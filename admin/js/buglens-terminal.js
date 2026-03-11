/**
 * BugLens Terminal — xterm.js frontend with AJAX command execution.
 *
 * Reads config from window.buglensTerminal (set via wp_localize_script):
 *   { ajaxUrl: string, nonce: string }
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * Resolve xterm.js UMD globals (v5 vs v6 export patterns)
     * ----------------------------------------------------------------*/
    function resolveTerminal() {
        if (typeof Terminal === 'function') return Terminal;
        if (typeof Terminal === 'object' && typeof Terminal.Terminal === 'function') return Terminal.Terminal;
        if (window.Terminal && typeof window.Terminal === 'function') return window.Terminal;
        if (window.Terminal && typeof window.Terminal.Terminal === 'function') return window.Terminal.Terminal;
        return null;
    }

    function resolveFitAddon() {
        if (typeof FitAddon !== 'undefined') {
            if (typeof FitAddon === 'function') return FitAddon;
            if (typeof FitAddon.FitAddon === 'function') return FitAddon.FitAddon;
        }
        if (window.FitAddon) {
            if (typeof window.FitAddon === 'function') return window.FitAddon;
            if (typeof window.FitAddon.FitAddon === 'function') return window.FitAddon.FitAddon;
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * State
     * ----------------------------------------------------------------*/
    var config    = window.buglensTerminal || {};
    var ajaxUrl   = config.ajaxUrl || '/wp-admin/admin-ajax.php';
    var nonce     = config.nonce || '';

    var term      = null;
    var fitAddon  = null;
    var sessionId = '';
    var cwd       = '';
    var user      = '';
    var hostname  = '';

    var commandBuffer = '';
    var history       = [];
    var historyIndex  = -1;
    var historyTmp    = '';      // stores current input when navigating history
    var maxHistory    = 100;
    var isExecuting   = false;

    // Cursor position within commandBuffer (for future left/right arrow support)
    var cursorPos = 0;

    /* ------------------------------------------------------------------
     * DOM references
     * ----------------------------------------------------------------*/
    var containerEl, warningEl, clearBtn, restartBtn, infoEl;
    var acceptCheckbox, continueBtn;

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Shorten a CWD path for display in the prompt.
     */
    function shortenCwd(path) {
        if (!path) return '~';
        // Replace ABSPATH with ./
        var abspath = config.abspath || '';
        if (abspath && path.indexOf(abspath) === 0) {
            var rel = './' + path.substring(abspath.length);
            // Clean trailing slash
            return rel.replace(/\/+$/, '') || './';
        }
        // Replace home dir with ~
        var home = config.home || '';
        if (home && path.indexOf(home) === 0) {
            return '~' + path.substring(home.length);
        }
        return path;
    }

    /**
     * Build the coloured prompt string.
     * Format: user@hostname:cwd$
     */
    function promptString() {
        var displayCwd = shortenCwd(cwd);
        return '\x1b[32m' + user + '@' + hostname + '\x1b[0m:\x1b[34m' + displayCwd + '\x1b[0m$ ';
    }

    /**
     * Write the prompt to the terminal.
     */
    function writePrompt() {
        term.write('\r\n' + promptString());
    }

    /**
     * POST to WP AJAX.
     */
    function ajaxPost(action, data, callback) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', nonce);
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }

        fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json.success) {
                    callback(null, json.data);
                } else {
                    callback(json.data || 'Unknown error');
                }
            })
            .catch(function (err) {
                callback(err.message || 'Network error');
            });
    }

    /**
     * Update the info bar.
     */
    function updateInfo() {
        if (!infoEl) return;
        var displayCwd = shortenCwd(cwd);
        infoEl.textContent = 'Session: ' + sessionId.substring(0, 8) + '... | CWD: ' + displayCwd;
    }

    /* ------------------------------------------------------------------
     * Terminal initialization
     * ----------------------------------------------------------------*/

    function initTerminal() {
        var TerminalCtor = resolveTerminal();
        var FitAddonCtor = resolveFitAddon();

        if (!TerminalCtor) {
            var errorP = document.createElement('p');
            errorP.style.cssText = 'color:#d63638;padding:20px;';
            errorP.textContent = 'Error: xterm.js library not loaded. Check vendor/xterm/ files.';
            containerEl.appendChild(errorP);
            return;
        }

        term = new TerminalCtor({
            cursorBlink: true,
            cursorStyle: 'block',
            fontSize: 14,
            fontFamily: "'JetBrains Mono', 'Fira Code', 'Cascadia Code', 'SF Mono', Menlo, Consolas, monospace",
            lineHeight: 1.2,
            scrollback: 5000,
            theme: {
                background: '#1a1a2e',
                foreground: '#e0e0e0',
                cursor: '#e0e0e0',
                selectionBackground: '#3a3a5e',
                black: '#1a1a2e',
                red: '#ff6b6b',
                green: '#51cf66',
                yellow: '#ffd43b',
                blue: '#74c0fc',
                magenta: '#da77f2',
                cyan: '#66d9e8',
                white: '#e0e0e0',
                brightBlack: '#555580',
                brightRed: '#ff8787',
                brightGreen: '#69db7c',
                brightYellow: '#ffe066',
                brightBlue: '#91d5ff',
                brightMagenta: '#e599f7',
                brightCyan: '#99e9f2',
                brightWhite: '#ffffff',
            },
            allowProposedApi: true,
        });

        if (FitAddonCtor) {
            fitAddon = new FitAddonCtor();
            term.loadAddon(fitAddon);
        }

        term.open(containerEl);

        if (fitAddon) {
            try { fitAddon.fit(); } catch (e) { /* ignore */ }
        }

        // Handle resize
        var resizeTimeout;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function () {
                if (fitAddon) {
                    try { fitAddon.fit(); } catch (e) { /* ignore */ }
                }
            }, 150);
        });

        // Input handling
        term.onData(onTermData);

        // Welcome message
        term.writeln('\x1b[1;33m  BugLens Terminal v2.0\x1b[0m');
        term.writeln('\x1b[90m  Type commands below. Ctrl+C to cancel, Ctrl+L to clear.\x1b[0m');
        term.writeln('');

        // Start session
        startSession();
    }

    /* ------------------------------------------------------------------
     * Session management
     * ----------------------------------------------------------------*/

    function startSession() {
        ajaxPost('buglens_terminal_start', {}, function (err, data) {
            if (err) {
                term.writeln('\x1b[31mError starting session: ' + err + '\x1b[0m');
                return;
            }
            sessionId = data.session_id;
            cwd       = data.cwd || '/';
            user      = data.user || 'www-data';
            hostname  = data.hostname || 'localhost';

            updateInfo();
            term.write(promptString());
            term.focus();
        });
    }

    function endSession(callback) {
        if (!sessionId) {
            if (callback) callback();
            return;
        }
        ajaxPost('buglens_terminal_end', { session_id: sessionId }, function () {
            sessionId = '';
            if (callback) callback();
        });
    }

    function restartSession() {
        endSession(function () {
            term.clear();
            term.writeln('\x1b[1;33m  BugLens Terminal v2.0\x1b[0m');
            term.writeln('\x1b[90m  New session started.\x1b[0m');
            term.writeln('');
            commandBuffer = '';
            cursorPos = 0;
            historyIndex = -1;
            startSession();
        });
    }

    /* ------------------------------------------------------------------
     * Input handling
     * ----------------------------------------------------------------*/

    function onTermData(data) {
        // Don't accept input while executing
        if (isExecuting) return;

        for (var i = 0; i < data.length; i++) {
            var ch = data[i];
            var code = ch.charCodeAt(0);

            // Check for escape sequences (arrow keys, etc.)
            if (ch === '\x1b' && i + 2 < data.length && data[i + 1] === '[') {
                var seq = data[i + 2];
                if (seq === 'A') {
                    // Arrow Up — previous history
                    navigateHistory(-1);
                    i += 2;
                    continue;
                } else if (seq === 'B') {
                    // Arrow Down — next history
                    navigateHistory(1);
                    i += 2;
                    continue;
                } else if (seq === 'C') {
                    // Arrow Right — ignore for now
                    i += 2;
                    continue;
                } else if (seq === 'D') {
                    // Arrow Left — ignore for now
                    i += 2;
                    continue;
                }
                // Unknown escape — skip
                i += 2;
                continue;
            }

            if (ch === '\r') {
                // Enter — execute command
                term.write('\r\n');
                executeCommand(commandBuffer);
                return;
            } else if (code === 127 || code === 8) {
                // Backspace
                if (commandBuffer.length > 0) {
                    commandBuffer = commandBuffer.slice(0, -1);
                    cursorPos = commandBuffer.length;
                    term.write('\b \b');
                }
            } else if (code === 3) {
                // Ctrl+C
                commandBuffer = '';
                cursorPos = 0;
                historyIndex = -1;
                term.write('^C');
                writePrompt();
            } else if (code === 12) {
                // Ctrl+L — clear screen
                term.clear();
                commandBuffer = '';
                cursorPos = 0;
                term.write(promptString());
            } else if (code === 4) {
                // Ctrl+D — if buffer empty, show exit message
                if (commandBuffer.length === 0) {
                    term.writeln('');
                    term.writeln('\x1b[90mUse the browser to navigate away.\x1b[0m');
                    writePrompt();
                }
            } else if (code >= 32) {
                // Printable character
                commandBuffer += ch;
                cursorPos = commandBuffer.length;
                term.write(ch);
            }
            // Ignore other control characters
        }
    }

    /**
     * Navigate command history.
     * direction: -1 = older, +1 = newer
     */
    function navigateHistory(direction) {
        if (history.length === 0) return;

        // Save current input when first entering history
        if (historyIndex === -1 && direction === -1) {
            historyTmp = commandBuffer;
        }

        var newIndex = historyIndex + direction;

        if (newIndex < -1) newIndex = -1;
        if (newIndex >= history.length) newIndex = history.length - 1;

        if (newIndex === historyIndex) return;

        historyIndex = newIndex;

        // Clear current line
        clearCurrentLine();

        if (historyIndex === -1) {
            // Back to current input
            commandBuffer = historyTmp;
        } else {
            commandBuffer = history[history.length - 1 - historyIndex];
        }

        cursorPos = commandBuffer.length;
        term.write(promptString() + commandBuffer);
    }

    /**
     * Clear the current input line on the terminal.
     */
    function clearCurrentLine() {
        // Move to beginning of line and clear it
        term.write('\r\x1b[2K');
    }

    /* ------------------------------------------------------------------
     * Command execution
     * ----------------------------------------------------------------*/

    function executeCommand(cmd) {
        var trimmed = cmd.trim();

        // Reset buffer
        commandBuffer = '';
        cursorPos = 0;
        historyIndex = -1;

        // Empty command — just show new prompt
        if (!trimmed) {
            term.write(promptString());
            return;
        }

        // Add to history (avoid duplicates at the end)
        if (history.length === 0 || history[history.length - 1] !== trimmed) {
            history.push(trimmed);
            if (history.length > maxHistory) {
                history.shift();
            }
        }

        // Handle `clear` locally
        if (trimmed === 'clear') {
            term.clear();
            term.write(promptString());
            return;
        }

        // Handle `exit`
        if (trimmed === 'exit') {
            term.writeln('\x1b[90mUse the browser to navigate away.\x1b[0m');
            writePrompt();
            return;
        }

        isExecuting = true;

        ajaxPost('buglens_terminal_execute', {
            session_id: sessionId,
            command: trimmed,
        }, function (err, data) {
            isExecuting = false;

            if (err) {
                term.writeln('\x1b[31mError: ' + err + '\x1b[0m');
                writePrompt();
                return;
            }

            // Write command output
            if (data.output) {
                // Normalize line endings and write
                var output = data.output.replace(/\r\n/g, '\n');
                var lines = output.split('\n');
                for (var i = 0; i < lines.length; i++) {
                    if (i < lines.length - 1) {
                        term.writeln(lines[i]);
                    } else if (lines[i] !== '') {
                        // Last line without newline
                        term.write(lines[i]);
                        term.write('\r\n');
                    }
                }
            }

            // Update CWD from response
            if (data.cwd) {
                cwd = data.cwd;
                updateInfo();
            }

            // Show prompt for next command
            term.write(promptString());
            term.focus();
        });
    }

    /* ------------------------------------------------------------------
     * Warning dialog
     * ----------------------------------------------------------------*/

    function checkWarning() {
        var accepted = false;
        try {
            accepted = localStorage.getItem('buglens_terminal_accepted') === '1';
        } catch (e) { /* localStorage not available */ }

        if (accepted) {
            initTerminal();
            return;
        }

        // Show warning
        warningEl.style.display = '';
        containerEl.style.display = 'none';

        acceptCheckbox.addEventListener('change', function () {
            continueBtn.disabled = !acceptCheckbox.checked;
        });

        continueBtn.addEventListener('click', function () {
            try {
                localStorage.setItem('buglens_terminal_accepted', '1');
            } catch (e) { /* ignore */ }
            warningEl.style.display = 'none';
            containerEl.style.display = '';
            initTerminal();
        });
    }

    /* ------------------------------------------------------------------
     * Toolbar buttons
     * ----------------------------------------------------------------*/

    function bindToolbar() {
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!term) return;
                term.clear();
                commandBuffer = '';
                cursorPos = 0;
                term.write(promptString());
                term.focus();
            });
        }

        if (restartBtn) {
            restartBtn.addEventListener('click', function () {
                if (!term) return;
                restartSession();
            });
        }
    }

    /* ------------------------------------------------------------------
     * Initialization on DOMContentLoaded
     * ----------------------------------------------------------------*/

    document.addEventListener('DOMContentLoaded', function () {
        containerEl     = document.getElementById('buglens-terminal');
        warningEl       = document.getElementById('buglens-terminal-warning');
        clearBtn        = document.getElementById('buglens-term-clear');
        restartBtn      = document.getElementById('buglens-term-restart');
        infoEl          = document.getElementById('buglens-term-info');
        acceptCheckbox  = document.getElementById('buglens-term-accept');
        continueBtn     = document.getElementById('buglens-term-continue');

        if (!containerEl) return;

        containerEl.setAttribute('aria-label', 'BugLens Terminal');

        bindToolbar();
        checkWarning();
    });

})();
