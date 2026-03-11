/**
 * BugLens - Visual Bug Reporter Widget
 * Version: 1.0.0
 *
 * Self-contained vanilla JS (no dependencies, no jQuery, no build step).
 * Works on any WordPress page. Reads config from window.buglensConfig
 * injected by WordPress via wp_localize_script.
 *
 * NOTE: This file uses innerHTML in controlled ways with hardcoded SVG
 * strings and content escaped via escapeHtml/escapeAttr helper functions.
 * No user-supplied raw HTML is ever inserted without escaping.
 */
(function () {
    'use strict';

    /* ======================================================================
       0. Config & Guards
       ====================================================================== */

    var defaultCfg = {
        restUrl: '',
        nonce: '',
        position: 'bottom-right',
        color: '#F2C700',
        maxHtml: 5000,
        captureConsole: true,
    };
    var config = Object.assign({}, defaultCfg, window.buglensConfig || {});

    // Bail if already initialised (double-load protection)
    if (window.__buglensInitialised) return;
    window.__buglensInitialised = true;

    // Bail if essential config is missing
    if (!config.restUrl || !config.nonce) {
        console.warn('BugLens: Missing restUrl or nonce in buglensConfig. Widget disabled.');
        return;
    }

    /* ======================================================================
       1. Console Error Capture
       ====================================================================== */

    var MAX_ERRORS = 50;
    var capturedErrors = [];

    function pushError(entry) {
        if (capturedErrors.length >= MAX_ERRORS) return;
        entry.ts = new Date().toISOString();
        capturedErrors.push(entry);
    }

    if (config.captureConsole) {
        // Override console.error
        var origConsoleError = console.error;
        console.error = function () {
            var args = Array.from(arguments);
            pushError({
                type: 'console.error',
                message: args
                    .map(function (a) {
                        try {
                            return typeof a === 'object' ? JSON.stringify(a) : String(a);
                        } catch (e) {
                            return String(a);
                        }
                    })
                    .join(' '),
            });
            origConsoleError.apply(console, args);
        };

        // window.onerror
        var origOnError = window.onerror;
        window.onerror = function (message, source, lineno, colno, error) {
            pushError({
                type: 'window.onerror',
                message: String(message),
                source: source || '',
                line: lineno,
                col: colno,
                stack: error && error.stack ? error.stack.slice(0, 500) : '',
            });
            if (typeof origOnError === 'function') {
                return origOnError.apply(window, arguments);
            }
            return false;
        };

        // unhandledrejection
        window.addEventListener('unhandledrejection', function (event) {
            var msg = 'Unhandled Promise rejection';
            try {
                if (event.reason) {
                    msg =
                        event.reason.message ||
                        event.reason.toString() ||
                        msg;
                }
            } catch (e) {
                // ignore
            }
            pushError({
                type: 'unhandledrejection',
                message: String(msg).slice(0, 500),
            });
        });
    }

    /* ======================================================================
       2. Utility: HTML Escaping
       ====================================================================== */

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.textContent !== undefined ? div.innerHTML : '';
    }

    function escapeAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /* ======================================================================
       3. SVG Icon Builders (return DOM nodes, not raw strings)
       ====================================================================== */

    function createSvg(className, paths) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        if (className) svg.setAttribute('class', className);
        paths.forEach(function (spec) {
            var el;
            if (spec.tag === 'circle') {
                el = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                el.setAttribute('cx', spec.cx);
                el.setAttribute('cy', spec.cy);
                el.setAttribute('r', spec.r);
            } else if (spec.tag === 'line') {
                el = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                el.setAttribute('x1', spec.x1);
                el.setAttribute('y1', spec.y1);
                el.setAttribute('x2', spec.x2);
                el.setAttribute('y2', spec.y2);
            } else if (spec.tag === 'polyline') {
                el = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                el.setAttribute('points', spec.points);
            } else if (spec.tag === 'path') {
                el = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                el.setAttribute('d', spec.d);
            }
            if (el) svg.appendChild(el);
        });
        return svg;
    }

    function iconLens() {
        return createSvg('buglens-fab__icon buglens-fab__icon--lens', [
            { tag: 'circle', cx: '11', cy: '11', r: '7' },
            { tag: 'line', x1: '16.5', y1: '16.5', x2: '21', y2: '21' },
        ]);
    }

    function iconClose(className) {
        return createSvg(className || 'buglens-fab__icon buglens-fab__icon--close', [
            { tag: 'line', x1: '18', y1: '6', x2: '6', y2: '18' },
            { tag: 'line', x1: '6', y1: '6', x2: '18', y2: '18' },
        ]);
    }

    function iconTarget() {
        return createSvg('buglens-selector-preview__icon', [
            { tag: 'circle', cx: '12', cy: '12', r: '10' },
            { tag: 'circle', cx: '12', cy: '12', r: '6' },
            { tag: 'circle', cx: '12', cy: '12', r: '2' },
        ]);
    }

    function iconCheck() {
        return createSvg('buglens-toast__icon', [
            { tag: 'polyline', points: '20 6 9 17 4 12' },
        ]);
    }

    function iconAlert() {
        return createSvg('buglens-toast__icon', [
            { tag: 'circle', cx: '12', cy: '12', r: '10' },
            { tag: 'line', x1: '12', y1: '8', x2: '12', y2: '12' },
            { tag: 'line', x1: '12', y1: '16', x2: '12.01', y2: '16' },
        ]);
    }

    function iconCamera() {
        return createSvg(null, [
            { tag: 'path', d: 'M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z' },
            { tag: 'circle', cx: '12', cy: '13', r: '4' },
        ]);
    }

    function iconCloseSmall() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '14');
        svg.setAttribute('height', '14');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        var l1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        l1.setAttribute('x1', '18'); l1.setAttribute('y1', '6');
        l1.setAttribute('x2', '6'); l1.setAttribute('y2', '18');
        var l2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        l2.setAttribute('x1', '6'); l2.setAttribute('y1', '6');
        l2.setAttribute('x2', '18'); l2.setAttribute('y2', '18');
        svg.appendChild(l1);
        svg.appendChild(l2);
        return svg;
    }

    /* ======================================================================
       4. DOM Helpers
       ====================================================================== */

    function mkEl(tag, attrs) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (k === 'className') node.className = v;
                else if (k === 'textContent') node.textContent = v;
                else if (k.startsWith('on') && typeof v === 'function')
                    node.addEventListener(k.slice(2).toLowerCase(), v);
                else node.setAttribute(k, v);
            });
        }
        // Remaining args are children
        for (var i = 2; i < arguments.length; i++) {
            var child = arguments[i];
            if (typeof child === 'string') node.appendChild(document.createTextNode(child));
            else if (child) node.appendChild(child);
        }
        return node;
    }

    function isBuglensEl(node) {
        if (!node || !node.className) return false;
        var cls = typeof node.className === 'string' ? node.className : '';
        return cls.indexOf('buglens-') !== -1;
    }

    /* ======================================================================
       5. Unique Selector Generator
       ====================================================================== */

    function generateUniqueSelector(element) {
        if (!element || element === document.body || element === document.documentElement) {
            return 'body';
        }

        // If element has an ID, use it directly
        if (element.id) {
            return '#' + CSS.escape(element.id);
        }

        var path = [];
        var current = element;

        while (current && current !== document.body && current !== document.documentElement) {
            var selector = current.tagName.toLowerCase();

            if (current.id) {
                path.unshift('#' + CSS.escape(current.id));
                break;
            }

            // Add up to 3 non-buglens classes for identification
            var classes = Array.from(current.classList)
                .filter(function (c) { return c.indexOf('buglens-') !== 0; })
                .slice(0, 3);
            if (classes.length) {
                selector += '.' + classes.map(function (c) { return CSS.escape(c); }).join('.');
            }

            // Add nth-child for uniqueness if there are same-tag siblings
            var parent = current.parentElement;
            if (parent) {
                var siblings = Array.from(parent.children).filter(
                    function (c) { return c.tagName === current.tagName; }
                );
                if (siblings.length > 1) {
                    var index = Array.from(parent.children).indexOf(current) + 1;
                    selector += ':nth-child(' + index + ')';
                }
            }

            path.unshift(selector);
            current = current.parentElement;
        }

        return path.join(' > ') || 'body';
    }

    /* ======================================================================
       6. Parent Chain Builder
       ====================================================================== */

    function buildParentChain(element) {
        var chain = [];
        var current = element;

        while (current && current !== document.documentElement) {
            var part = current.tagName.toLowerCase();
            var classes = Array.from(current.classList || [])
                .filter(function (c) { return c.indexOf('buglens-') !== 0; })
                .slice(0, 2);
            if (classes.length) {
                part += '.' + classes.join('.');
            }
            chain.unshift(part);
            current = current.parentElement;
        }

        return chain.join(' > ');
    }

    /* ======================================================================
       7. Context Detector
       ====================================================================== */

    function detectContext(element) {
        var current = element;
        var result = { context: 'page', overlaySelector: null };

        while (current && current !== document.body && current !== document.documentElement) {
            var style = window.getComputedStyle(current);
            var position = style.getPropertyValue('position');
            var zIndex = parseInt(style.getPropertyValue('z-index'), 10);

            if (position === 'fixed' || (position === 'absolute' && zIndex > 0)) {
                // Determine type based on heuristics
                var role = (current.getAttribute('role') || '').toLowerCase();
                var cls = (typeof current.className === 'string' ? current.className : '').toLowerCase();
                var id = (current.id || '').toLowerCase();
                var combined = role + ' ' + cls + ' ' + id;

                if (role === 'dialog' || combined.indexOf('modal') !== -1) {
                    result.context = 'modal';
                } else if (combined.indexOf('popup') !== -1 || combined.indexOf('popover') !== -1) {
                    result.context = 'popup';
                } else if (combined.indexOf('overlay') !== -1) {
                    result.context = 'overlay';
                } else if (combined.indexOf('dropdown') !== -1 || combined.indexOf('menu') !== -1) {
                    result.context = 'dropdown';
                } else if (combined.indexOf('tooltip') !== -1) {
                    result.context = 'tooltip';
                } else if (position === 'fixed') {
                    result.context = 'fixed';
                } else {
                    result.context = 'overlay';
                }

                result.overlaySelector = generateUniqueSelector(current);
                break;
            }

            current = current.parentElement;
        }

        return result;
    }

    /* ======================================================================
       8. Computed Styles Capture
       ====================================================================== */

    var STYLE_PROPS = [
        'font-size', 'color', 'background-color', 'background',
        'display', 'position', 'z-index', 'width', 'height',
        'margin', 'padding', 'border', 'opacity', 'overflow',
        'text-align', 'line-height', 'font-weight', 'font-family',
    ];

    function captureComputedStyles(element) {
        var cs = window.getComputedStyle(element);
        var result = {};
        STYLE_PROPS.forEach(function (prop) {
            result[prop] = cs.getPropertyValue(prop);
        });
        return result;
    }

    /* ======================================================================
       9. Screenshot Capture
       ====================================================================== */

    async function captureScreenshot() {
        // Check if API is available
        if (
            !navigator.mediaDevices ||
            typeof navigator.mediaDevices.getDisplayMedia !== 'function'
        ) {
            console.warn('BugLens: getDisplayMedia not available.');
            return null;
        }

        var stream = null;
        try {
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: { displaySurface: 'browser' },
                preferCurrentTab: true,
            });

            var track = stream.getVideoTracks()[0];
            var dataUrl = null;

            // Try ImageCapture API first, fallback to video element
            if (typeof ImageCapture !== 'undefined') {
                try {
                    var imageCapture = new ImageCapture(track);
                    var bitmap = await imageCapture.grabFrame();
                    var canvas = document.createElement('canvas');
                    canvas.width = bitmap.width;
                    canvas.height = bitmap.height;
                    canvas.getContext('2d').drawImage(bitmap, 0, 0);
                    dataUrl = canvas.toDataURL('image/png');
                    bitmap.close();
                } catch (e) {
                    // ImageCapture failed, fall through to video fallback
                    dataUrl = null;
                }
            }

            // Fallback: use a <video> element to capture frame
            if (!dataUrl) {
                dataUrl = await new Promise(function (resolve) {
                    var video = document.createElement('video');
                    video.srcObject = stream;
                    video.muted = true;
                    video.playsInline = true;

                    video.onloadedmetadata = function () {
                        video.play().then(function () {
                            // Small delay to ensure frame is rendered
                            requestAnimationFrame(function () {
                                var cvs = document.createElement('canvas');
                                cvs.width = video.videoWidth;
                                cvs.height = video.videoHeight;
                                cvs.getContext('2d').drawImage(video, 0, 0);
                                resolve(cvs.toDataURL('image/png'));
                                video.srcObject = null;
                            });
                        }).catch(function () { resolve(null); });
                    };

                    video.onerror = function () { resolve(null); };
                });
            }

            track.stop();
            return dataUrl;
        } catch (err) {
            // User denied or API error
            console.warn('BugLens: Screenshot not captured:', err.message);
            if (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
            }
            return null;
        }
    }

    /* ======================================================================
       10. Browser / Viewport Info
       ====================================================================== */

    function getBrowserInfo() {
        var ua = navigator.userAgent;
        var browser = 'Unknown';
        if (ua.indexOf('Firefox/') !== -1) browser = 'Firefox';
        else if (ua.indexOf('Edg/') !== -1) browser = 'Edge';
        else if (ua.indexOf('Chrome/') !== -1) browser = 'Chrome';
        else if (ua.indexOf('Safari/') !== -1) browser = 'Safari';

        var platform = navigator.platform || 'Unknown';
        try {
            if (navigator.userAgentData && navigator.userAgentData.platform) {
                platform = navigator.userAgentData.platform;
            }
        } catch (e) {
            // ignore
        }

        return browser + ' / ' + platform;
    }

    function getViewportInfo() {
        return (
            window.innerWidth +
            'x' +
            window.innerHeight +
            ' @' +
            window.devicePixelRatio +
            'x'
        );
    }

    /* ======================================================================
       11. Submit Handler
       ====================================================================== */

    async function submitReport(formData) {
        var response = await fetch(config.restUrl + 'reports', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            body: JSON.stringify({
                title: formData.title,
                description: formData.description,
                page_url: window.location.href,
                selector: formData.selector,
                parent_chain: formData.parentChain,
                outer_html: formData.outerHTML,
                inner_text: formData.innerText,
                computed_styles: JSON.stringify(formData.computedStyles),
                bounding_box: JSON.stringify(formData.boundingBox),
                context: formData.context,
                overlay_selector: formData.overlaySelector,
                browser: formData.browser,
                viewport: formData.viewport,
                console_errors: JSON.stringify(formData.consoleErrors),
                screenshot: formData.screenshot,
            }),
        });

        if (!response.ok) {
            var errorMsg = 'Failed to submit report';
            try {
                var body = await response.json();
                errorMsg = body.message || errorMsg;
            } catch (e) {
                // ignore parse error
            }
            throw new Error(errorMsg);
        }

        return response.json();
    }

    /* ======================================================================
       12. Toast Notification
       ====================================================================== */

    var activeToast = null;
    var toastTimer = null;

    function showToast(message, type) {
        // type: 'success' | 'error'
        // Remove existing toast
        dismissToast();

        var iconNode = type === 'success' ? iconCheck() : iconAlert();

        var closeBtn = mkEl('button', {
            className: 'buglens-toast__close',
            type: 'button',
            'aria-label': 'Close notification',
        });
        closeBtn.appendChild(iconCloseSmall());
        closeBtn.addEventListener('click', dismissToast);

        var progressBar = mkEl('div', { className: 'buglens-toast__progress-bar' });
        var progress = mkEl('div', { className: 'buglens-toast__progress' }, progressBar);

        var toast = mkEl('div', { className: 'buglens-toast buglens-toast--' + type },
            iconNode,
            mkEl('span', { className: 'buglens-toast__message', textContent: message }),
            closeBtn,
            progress
        );

        document.body.appendChild(toast);
        activeToast = toast;

        // Trigger visible transition (double rAF for layout flush)
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                toast.classList.add('buglens-toast--visible');
            });
        });

        // Auto-dismiss after 3 seconds
        toastTimer = setTimeout(dismissToast, 3000);
    }

    function dismissToast() {
        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
        if (activeToast) {
            var ref = activeToast;
            ref.classList.remove('buglens-toast--visible');
            activeToast = null;
            setTimeout(function () { ref.remove(); }, 400);
        }
    }

    /* ======================================================================
       13. State
       ====================================================================== */

    var state = {
        selectorMode: false,
        selectedElement: null,
        metadata: null,
        screenshotDataUrl: null,
        formVisible: false,
    };

    // DOM references (created lazily)
    var fabBtn = null;
    var overlayEl = null;
    var highlightEl = null;
    var tooltipEl = null;
    var formBackdrop = null;
    var formPanel = null;

    /* ======================================================================
       14. FAB Button
       ====================================================================== */

    function createFab() {
        fabBtn = mkEl('button', {
            className: 'buglens-fab buglens-fab--' + config.position,
            type: 'button',
            'aria-label': 'Report a bug',
        });
        fabBtn.appendChild(iconLens());
        fabBtn.appendChild(iconClose());
        fabBtn.addEventListener('click', onFabClick);

        document.body.appendChild(fabBtn);

        // Pulse animation on first visit
        try {
            if (!localStorage.getItem('buglens_visited')) {
                fabBtn.classList.add('buglens-fab--pulse');
                localStorage.setItem('buglens_visited', '1');
                // Remove pulse class after animation ends
                fabBtn.addEventListener(
                    'animationend',
                    function () { fabBtn.classList.remove('buglens-fab--pulse'); },
                    { once: true }
                );
            }
        } catch (e) {
            // localStorage may be unavailable (private browsing, etc.)
        }
    }

    function onFabClick() {
        if (state.formVisible) {
            closeForm();
            return;
        }

        if (state.selectorMode) {
            exitSelectorMode();
        } else {
            enterSelectorMode();
        }
    }

    /* ======================================================================
       15. Selector Mode
       ====================================================================== */

    function enterSelectorMode() {
        state.selectorMode = true;
        state.selectedElement = null;
        state.metadata = null;
        state.screenshotDataUrl = null;

        // Create overlay (captures pointer events, transparent)
        overlayEl = mkEl('div', { className: 'buglens-selector-overlay' });

        // Create highlight box
        highlightEl = mkEl('div', { className: 'buglens-highlight buglens-hidden' });

        // Create tooltip
        tooltipEl = mkEl('div', { className: 'buglens-tooltip' });

        document.body.appendChild(overlayEl);
        document.body.appendChild(highlightEl);
        document.body.appendChild(tooltipEl);

        // Activate FAB (show X icon)
        fabBtn.classList.add('buglens-fab--active');

        // Attach overlay event listeners
        overlayEl.addEventListener('mousemove', onSelectorMouseMove);
        overlayEl.addEventListener('click', onSelectorClick);
        overlayEl.addEventListener('touchstart', onSelectorTouch, { passive: false });

        // ESC to cancel
        document.addEventListener('keydown', onSelectorKeyDown);
    }

    function exitSelectorMode() {
        state.selectorMode = false;

        // Remove overlay
        if (overlayEl) {
            overlayEl.removeEventListener('mousemove', onSelectorMouseMove);
            overlayEl.removeEventListener('click', onSelectorClick);
            overlayEl.removeEventListener('touchstart', onSelectorTouch);
            overlayEl.remove();
            overlayEl = null;
        }

        // Remove highlight
        if (highlightEl) {
            highlightEl.remove();
            highlightEl = null;
        }

        // Remove tooltip
        if (tooltipEl) {
            tooltipEl.remove();
            tooltipEl = null;
        }

        // Reset FAB
        fabBtn.classList.remove('buglens-fab--active');

        // Remove keydown listener
        document.removeEventListener('keydown', onSelectorKeyDown);
    }

    function onSelectorKeyDown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            exitSelectorMode();
        }
    }

    function resolveElementAtPoint(x, y) {
        // Get all elements at this point, skipping BugLens elements
        var elements = document.elementsFromPoint(x, y);
        for (var i = 0; i < elements.length; i++) {
            var elem = elements[i];
            if (isBuglensEl(elem)) continue;
            // If element has shadowRoot, try to resolve deeper
            if (elem.shadowRoot) {
                try {
                    var shadowEl = elem.shadowRoot.elementFromPoint(x, y);
                    if (shadowEl && !isBuglensEl(shadowEl)) {
                        return shadowEl;
                    }
                } catch (e) {
                    // closed shadow DOM - cannot access
                }
            }
            return elem;
        }
        return null;
    }

    function onSelectorMouseMove(e) {
        var target = resolveElementAtPoint(e.clientX, e.clientY);
        if (!target || target === document.body || target === document.documentElement) {
            highlightEl.classList.add('buglens-hidden');
            tooltipEl.classList.remove('buglens-tooltip--visible');
            return;
        }

        // Position highlight over target element
        var rect = target.getBoundingClientRect();
        highlightEl.style.top = rect.top + 'px';
        highlightEl.style.left = rect.left + 'px';
        highlightEl.style.width = rect.width + 'px';
        highlightEl.style.height = rect.height + 'px';
        highlightEl.classList.remove('buglens-hidden');

        // Update tooltip content
        var tagName = target.tagName.toLowerCase();
        var classes = Array.from(target.classList || [])
            .filter(function (c) { return c.indexOf('buglens-') !== 0; })
            .slice(0, 3);
        var classStr = classes.length ? '.' + classes.join('.') : '';

        // Build tooltip content with DOM nodes (no innerHTML)
        tooltipEl.textContent = '';
        var tagSpan = mkEl('span', { className: 'buglens-tooltip__tag', textContent: tagName });
        tooltipEl.appendChild(tagSpan);
        if (classStr) {
            var classSpan = mkEl('span', { className: 'buglens-tooltip__class', textContent: classStr });
            tooltipEl.appendChild(classSpan);
        }
        tooltipEl.classList.add('buglens-tooltip--visible');

        // Position tooltip near cursor, avoiding viewport edges
        var tooltipW = tooltipEl.offsetWidth;
        var tooltipH = tooltipEl.offsetHeight;
        var tooltipX = e.clientX + 14;
        var tooltipY = e.clientY + 14;

        if (tooltipX + tooltipW > window.innerWidth - 8) {
            tooltipX = e.clientX - tooltipW - 8;
        }
        if (tooltipY + tooltipH > window.innerHeight - 8) {
            tooltipY = e.clientY - tooltipH - 8;
        }

        tooltipEl.style.left = tooltipX + 'px';
        tooltipEl.style.top = tooltipY + 'px';
    }

    function onSelectorTouch(e) {
        // On touch devices, prevent scrolling and use first touch point
        e.preventDefault();
        var touch = e.touches[0];
        if (!touch) return;

        var target = resolveElementAtPoint(touch.clientX, touch.clientY);
        if (target && target !== document.body && target !== document.documentElement) {
            selectElement(target);
        }
    }

    function onSelectorClick(e) {
        e.preventDefault();
        e.stopPropagation();

        var target = resolveElementAtPoint(e.clientX, e.clientY);
        if (!target || target === document.body || target === document.documentElement) {
            return;
        }

        selectElement(target);
    }

    /* ======================================================================
       16. Element Selection & Metadata Capture
       ====================================================================== */

    function selectElement(element) {
        state.selectedElement = element;

        // Capture all metadata immediately
        var rect = element.getBoundingClientRect();
        var contextInfo = detectContext(element);

        var outerHTML = '';
        try {
            outerHTML = element.outerHTML || '';
            if (outerHTML.length > config.maxHtml) {
                outerHTML = outerHTML.slice(0, config.maxHtml) + '<!-- truncated -->';
            }
        } catch (e) {
            outerHTML = '<' + element.tagName.toLowerCase() + ' ... />';
        }

        var innerText = '';
        try {
            innerText = (element.innerText || element.textContent || '').trim().slice(0, 1000);
        } catch (e) {
            innerText = '';
        }

        state.metadata = {
            selector: generateUniqueSelector(element),
            parentChain: buildParentChain(element),
            outerHTML: outerHTML,
            innerText: innerText,
            computedStyles: captureComputedStyles(element),
            boundingBox: {
                top: Math.round(rect.top),
                left: Math.round(rect.left),
                width: Math.round(rect.width),
                height: Math.round(rect.height),
                scrollX: Math.round(window.scrollX),
                scrollY: Math.round(window.scrollY),
            },
            context: contextInfo.context,
            overlaySelector: contextInfo.overlaySelector,
            browser: getBrowserInfo(),
            viewport: getViewportInfo(),
            consoleErrors: capturedErrors.slice(),
        };

        // Exit selector mode (removes overlay, highlight, tooltip)
        exitSelectorMode();

        // Start screenshot capture, then open form
        startScreenshotThenShowForm();
    }

    async function startScreenshotThenShowForm() {
        // Capture screenshot (user will see browser prompt)
        var screenshot = await captureScreenshot();
        state.screenshotDataUrl = screenshot;

        // Now show the form
        showForm();
    }

    /* ======================================================================
       17. Report Form
       ====================================================================== */

    function buildElementHint() {
        if (!state.selectedElement) return 'Bug Report';
        var tag = state.selectedElement.tagName.toLowerCase();
        var cls = Array.from(state.selectedElement.classList || [])
            .filter(function (c) { return c.indexOf('buglens-') !== 0; })
            .slice(0, 1);
        return tag + (cls.length ? '.' + cls[0] : '');
    }

    function showForm() {
        state.formVisible = true;

        // Activate FAB
        fabBtn.classList.add('buglens-fab--active');

        // --- Backdrop ---
        formBackdrop = mkEl('div', { className: 'buglens-form-backdrop' });
        formBackdrop.addEventListener('click', closeForm);

        // --- Form Panel ---
        formPanel = mkEl('div', { className: 'buglens-form' });

        // Handle (drag indicator)
        var handle = mkEl('div', { className: 'buglens-form__handle' });

        // Header
        var closeFormBtn = mkEl('button', {
            className: 'buglens-form__close',
            type: 'button',
            'aria-label': 'Close form',
        });
        closeFormBtn.appendChild(iconCloseSmall());
        closeFormBtn.addEventListener('click', closeForm);

        var header = mkEl('div', { className: 'buglens-form__header' },
            mkEl('h3', { className: 'buglens-form__title', textContent: 'Report Bug' }),
            closeFormBtn
        );

        // Body
        var body = mkEl('div', { className: 'buglens-form__body' });

        // Title field
        var titleField = mkEl('div', { className: 'buglens-field' },
            mkEl('label', { className: 'buglens-label', for: 'buglens-title', textContent: 'Title' }),
            mkEl('input', {
                className: 'buglens-input',
                id: 'buglens-title',
                type: 'text',
                placeholder: 'Describe the issue...',
                value: buildElementHint(),
            })
        );
        body.appendChild(titleField);

        // Description field
        var descField = mkEl('div', { className: 'buglens-field' },
            mkEl('label', { className: 'buglens-label', for: 'buglens-desc', textContent: 'Description' }),
            mkEl('textarea', {
                className: 'buglens-textarea',
                id: 'buglens-desc',
                placeholder: 'Steps to reproduce, expected behavior...',
            })
        );
        body.appendChild(descField);

        // Selector preview field
        var selectorText = state.metadata ? state.metadata.selector : '';
        var reselectBtn = mkEl('button', {
            className: 'buglens-selector-preview__reselect',
            type: 'button',
            textContent: 'Reselect',
        });
        reselectBtn.addEventListener('click', function () {
            closeForm();
            setTimeout(function () { enterSelectorMode(); }, 100);
        });

        var selectorPreview = mkEl('div', { className: 'buglens-selector-preview' },
            iconTarget(),
            mkEl('span', { className: 'buglens-selector-preview__text', textContent: selectorText }),
            reselectBtn
        );

        var selectorField = mkEl('div', { className: 'buglens-field' },
            mkEl('label', { className: 'buglens-label', textContent: 'Selected Element' }),
            selectorPreview
        );
        body.appendChild(selectorField);

        // Screenshot field
        var screenshotContainer = buildScreenshotPreview();
        var screenshotField = mkEl('div', { className: 'buglens-field' },
            mkEl('label', { className: 'buglens-label', textContent: 'Screenshot' }),
            screenshotContainer
        );
        body.appendChild(screenshotField);

        // Action buttons
        var submitBtn = mkEl('button', {
            className: 'buglens-btn buglens-btn--primary',
            type: 'button',
            textContent: 'Submit Report',
        });
        submitBtn.addEventListener('click', function () { handleSubmit(submitBtn); });

        var cancelBtn = mkEl('button', {
            className: 'buglens-btn buglens-btn--secondary',
            type: 'button',
            textContent: 'Cancel',
        });
        cancelBtn.addEventListener('click', closeForm);

        var actions = mkEl('div', { className: 'buglens-form__actions' },
            submitBtn,
            cancelBtn
        );
        body.appendChild(actions);

        // Assemble panel
        formPanel.appendChild(handle);
        formPanel.appendChild(header);
        formPanel.appendChild(body);

        // Add to DOM
        document.body.appendChild(formBackdrop);
        document.body.appendChild(formPanel);

        // Trigger visible transition (double rAF for layout flush)
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                formBackdrop.classList.add('buglens-form-backdrop--visible');
                formPanel.classList.add('buglens-form--visible');
            });
        });

        // Focus title input
        var titleInput = formPanel.querySelector('#buglens-title');
        if (titleInput) {
            setTimeout(function () { titleInput.focus(); }, 350);
        }

        // ESC to close form
        document.addEventListener('keydown', onFormKeyDown);
    }

    function buildScreenshotPreview() {
        if (state.screenshotDataUrl) {
            var container = mkEl('div', { className: 'buglens-screenshot' });
            var img = mkEl('img', {
                className: 'buglens-screenshot__img',
                src: state.screenshotDataUrl,
                alt: 'Screenshot',
            });
            var removeBtn = mkEl('button', {
                className: 'buglens-screenshot__remove',
                type: 'button',
                'aria-label': 'Remove screenshot',
            });
            removeBtn.appendChild(iconCloseSmall());
            removeBtn.addEventListener('click', function () {
                removeScreenshot(container);
            });
            container.appendChild(img);
            container.appendChild(removeBtn);
            return container;
        } else {
            var empty = mkEl('div', { className: 'buglens-screenshot buglens-screenshot--empty' });
            empty.appendChild(iconCamera());
            empty.appendChild(mkEl('span', { textContent: 'No screenshot captured' }));
            return empty;
        }
    }

    function removeScreenshot(container) {
        state.screenshotDataUrl = null;
        if (container && container.parentNode) {
            var empty = mkEl('div', { className: 'buglens-screenshot buglens-screenshot--empty' });
            empty.appendChild(iconCamera());
            empty.appendChild(mkEl('span', { textContent: 'No screenshot captured' }));
            container.replaceWith(empty);
        }
    }

    function closeForm() {
        state.formVisible = false;

        // Animate out
        if (formBackdrop) {
            formBackdrop.classList.remove('buglens-form-backdrop--visible');
        }
        if (formPanel) {
            formPanel.classList.remove('buglens-form--visible');
        }

        // Remove after animation
        var bRef = formBackdrop;
        var fRef = formPanel;
        setTimeout(function () {
            if (bRef) bRef.remove();
            if (fRef) fRef.remove();
        }, 350);

        formBackdrop = null;
        formPanel = null;

        // Reset FAB
        fabBtn.classList.remove('buglens-fab--active');

        // Remove keydown listener
        document.removeEventListener('keydown', onFormKeyDown);
    }

    function onFormKeyDown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeForm();
        }
    }

    /* ======================================================================
       18. Form Submit
       ====================================================================== */

    async function handleSubmit(submitBtn) {
        var titleInput = formPanel.querySelector('#buglens-title');
        var descTextarea = formPanel.querySelector('#buglens-desc');
        var title = (titleInput ? titleInput.value : '').trim();
        var description = (descTextarea ? descTextarea.value : '').trim();

        if (!title) {
            if (titleInput) {
                titleInput.focus();
                titleInput.style.borderColor = 'var(--buglens-error)';
                setTimeout(function () { titleInput.style.borderColor = ''; }, 2000);
            }
            return;
        }

        // Disable submit button, show loading spinner
        submitBtn.disabled = true;
        var originalText = submitBtn.textContent;
        submitBtn.textContent = '';
        var spinner = mkEl('span', { className: 'buglens-btn__spinner' });
        submitBtn.appendChild(spinner);
        submitBtn.appendChild(document.createTextNode(' Submitting...'));

        try {
            var formData = {
                title: title,
                description: description,
                selector: state.metadata.selector,
                parentChain: state.metadata.parentChain,
                outerHTML: state.metadata.outerHTML,
                innerText: state.metadata.innerText,
                computedStyles: state.metadata.computedStyles,
                boundingBox: state.metadata.boundingBox,
                context: state.metadata.context,
                overlaySelector: state.metadata.overlaySelector,
                browser: state.metadata.browser,
                viewport: state.metadata.viewport,
                consoleErrors: state.metadata.consoleErrors,
                screenshot: state.screenshotDataUrl,
            };

            await submitReport(formData);

            // Success
            closeForm();
            showToast('Bug report submitted successfully!', 'success');

            // Reset state
            state.selectedElement = null;
            state.metadata = null;
            state.screenshotDataUrl = null;
        } catch (err) {
            // Error
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            showToast('Failed to submit: ' + err.message, 'error');
        }
    }

    /* ======================================================================
       19. Initialise
       ====================================================================== */

    function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', createFab);
        } else {
            createFab();
        }
    }

    init();
})();
