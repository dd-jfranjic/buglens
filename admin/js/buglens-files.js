/**
 * BugLens File Browser
 *
 * Lazy-loading tree + CodeMirror editor for BugLens export files.
 */
(function () {
    'use strict';

    if (typeof buglensFiles === 'undefined') return;

    var config = buglensFiles;
    var treeEl = document.getElementById('buglens-tree');
    var contentEl = document.getElementById('buglens-content');
    var breadcrumbEl = document.getElementById('buglens-breadcrumb');
    var toolbarEl = document.getElementById('buglens-toolbar');
    var fileNameEl = document.getElementById('buglens-file-name');
    var fileSizeEl = document.getElementById('buglens-file-size');
    var downloadBtn = document.getElementById('buglens-file-download');
    var saveBtn = document.getElementById('buglens-file-save');

    var currentEditor = null;
    var currentFilePath = null;
    var activeTreeItem = null;

    // ── AJAX helper ──

    function ajax(action, data, method) {
        var fd = new FormData();
        fd.append('action', 'buglens_files_' + action);
        fd.append('nonce', config.nonce);
        if (data) {
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        }

        var opts = { method: method || 'POST', body: fd };
        return fetch(config.ajaxUrl, opts).then(function (r) { return r.json(); });
    }

    // ── DOM helper: clear all children ──

    function clearChildren(el) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
    }

    // ── DOM helper: create a message div ──

    function createMessageDiv(className, text) {
        var div = document.createElement('div');
        div.className = className;
        div.textContent = text;
        return div;
    }

    // ── DOM helper: set save button content (icon + text) ──

    function setSaveBtnContent(iconClass, text) {
        clearChildren(saveBtn);
        var icon = document.createElement('span');
        icon.className = 'dashicons ' + iconClass;
        saveBtn.appendChild(icon);
        saveBtn.appendChild(document.createTextNode(' ' + text));
    }

    // ── Breadcrumb ──

    function renderBreadcrumb(path) {
        clearChildren(breadcrumbEl);
        var parts = path.split('/').filter(Boolean);

        var rootSpan = document.createElement('span');
        rootSpan.className = 'buglens-files-breadcrumb__item';
        rootSpan.textContent = '/buglens/';
        rootSpan.dataset.path = '/';
        rootSpan.addEventListener('click', function () { loadTree('/', treeEl); updateBreadcrumb('/'); });
        breadcrumbEl.appendChild(rootSpan);

        var accumulated = '';
        parts.forEach(function (part, i) {
            var sep = document.createElement('span');
            sep.className = 'buglens-files-breadcrumb__sep';
            sep.textContent = '/';
            breadcrumbEl.appendChild(sep);

            accumulated += '/' + part;
            var span = document.createElement('span');
            span.className = 'buglens-files-breadcrumb__item';
            span.textContent = part;
            span.dataset.path = accumulated;
            if (i < parts.length - 1) {
                (function (p) {
                    span.addEventListener('click', function () { loadTree(p, treeEl); updateBreadcrumb(p); });
                })(accumulated);
            }
            breadcrumbEl.appendChild(span);
        });
    }

    function updateBreadcrumb(path) {
        renderBreadcrumb(path);
    }

    // ── Tree ──

    function loadTree(path, container) {
        clearChildren(container);
        container.appendChild(createMessageDiv('buglens-files-tree__loading', 'Loading...'));

        ajax('list', { path: path }).then(function (resp) {
            clearChildren(container);
            if (!resp.success || !resp.data.items.length) {
                container.appendChild(createMessageDiv('buglens-files-tree__loading', 'Empty directory'));
                return;
            }

            resp.data.items.forEach(function (item) {
                var row = document.createElement('div');
                row.className = 'buglens-files-tree__item' + (item.is_dir ? ' buglens-files-tree__item--dir' : '');

                var icon = document.createElement('span');
                icon.className = 'dashicons ' + getIcon(item);
                row.appendChild(icon);

                var name = document.createElement('span');
                name.textContent = item.name;
                row.appendChild(name);

                if (item.is_dir) {
                    var childContainer = document.createElement('div');
                    childContainer.className = 'buglens-files-tree__children';
                    childContainer.style.display = 'none';
                    var expanded = false;

                    row.addEventListener('click', function (e) {
                        e.stopPropagation();
                        expanded = !expanded;
                        childContainer.style.display = expanded ? 'block' : 'none';
                        icon.className = 'dashicons ' + (expanded ? 'dashicons-open-folder' : 'dashicons-category');
                        if (expanded && !childContainer.dataset.loaded) {
                            childContainer.dataset.loaded = '1';
                            loadTree(item.path, childContainer);
                        }
                        updateBreadcrumb(item.path);
                    });

                    container.appendChild(row);
                    container.appendChild(childContainer);
                } else {
                    row.addEventListener('click', function (e) {
                        e.stopPropagation();
                        if (activeTreeItem) activeTreeItem.classList.remove('is-active');
                        row.classList.add('is-active');
                        activeTreeItem = row;
                        openFile(item);
                    });
                    container.appendChild(row);
                }
            });
        });
    }

    function getIcon(item) {
        if (item.is_dir) return 'dashicons-category';
        var ext = item.type;
        if (ext === 'json') return 'dashicons-editor-code';
        if (ext === 'md') return 'dashicons-media-text';
        if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].indexOf(ext) >= 0) return 'dashicons-format-image';
        return 'dashicons-media-default';
    }

    // ── File viewer ──

    function openFile(item) {
        currentFilePath = item.path;
        clearChildren(contentEl);
        contentEl.appendChild(createMessageDiv('buglens-files-empty', 'Loading...'));
        toolbarEl.style.display = 'none';

        ajax('read', { path: item.path }).then(function (resp) {
            if (!resp.success) {
                clearChildren(contentEl);
                var errorMsg = 'Error: ' + (resp.data || 'Unknown');
                contentEl.appendChild(createMessageDiv('buglens-files-empty', errorMsg));
                return;
            }

            var file = resp.data;
            fileNameEl.textContent = file.name;
            fileSizeEl.textContent = formatSize(file.size);
            toolbarEl.style.display = 'flex';

            if (file.type === 'image') {
                renderImage(file);
                saveBtn.style.display = 'none';
            } else {
                renderText(file);
                saveBtn.style.display = '';
            }
        });
    }

    function renderImage(file) {
        clearChildren(contentEl);
        var img = document.createElement('img');
        img.src = file.url;
        img.alt = file.name;
        contentEl.appendChild(img);
    }

    function renderText(file) {
        clearChildren(contentEl);

        var textarea = document.createElement('textarea');
        textarea.id = 'buglens-file-editor';
        textarea.value = file.content;
        contentEl.appendChild(textarea);

        // Destroy previous editor
        if (currentEditor) {
            currentEditor.toTextArea();
            currentEditor = null;
        }

        // Initialize CodeMirror via wp.codeEditor
        if (typeof wp !== 'undefined' && wp.codeEditor && config.cmSettings) {
            var settings = JSON.parse(JSON.stringify(config.cmSettings));
            if (settings.codemirror) {
                settings.codemirror.mode = file.mode || 'text/plain';
            }
            var instance = wp.codeEditor.initialize(textarea, settings);
            currentEditor = instance.codemirror;
        }
    }

    // ── Actions ──

    saveBtn.addEventListener('click', function () {
        if (!currentFilePath) return;

        var content = currentEditor ? currentEditor.getValue() : '';
        saveBtn.disabled = true;
        setSaveBtnContent('dashicons-update spin', 'Saving...');

        ajax('write', { path: currentFilePath, content: content }).then(function (resp) {
            saveBtn.disabled = false;
            if (resp.success) {
                setSaveBtnContent('dashicons-yes', 'Saved!');
                setTimeout(function () {
                    setSaveBtnContent('dashicons-saved', 'Save');
                }, 1500);
            } else {
                setSaveBtnContent('dashicons-no', 'Error');
                alert('Save failed: ' + (resp.data || 'Unknown error'));
                setTimeout(function () {
                    setSaveBtnContent('dashicons-saved', 'Save');
                }, 2000);
            }
        });
    });

    downloadBtn.addEventListener('click', function () {
        if (!currentFilePath) return;
        var url = config.ajaxUrl + '?action=buglens_files_download&nonce=' + encodeURIComponent(config.nonce)
            + '&path=' + encodeURIComponent(currentFilePath);
        window.open(url, '_blank');
    });

    // ── Helpers ──

    function formatSize(bytes) {
        if (bytes == null) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    // ── Init ──
    loadTree('/', treeEl);
})();
