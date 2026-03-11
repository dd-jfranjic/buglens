# Changelog

All notable changes to BugLens will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] — 2026-03-11

### Fixed
- Escape output for `checked()` return values in settings (Plugin Check compliance)
- Prefix all global variables in `uninstall.php` and `board.php` with `buglens_`
- Add phpcs ignore comments for intentional `proc_open`/`fclose` usage in terminal
- Add phpcs ignore comments for raw input in terminal commands and file editor content
- Include `readme.txt` in plugin directory for WordPress.org validation

## [2.0.0] — 2026-03-11

### Added
- Kanban board with drag-and-drop status management (Open, In Progress, Resolved, Closed)
- Built-in web terminal with xterm.js (session persistence, command history, CWD tracking)
- File browser with CodeMirror editor for viewing/editing export files
- REST API with full CRUD operations (`GET`, `POST`, `PATCH`, `DELETE`)
- API key authentication with timing-safe comparison (`hash_equals`)
- Automatic Markdown export for each report (`wp-content/uploads/buglens/report-{id}.md`)
- JSON index file (`reports.json`) with all report metadata
- Console error capture — automatically collects JavaScript errors on the page
- Smart overlay/modal context detection (detects fixed/absolute positioned ancestors)
- Report detail modal with screenshot, element info, computed styles, outerHTML, console errors
- Page URL filter on the Kanban board
- Delete reports with full cleanup (post, meta, screenshot attachment, export files)
- Configurable outerHTML capture limit (500–50,000 characters)
- Translation-ready with complete `.pot` file
- Clean uninstall — removes all posts, meta, options, and uploaded files
- Settings page with color picker, visibility controls, FAB position
- API key copy-to-clipboard and regenerate functionality

### Improved
- Security — nonce verification on all AJAX endpoints, capability checks, input sanitization, output escaping
- Frontend widget — fully self-contained vanilla JS, zero innerHTML usage, XSS-safe DOM methods
- Accessibility — keyboard navigation, ARIA attributes, `prefers-reduced-motion` support
- Responsive design — mobile-friendly widget, admin board, and terminal

## [1.0.0] — 2026-03-10

### Added
- Initial release
- Frontend visual element selector with hover highlighting and tooltip
- Screenshot capture via html2canvas (loaded from CDN)
- Floating action button (FAB) with configurable position and color
- Slide-up bug report form with title and description fields
- Element data capture: CSS selector, parent chain, computed styles, bounding box, viewport, browser
- Visibility controls (admins only, logged-in users, everyone)
- Custom post type (`buglens_report`) for storing bug reports
- WordPress REST API endpoint for report creation
- Base64 screenshot storage as WordPress attachment
