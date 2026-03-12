/**
 * BugLens Bridge — config snippet generator and copy functionality.
 *
 * Vanilla JS, no jQuery dependency.
 *
 * @package BugLens
 */

document.addEventListener( 'DOMContentLoaded', function () {
    'use strict';

    var container = document.getElementById( 'buglens-bridge-snippets' );
    if ( ! container ) {
        return;
    }

    var siteUrl = container.getAttribute( 'data-site-url' ) || '';
    var apiKey  = container.getAttribute( 'data-api-key' ) || '';

    /**
     * Build the standard mcpServers JSON config block.
     */
    function mcpServersJson() {
        return JSON.stringify( {
            mcpServers: {
                buglens: {
                    command: 'npx',
                    args: [ '-y', 'buglens-mcp' ],
                    env: {
                        BUGLENS_URL: siteUrl,
                        BUGLENS_KEY: apiKey
                    }
                }
            }
        }, null, 2 );
    }

    /**
     * Build VS Code servers JSON (uses "servers" key + type: "stdio").
     */
    function vscodeJson() {
        return JSON.stringify( {
            servers: {
                buglens: {
                    type: 'stdio',
                    command: 'npx',
                    args: [ '-y', 'buglens-mcp' ],
                    env: {
                        BUGLENS_URL: siteUrl,
                        BUGLENS_KEY: apiKey
                    }
                }
            }
        }, null, 2 );
    }

    /**
     * Build OpenAI Codex TOML config.
     */
    function codexToml() {
        return '[mcp_servers.buglens]\n' +
               'command = ["npx", "-y", "buglens-mcp"]\n' +
               '\n' +
               '[mcp_servers.buglens.env]\n' +
               'BUGLENS_URL = "' + siteUrl + '"\n' +
               'BUGLENS_KEY = "' + apiKey + '"';
    }

    /**
     * Build Claude Code CLI command.
     */
    function claudeCodeCmd() {
        return 'BUGLENS_URL=' + siteUrl + ' BUGLENS_KEY=' + apiKey + ' claude mcp add buglens -- npx -y buglens-mcp';
    }

    // Config generators keyed by data-tool attribute.
    var generators = {
        'claude-code':    claudeCodeCmd,
        'claude-desktop': mcpServersJson,
        'cursor':         mcpServersJson,
        'windsurf':       mcpServersJson,
        'openai-codex':   codexToml,
        'gemini-cli':     mcpServersJson,
        'vscode':         vscodeJson,
        'cline':          mcpServersJson
    };

    // Fill each snippet's <pre> element.
    var snippets = container.querySelectorAll( '.buglens-snippet' );
    snippets.forEach( function ( snippet ) {
        var tool = snippet.getAttribute( 'data-tool' );
        var pre  = snippet.querySelector( '.buglens-snippet__code' );
        if ( tool && pre && generators[ tool ] ) {
            pre.textContent = generators[ tool ]();
        }
    } );

    // Copy button click handlers.
    var copyButtons = container.querySelectorAll( '.buglens-snippet__copy' );
    copyButtons.forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var snippet = btn.closest( '.buglens-snippet' );
            if ( ! snippet ) {
                return;
            }

            var pre = snippet.querySelector( '.buglens-snippet__code' );
            if ( ! pre ) {
                return;
            }

            var text = pre.textContent || '';

            navigator.clipboard.writeText( text ).then( function () {
                // Visual feedback: change icon + text.
                var icon = btn.querySelector( '.dashicons' );
                var originalIconClass = 'dashicons-clipboard';
                var originalText = btn.textContent.trim();

                if ( icon ) {
                    icon.classList.remove( 'dashicons-clipboard' );
                    icon.classList.add( 'dashicons-yes' );
                }

                // Replace button text (keep icon).
                var textNode = null;
                for ( var i = 0; i < btn.childNodes.length; i++ ) {
                    if ( btn.childNodes[ i ].nodeType === Node.TEXT_NODE && btn.childNodes[ i ].textContent.trim() !== '' ) {
                        textNode = btn.childNodes[ i ];
                        break;
                    }
                }
                if ( textNode ) {
                    textNode.textContent = ' Copied!';
                }

                // Revert after 1.5s.
                setTimeout( function () {
                    if ( icon ) {
                        icon.classList.remove( 'dashicons-yes' );
                        icon.classList.add( originalIconClass );
                    }
                    if ( textNode ) {
                        textNode.textContent = ' Copy';
                    }
                }, 1500 );
            } );
        } );
    } );
} );
