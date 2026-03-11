/**
 * BugLens Kanban Board — vanilla JS
 * Reads config from window.buglensBoard (injected via wp_localize_script).
 */
(function () {
    'use strict';

    var config = window.buglensBoard || {};
    var restUrl = config.restUrl || '';
    var nonce   = config.nonce   || '';
    var apiKey  = config.apiKey  || '';

    // Cache for loaded reports (keyed by ID)
    var reportsCache = {};
    // Current filter value
    var currentPageFilter = '';

    /* ==================================================================
     * ARIA — enhance server-rendered board with accessibility attributes
     * ================================================================*/

    function applyAriaAttributes() {
        // Card zones: role="list"
        var cardZones = document.querySelectorAll('.buglens-board__cards');
        cardZones.forEach(function (zone) {
            zone.setAttribute('role', 'list');
        });

        // Column headers: role="heading"
        var columnHeaders = document.querySelectorAll('.buglens-board__column-header h3');
        columnHeaders.forEach(function (h3) {
            h3.setAttribute('role', 'heading');
            h3.setAttribute('aria-level', '3');
        });

        // Modal close button
        var modalClose = document.querySelector('.buglens-modal__close');
        if (modalClose) {
            modalClose.setAttribute('aria-label', 'Close modal');
        }
    }

    /* ==================================================================
     * Bootstrap
     * ================================================================*/

    document.addEventListener('DOMContentLoaded', function () {
        applyAriaAttributes();
        loadReports();
        setupDragDrop();

        // Filter dropdown
        var filterSelect = document.getElementById('buglens-filter-page');
        if (filterSelect) {
            filterSelect.addEventListener('change', function () {
                currentPageFilter = this.value;
                filterByPage(currentPageFilter);
            });
        }

        // Modal close handlers
        var modal = document.getElementById('buglens-card-modal');
        if (modal) {
            modal.querySelector('.buglens-modal__backdrop').addEventListener('click', closeModal);
            modal.querySelector('.buglens-modal__close').addEventListener('click', closeModal);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeModal();
            });
        }
    });

    /* ==================================================================
     * API helpers
     * ================================================================*/

    function apiHeaders() {
        var h = { 'Content-Type': 'application/json' };
        if (nonce)  h['X-WP-Nonce']    = nonce;
        if (apiKey) h['X-BugLens-Key'] = apiKey;
        return h;
    }

    /* ==================================================================
     * loadReports — fetch all reports and render
     * ================================================================*/

    function loadReports() {
        fetch(restUrl + 'reports?per_page=100', { headers: apiHeaders() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var reports = data.reports || [];

                // Clear columns
                var cardZones = document.querySelectorAll('.buglens-board__cards');
                cardZones.forEach(function (zone) { zone.textContent = ''; });

                // Unique page URLs for filter
                var pages = {};

                reports.forEach(function (report) {
                    reportsCache[report.id] = report;
                    var status = report.status || 'open';
                    var column = document.querySelector('.buglens-board__cards[data-status="' + status + '"]');
                    if (!column) {
                        column = document.querySelector('.buglens-board__cards[data-status="open"]');
                    }
                    column.appendChild(renderCard(report));

                    if (report.page_url) {
                        pages[report.page_url] = true;
                    }
                });

                // Populate page filter
                populatePageFilter(Object.keys(pages));
                updateCounts();
            })
            .catch(function (err) {
                console.error('BugLens: Failed to load reports', err);
            });
    }

    /* ==================================================================
     * populatePageFilter
     * ================================================================*/

    function populatePageFilter(urls) {
        var select = document.getElementById('buglens-filter-page');
        if (!select) return;

        // Keep the "All pages" option, remove the rest
        while (select.options.length > 1) {
            select.remove(1);
        }

        urls.sort().forEach(function (url) {
            var opt = document.createElement('option');
            opt.value = url;
            opt.textContent = truncate(url, 80);
            select.appendChild(opt);
        });

        // Restore active filter
        if (currentPageFilter) {
            select.value = currentPageFilter;
        }
    }

    /* ==================================================================
     * renderCard — create a card DOM element using safe DOM methods
     * ================================================================*/

    function renderCard(report) {
        var card = document.createElement('div');
        card.className = 'buglens-card';
        card.draggable = true;
        card.setAttribute('data-id', report.id);
        card.setAttribute('role', 'listitem');
        card.setAttribute('aria-label', report.title || 'Untitled report');
        if (report.page_url) {
            card.setAttribute('data-page-url', report.page_url);
        }

        // Screenshot thumbnail — use direct attachment URL from summary data.
        if (report.screenshot_url) {
            var img = document.createElement('img');
            img.className = 'buglens-card__thumb';
            img.src = report.screenshot_url;
            img.alt = 'Screenshot';
            img.loading = 'lazy';
            card.appendChild(img);
        }

        // Title
        var title = document.createElement('h4');
        title.className = 'buglens-card__title';
        title.textContent = report.title || 'Untitled';
        card.appendChild(title);

        // Page URL meta
        if (report.page_url) {
            var meta = document.createElement('div');
            meta.className = 'buglens-card__meta';
            meta.textContent = truncate(report.page_url, 40);
            card.appendChild(meta);
        }

        // Selector
        if (report.selector) {
            var sel = document.createElement('div');
            sel.className = 'buglens-card__selector';
            sel.textContent = truncate(report.selector, 50);
            card.appendChild(sel);
        }

        // Footer: date + delete button
        var footer = document.createElement('div');
        footer.className = 'buglens-card__footer';

        if (report.reported_at) {
            var dateDiv = document.createElement('div');
            dateDiv.className = 'buglens-card__date';
            dateDiv.textContent = timeAgo(report.reported_at);
            footer.appendChild(dateDiv);
        }

        // Delete button (trash icon)
        var deleteBtn = document.createElement('button');
        deleteBtn.className = 'buglens-card__delete';
        deleteBtn.title = 'Delete report';
        deleteBtn.type = 'button';
        deleteBtn.setAttribute('aria-label', 'Delete report');
        var trashIcon = document.createElement('span');
        trashIcon.className = 'dashicons dashicons-trash';
        deleteBtn.appendChild(trashIcon);
        deleteBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            if (!confirm('Delete this report? This cannot be undone.')) return;
            deleteReport(report.id);
        });
        footer.appendChild(deleteBtn);
        card.appendChild(footer);

        // Click to open modal
        card.addEventListener('click', function (e) {
            // Ignore if we just finished dragging
            if (card.classList.contains('is-dragging')) return;
            openModal(report.id);
        });

        return card;
    }

    /* ==================================================================
     * setupDragDrop — HTML5 Drag & Drop
     * ================================================================*/

    function setupDragDrop() {
        var board = document.getElementById('buglens-board');
        if (!board) return;

        // Drag start/end on cards (delegated)
        board.addEventListener('dragstart', function (e) {
            var card = e.target.closest('.buglens-card');
            if (!card) return;
            card.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-id'));
        });

        board.addEventListener('dragend', function (e) {
            var card = e.target.closest('.buglens-card');
            if (card) card.classList.remove('is-dragging');
            // Remove all drag-over highlights
            document.querySelectorAll('.buglens-board__cards.is-drag-over').forEach(function (el) {
                el.classList.remove('is-drag-over');
            });
        });

        // Drop targets: the .buglens-board__cards zones
        var zones = document.querySelectorAll('.buglens-board__cards');
        zones.forEach(function (zone) {
            zone.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });

            zone.addEventListener('dragenter', function (e) {
                e.preventDefault();
                zone.classList.add('is-drag-over');
            });

            zone.addEventListener('dragleave', function (e) {
                // Only remove if we truly left the zone (not entering a child)
                if (!zone.contains(e.relatedTarget)) {
                    zone.classList.remove('is-drag-over');
                }
            });

            zone.addEventListener('drop', function (e) {
                e.preventDefault();
                zone.classList.remove('is-drag-over');

                var reportId = e.dataTransfer.getData('text/plain');
                var newStatus = zone.getAttribute('data-status');
                var card = document.querySelector('.buglens-card[data-id="' + reportId + '"]');

                if (!card) return;

                // Move DOM
                zone.appendChild(card);
                updateCounts();

                // Update on server
                updateStatus(reportId, newStatus);
            });
        });
    }

    /* ==================================================================
     * updateStatus — PATCH report status
     * ================================================================*/

    function updateStatus(reportId, newStatus) {
        fetch(restUrl + 'reports/' + reportId, {
            method: 'PATCH',
            headers: apiHeaders(),
            body: JSON.stringify({ status: newStatus }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            // Update cache
            if (reportsCache[reportId]) {
                reportsCache[reportId].status = newStatus;
            }
        })
        .catch(function (err) {
            console.error('BugLens: Failed to update status', err);
            // Reload to restore consistent state
            loadReports();
        });
    }

    /* ==================================================================
     * deleteReport — DELETE report via REST API
     * ================================================================*/

    function deleteReport(reportId) {
        fetch(restUrl + 'reports/' + reportId, {
            method: 'DELETE',
            headers: apiHeaders(),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.deleted) {
                // Remove card from board
                var card = document.querySelector('.buglens-card[data-id="' + reportId + '"]');
                if (card) card.remove();
                // Remove from cache
                delete reportsCache[reportId];
                updateCounts();
                closeModal();
            }
        })
        .catch(function (err) {
            console.error('BugLens: Failed to delete report', err);
            alert('Failed to delete report.');
        });
    }

    /* ==================================================================
     * openModal — load full report detail
     * ================================================================*/

    function openModal(reportId) {
        var modal = document.getElementById('buglens-card-modal');
        var body  = document.getElementById('buglens-modal-body');
        if (!modal || !body) return;

        body.textContent = '';
        var loadingP = document.createElement('p');
        loadingP.style.textAlign = 'center';
        loadingP.style.padding = '40px';
        loadingP.textContent = 'Loading...';
        body.appendChild(loadingP);
        modal.style.display = '';

        fetch(restUrl + 'reports/' + reportId, { headers: apiHeaders() })
            .then(function (r) { return r.json(); })
            .then(function (report) {
                buildModalContent(body, report);

                // Status change dropdown
                var statusSelect = body.querySelector('#buglens-modal-status');
                if (statusSelect) {
                    statusSelect.addEventListener('change', function () {
                        var newStatus = this.value;
                        updateStatus(report.id, newStatus);
                        // Move card on board
                        var card = document.querySelector('.buglens-card[data-id="' + report.id + '"]');
                        var targetZone = document.querySelector('.buglens-board__cards[data-status="' + newStatus + '"]');
                        if (card && targetZone) {
                            targetZone.appendChild(card);
                            updateCounts();
                        }
                    });
                }
            })
            .catch(function (err) {
                body.textContent = '';
                var errP = document.createElement('p');
                errP.style.color = '#d63638';
                errP.textContent = 'Failed to load report.';
                body.appendChild(errP);
                console.error('BugLens: Failed to load report', err);
            });
    }

    /* ==================================================================
     * buildModalContent — render full report detail using safe DOM methods
     * ================================================================*/

    function buildModalContent(container, report) {
        container.textContent = '';

        // Title
        var h2 = document.createElement('h2');
        h2.style.margin = '0 0 4px';
        h2.textContent = report.title || 'Untitled';
        container.appendChild(h2);

        // Status dropdown
        var statusWrap = document.createElement('div');
        statusWrap.style.marginBottom = '16px';
        var statusLabel = document.createElement('label');
        statusLabel.setAttribute('for', 'buglens-modal-status');
        var statusStrong = document.createElement('strong');
        statusStrong.textContent = 'Status: ';
        statusLabel.appendChild(statusStrong);
        statusWrap.appendChild(statusLabel);

        var statusSelect = document.createElement('select');
        statusSelect.id = 'buglens-modal-status';
        var statusOptions = [
            { value: 'open', label: 'Open' },
            { value: 'in_progress', label: 'In Progress' },
            { value: 'resolved', label: 'Resolved' },
            { value: 'closed', label: 'Closed' },
        ];
        statusOptions.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.value;
            opt.textContent = s.label;
            if (s.value === report.status) opt.selected = true;
            statusSelect.appendChild(opt);
        });
        statusWrap.appendChild(statusSelect);
        container.appendChild(statusWrap);

        // Page URL
        if (report.page_url) {
            var pageP = document.createElement('p');
            var pageStrong = document.createElement('strong');
            pageStrong.textContent = 'Page: ';
            pageP.appendChild(pageStrong);
            var pageLink = document.createElement('a');
            pageLink.href = report.page_url;
            pageLink.target = '_blank';
            pageLink.rel = 'noopener';
            pageLink.textContent = report.page_url;
            pageP.appendChild(pageLink);
            container.appendChild(pageP);
        }

        // Reported date
        if (report.reported_at) {
            var dateP = document.createElement('p');
            var dateStrong = document.createElement('strong');
            dateStrong.textContent = 'Reported: ';
            dateP.appendChild(dateStrong);
            dateP.appendChild(document.createTextNode(
                formatDate(report.reported_at) + ' (' + timeAgo(report.reported_at) + ')'
            ));
            container.appendChild(dateP);
        }

        // Description
        if (report.description) {
            var descP = document.createElement('p');
            var descStrong = document.createElement('strong');
            descStrong.textContent = 'Description: ';
            descP.appendChild(descStrong);
            descP.appendChild(document.createTextNode(report.description));
            container.appendChild(descP);
        }

        // Screenshot
        if (report.screenshot_url) {
            var screenshotSection = createSection('Screenshot');
            var screenshotImg = document.createElement('img');
            screenshotImg.src = report.screenshot_url;
            screenshotImg.alt = 'Screenshot';
            screenshotImg.style.maxWidth = '100%';
            screenshotImg.style.border = '1px solid #dcdcde';
            screenshotImg.style.borderRadius = '4px';
            screenshotSection.appendChild(screenshotImg);
            container.appendChild(screenshotSection);
        }

        // Element details
        var elemSection = createSection('Element Details');
        var hasElemDetails = false;

        if (report.selector) {
            appendLabelCode(elemSection, 'Selector', report.selector);
            hasElemDetails = true;
        }
        if (report.parent_chain) {
            appendLabelCode(elemSection, 'Parent chain', report.parent_chain);
            hasElemDetails = true;
        }
        if (report.context) {
            appendLabelText(elemSection, 'Context', report.context);
            hasElemDetails = true;
        }
        if (report.overlay_selector) {
            appendLabelCode(elemSection, 'Overlay selector', report.overlay_selector);
            hasElemDetails = true;
        }
        if (hasElemDetails) {
            container.appendChild(elemSection);
        }

        // Computed styles
        if (report.computed_styles && typeof report.computed_styles === 'object' && Object.keys(report.computed_styles).length > 0) {
            var stylesSection = createSection('Computed Styles');
            var stylesTable = createKVTable(report.computed_styles);
            stylesSection.appendChild(stylesTable);
            container.appendChild(stylesSection);
        }

        // outerHTML
        if (report.outer_html) {
            var outerSection = createSection('outerHTML');
            var pre = document.createElement('pre');
            pre.className = 'buglens-modal__code';
            var code = document.createElement('code');
            code.textContent = report.outer_html;
            pre.appendChild(code);
            outerSection.appendChild(pre);
            container.appendChild(outerSection);
        }

        // Inner text
        if (report.inner_text) {
            var innerSection = createSection('Inner Text');
            var innerPre = document.createElement('pre');
            innerPre.className = 'buglens-modal__code';
            innerPre.textContent = report.inner_text;
            innerSection.appendChild(innerPre);
            container.appendChild(innerSection);
        }

        // Console errors
        if (report.console_errors && Array.isArray(report.console_errors) && report.console_errors.length > 0) {
            var errSection = createSection('Console Errors (' + report.console_errors.length + ')');
            var ul = document.createElement('ul');
            ul.className = 'buglens-modal__errors';
            report.console_errors.forEach(function (err) {
                var li = document.createElement('li');
                var errCode = document.createElement('code');
                errCode.textContent = typeof err === 'string' ? err : JSON.stringify(err);
                li.appendChild(errCode);
                ul.appendChild(li);
            });
            errSection.appendChild(ul);
            container.appendChild(errSection);
        }

        // Bounding box
        if (report.bounding_box && typeof report.bounding_box === 'object' && Object.keys(report.bounding_box).length > 0) {
            var boxSection = createSection('Bounding Box');
            var boxTable = createKVTable(report.bounding_box);
            boxSection.appendChild(boxTable);
            container.appendChild(boxSection);
        }

        // Browser / viewport
        if (report.browser || report.viewport) {
            var envSection = createSection('Environment');
            if (report.browser) {
                appendLabelText(envSection, 'Browser', report.browser);
            }
            if (report.viewport) {
                appendLabelText(envSection, 'Viewport', report.viewport);
            }
            container.appendChild(envSection);
        }

        // Delete button
        var deleteSection = document.createElement('div');
        deleteSection.style.marginTop = '24px';
        deleteSection.style.paddingTop = '16px';
        deleteSection.style.borderTop = '1px solid #f0f0f1';
        deleteSection.style.textAlign = 'right';
        var deleteBtn = document.createElement('button');
        deleteBtn.className = 'button';
        deleteBtn.style.color = '#d63638';
        deleteBtn.style.borderColor = '#d63638';
        deleteBtn.textContent = 'Delete Report';
        deleteBtn.addEventListener('click', function () {
            if (!confirm('Are you sure you want to delete this report? This cannot be undone.')) return;
            deleteReport(report.id);
        });
        deleteSection.appendChild(deleteBtn);
        container.appendChild(deleteSection);
    }

    /* ==================================================================
     * DOM builder helpers for modal
     * ================================================================*/

    function createSection(titleText) {
        var section = document.createElement('div');
        section.className = 'buglens-modal__section';
        var h3 = document.createElement('h3');
        h3.textContent = titleText;
        section.appendChild(h3);
        return section;
    }

    function appendLabelCode(parent, label, value) {
        var p = document.createElement('p');
        var strong = document.createElement('strong');
        strong.textContent = label + ': ';
        p.appendChild(strong);
        var code = document.createElement('code');
        code.textContent = value;
        p.appendChild(code);
        parent.appendChild(p);
    }

    function appendLabelText(parent, label, value) {
        var p = document.createElement('p');
        var strong = document.createElement('strong');
        strong.textContent = label + ': ';
        p.appendChild(strong);
        p.appendChild(document.createTextNode(value));
        parent.appendChild(p);
    }

    function createKVTable(obj) {
        var table = document.createElement('table');
        table.className = 'buglens-modal__kv-table';
        Object.keys(obj).forEach(function (key) {
            var tr = document.createElement('tr');
            var tdKey = document.createElement('td');
            var keyCode = document.createElement('code');
            keyCode.textContent = key;
            tdKey.appendChild(keyCode);
            tr.appendChild(tdKey);
            var tdVal = document.createElement('td');
            tdVal.textContent = String(obj[key]);
            tr.appendChild(tdVal);
            table.appendChild(tr);
        });
        return table;
    }

    /* ==================================================================
     * closeModal
     * ================================================================*/

    function closeModal() {
        var modal = document.getElementById('buglens-card-modal');
        if (modal) modal.style.display = 'none';
        var body = document.getElementById('buglens-modal-body');
        if (body) body.textContent = '';
    }

    /* ==================================================================
     * updateCounts — count cards per column
     * ================================================================*/

    function updateCounts() {
        var statuses = ['open', 'in_progress', 'resolved', 'closed'];
        statuses.forEach(function (status) {
            var zone = document.querySelector('.buglens-board__cards[data-status="' + status + '"]');
            var badge = document.querySelector('.buglens-board__count[data-count-for="' + status + '"]');
            if (zone && badge) {
                // Count only visible cards
                var cards = zone.querySelectorAll('.buglens-card');
                var visibleCount = 0;
                cards.forEach(function (card) {
                    if (card.style.display !== 'none') visibleCount++;
                });
                badge.textContent = visibleCount;
            }
        });
    }

    /* ==================================================================
     * timeAgo — relative time from ISO date string
     * ================================================================*/

    function timeAgo(dateString) {
        var date = new Date(dateString);
        var now = new Date();
        var seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days < 30) return days + 'd ago';
        var months = Math.floor(days / 30);
        if (months < 12) return months + 'mo ago';
        var years = Math.floor(months / 12);
        return years + 'y ago';
    }

    /* ==================================================================
     * formatDate — readable date from ISO string
     * ================================================================*/

    function formatDate(dateString) {
        var d = new Date(dateString);
        return d.toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
    }

    /* ==================================================================
     * filterByPage — show/hide cards by page_url
     * ================================================================*/

    function filterByPage(url) {
        var cards = document.querySelectorAll('.buglens-card');
        cards.forEach(function (card) {
            if (!url) {
                card.style.display = '';
            } else {
                var cardUrl = card.getAttribute('data-page-url') || '';
                card.style.display = (cardUrl === url) ? '' : 'none';
            }
        });
        updateCounts();
    }

    /* ==================================================================
     * Utility: truncate
     * ================================================================*/

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

})();
