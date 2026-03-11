/**
 * BugLens Settings page JS.
 *
 * Handles:
 * - Copy API key to clipboard
 * - Regenerate API key via AJAX
 * - Initialize wpColorPicker on the color field
 *
 * Localized data available via `buglensSettings`:
 *   - ajaxUrl  (string)
 *   - i18n     (object with translated strings)
 *
 * @package BugLens
 */
(function ($) {
    'use strict';

    var i18n = (window.buglensSettings && window.buglensSettings.i18n) || {};

    /**
     * Initialize the color picker.
     */
    function initColorPicker() {
        var $picker = $('#buglens-color-picker');
        if ($picker.length && $.fn.wpColorPicker) {
            $picker.wpColorPicker();
        }
    }

    /**
     * Initialize the copy-to-clipboard button.
     */
    function initCopyButton() {
        var copyBtn = document.getElementById('buglens-copy-key');
        var keyInput = document.getElementById('buglens-api-key');

        if (!copyBtn || !keyInput) {
            return;
        }

        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(keyInput.value).then(function () {
                setButtonState(copyBtn, 'dashicons-yes', i18n.copied || 'Copied!');
                setTimeout(function () {
                    setButtonState(copyBtn, 'dashicons-clipboard', i18n.copy || 'Copy');
                }, 1500);
            });
        });
    }

    /**
     * Initialize the regenerate API key button.
     */
    function initRegenerateButton() {
        var regenBtn = document.getElementById('buglens-regenerate-key');
        var keyInput = document.getElementById('buglens-api-key');

        if (!regenBtn || !keyInput) {
            return;
        }

        var nonce = regenBtn.getAttribute('data-nonce') || '';

        regenBtn.addEventListener('click', function () {
            var confirmMsg = i18n.confirmRegen ||
                'Are you sure? Any external integrations using the current key will stop working.';

            if (!confirm(confirmMsg)) {
                return;
            }

            regenBtn.disabled = true;
            setButtonState(regenBtn, 'dashicons-update spin', i18n.regenerating || 'Regenerating...');

            var data = new FormData();
            data.append('action', 'buglens_regenerate_key');
            data.append('nonce', nonce);

            fetch(window.buglensSettings.ajaxUrl, { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        keyInput.value = resp.data.key;
                        setButtonState(regenBtn, 'dashicons-yes', i18n.done || 'Done!');
                        setTimeout(function () {
                            setButtonState(regenBtn, 'dashicons-update', i18n.regenerate || 'Regenerate');
                            regenBtn.disabled = false;
                        }, 1500);
                    } else {
                        var errPrefix = i18n.errorPrefix || 'Error:';
                        var errUnknown = i18n.unknownError || 'Unknown error';
                        alert(errPrefix + ' ' + (resp.data || errUnknown));
                        setButtonState(regenBtn, 'dashicons-update', i18n.regenerate || 'Regenerate');
                        regenBtn.disabled = false;
                    }
                })
                .catch(function () {
                    alert(i18n.networkError || 'Network error. Please try again.');
                    setButtonState(regenBtn, 'dashicons-update', i18n.regenerate || 'Regenerate');
                    regenBtn.disabled = false;
                });
        });
    }

    /**
     * Update a button's icon and text safely (no innerHTML).
     *
     * @param {HTMLElement} btn   The button element.
     * @param {string}      icon  The dashicons class (e.g. 'dashicons-yes').
     * @param {string}      text  The button label text.
     */
    function setButtonState(btn, icon, text) {
        // Remove all child nodes.
        while (btn.firstChild) {
            btn.removeChild(btn.firstChild);
        }

        // Create icon span.
        var span = document.createElement('span');
        var classes = icon.split(' ');
        span.classList.add('dashicons');
        for (var i = 0; i < classes.length; i++) {
            span.classList.add(classes[i]);
        }
        btn.appendChild(span);

        // Add text node with leading space.
        btn.appendChild(document.createTextNode(' ' + text));
    }

    // Initialize on DOM ready.
    $(document).ready(function () {
        initColorPicker();
        initCopyButton();
        initRegenerateButton();
    });

})(jQuery);
