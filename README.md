# BugLens - Visual Bug Reporter for AI Agents

**BugLens** is a WordPress plugin that bridges the gap between **visual bug reporting** and **AI-powered development workflows**. It lets users visually select any element on a page, capture a screenshot, and generate structured bug reports that are optimized for consumption by AI coding agents (Claude, GPT, Cursor, etc.).

Instead of vague bug descriptions like *"the button looks weird"*, BugLens captures the exact CSS selector, computed styles, bounding box, DOM context, console errors, and a screenshot — everything an AI agent needs to understand and fix the issue without guessing.

## Why BugLens?

Modern development increasingly involves AI coding assistants. But these assistants work best with **structured, precise data** — not vague descriptions. BugLens solves this by:

- **Capturing what AI agents need**: CSS selectors, computed styles, outerHTML, parent chain, bounding box coordinates, viewport size, browser info, and console errors
- **Exporting in machine-readable formats**: Every report is automatically exported as Markdown and JSON, ready to be fed directly into an AI agent's context
- **Providing a REST API**: AI agents can programmatically fetch, create, and manage bug reports via authenticated API endpoints
- **Including visual context**: Annotated screenshots with the selected element highlighted

## Features

### Frontend Widget
- **Visual element selector** — hover over any element to see its tag and classes, click to select
- **Automatic screenshot capture** — uses `html2canvas` to capture the viewport with the selected element highlighted
- **Console error capture** — automatically collects JavaScript errors that occurred on the page
- **Smart context detection** — detects if the selected element is inside a modal, popover, dropdown, or other overlay
- **Floating action button (FAB)** — configurable position (4 corners) and color
- **Visibility controls** — show to admins only, logged-in users, or everyone
- **Fully self-contained** — vanilla JS, no jQuery, no build step, no external dependencies
- **Responsive** — works on mobile and desktop
- **Accessibility** — keyboard navigable, respects `prefers-reduced-motion`

### Admin Dashboard
- **Kanban board** — drag-and-drop bug reports between status columns (Open, In Progress, Resolved, Closed)
- **Detail modal** — click any card to see the full report with screenshot, element details, computed styles, outerHTML, console errors
- **Page filter** — filter reports by the page URL they were reported on
- **Delete reports** — with confirmation, removes report, screenshot, and export files

### Built-in Terminal
- **Web-based terminal** — full shell access via xterm.js in the WordPress admin
- **Session management** — persistent CWD across commands, 15-minute timeout
- **History** — arrow keys navigate command history
- **Safety** — warning dialog on first use, requires explicit acceptance

### File Browser
- **Browse export files** — tree view of all BugLens export files (Markdown reports, JSON index, screenshots)
- **CodeMirror editor** — syntax-highlighted editing of text files
- **Image preview** — inline preview of screenshots
- **Download** — download any file directly
- **Path-safe** — all file operations are sandboxed to the BugLens uploads directory

### REST API
- `GET /wp-json/buglens/v1/reports` — list all reports (paginated, filterable by status)
- `POST /wp-json/buglens/v1/reports` — create a new report (with all metadata + base64 screenshot)
- `GET /wp-json/buglens/v1/reports/{id}` — get a single report with full details
- `PATCH /wp-json/buglens/v1/reports/{id}` — update report status
- `DELETE /wp-json/buglens/v1/reports/{id}` — delete a report
- `GET /wp-json/buglens/v1/reports/{id}/screenshot` — get screenshot URL

Authentication via `X-BugLens-Key` header (API key) or WordPress admin session (nonce).

### Export System
- **Markdown reports** — each report is exported as a structured `.md` file in `wp-content/uploads/buglens/`
- **JSON index** — `reports.json` contains a machine-readable index of all reports with metadata and file paths
- **Auto-generated** — exports are created/updated automatically when reports are created, updated, or deleted
- **AI-ready** — format is designed to be directly included in AI agent prompts

## How It Works

### 1. Report a Bug (Frontend)

1. Click the floating bug icon (FAB) on any page
2. The page enters **selector mode** — hover over elements to highlight them
3. Click an element to select it
4. A slide-up form appears with:
   - Pre-filled element info (selector, screenshot)
   - Title field
   - Description field (describe the bug)
5. Submit — the report is sent to the REST API with all captured metadata

### 2. Manage Bugs (Admin)

1. Go to **BugLens > Board** in WordPress admin
2. See all reports in a Kanban board (Open / In Progress / Resolved / Closed)
3. Drag cards between columns to update status
4. Click a card to see full details — screenshot, selector, styles, HTML, console errors
5. Use the page filter dropdown to focus on bugs from a specific page

### 3. Feed to AI Agent

Bug reports are automatically exported to `wp-content/uploads/buglens/` as Markdown files. You can:

- **Direct file access**: Point your AI agent to read `wp-content/uploads/buglens/reports.json` for the index, then individual `report-{id}.md` files
- **REST API**: Have your AI agent call `GET /wp-json/buglens/v1/reports` with the API key
- **Copy-paste**: Open a report in the admin, copy the details into your AI chat

#### Example: Using with Claude Code

```bash
# Read the bug reports index
cat wp-content/uploads/buglens/reports.json

# Read a specific report
cat wp-content/uploads/buglens/report-42.md

# Or via API
curl -H "X-BugLens-Key: YOUR_API_KEY" https://yoursite.com/wp-json/buglens/v1/reports
```

#### Example Markdown Report Output

```markdown
# BugLens Report #42

| Field | Value |
|-------|-------|
| Status | open |
| Page | https://yoursite.com/contact/ |
| Reported | 2026-03-11 10:30:00 |
| Browser | Mozilla/5.0 ... Chrome/122 |
| Viewport | 1920x1080 |

## Target Element

- **Selector:** `form.contact-form > button.submit-btn`
- **Parent chain:** `body > main > section.contact > form.contact-form > button.submit-btn`
- **Bounding box:** x:450, y:680, 200x48

## Description

The submit button doesn't change color on hover. Expected: green hover state.

## Console Errors

[0] TypeError: Cannot read properties of undefined (reading 'addEventListener')
```

## Installation

### From GitHub (recommended)

1. Download the latest release ZIP from the [Releases page](https://github.com/dd-jfranjic/buglens/releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP and activate

### Manual

1. Clone or download this repository
2. Copy the entire folder to `wp-content/plugins/buglens/`
3. Activate the plugin in WordPress admin

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/dd-jfranjic/buglens.git
```

### Requirements

- WordPress 6.0+
- PHP 8.0+

## Configuration

After activation, go to **BugLens > Settings** in the WordPress admin:

| Setting | Description | Default |
|---------|-------------|---------|
| **API Key** | Auto-generated 40-char key for REST API authentication. Can be regenerated. | Auto-generated |
| **FAB Position** | Corner of screen for the floating button | Bottom Right |
| **Widget Visibility** | Who sees the bug report widget | Admins only |
| **Widget Color** | Primary color for the FAB and accents | `#F2C700` |
| **outerHTML Limit** | Max characters captured for element's outer HTML | 5000 |
| **Capture Console Errors** | Auto-capture JS console errors | Enabled |

## Data Captured Per Report

Each bug report captures:

| Data | Description |
|------|-------------|
| **Title** | User-provided bug title |
| **Description** | User-provided bug description |
| **Page URL** | Full URL where the bug was reported |
| **CSS Selector** | Unique selector for the selected element |
| **Parent Chain** | Full DOM path from body to the element |
| **outerHTML** | The element's HTML (truncated to configured limit) |
| **Inner Text** | Text content of the element |
| **Computed Styles** | Key CSS properties (color, font, display, position, etc.) |
| **Bounding Box** | Element's position and dimensions on screen |
| **Viewport** | Browser viewport dimensions |
| **Browser** | Full user agent string |
| **Console Errors** | JavaScript errors captured on the page |
| **Screenshot** | PNG screenshot of the viewport with element highlighted |
| **Context** | Whether element is on-page or inside an overlay/modal |
| **Overlay Selector** | If in overlay, the selector of the overlay container |
| **Status** | Report status: open, in_progress, resolved, closed |

## Plugin Structure

```
buglens/
├── buglens.php                    # Main plugin file, hooks, activation/deactivation
├── uninstall.php                  # Clean removal of all plugin data
├── includes/
│   ├── class-buglens-cpt.php      # Custom Post Type registration + meta fields
│   ├── class-buglens-rest-api.php # REST API endpoints (CRUD + screenshot)
│   ├── class-buglens-export.php   # Markdown + JSON export generation
│   ├── class-buglens-widget.php   # Frontend widget conditional loading
│   ├── class-buglens-admin.php    # Admin menus, settings, asset enqueuing
│   ├── class-buglens-terminal.php # Web terminal (proc_open + session management)
│   └── class-buglens-files.php    # File browser (AJAX tree + editor)
├── admin/
│   ├── css/buglens-admin.css      # Admin styles (board, terminal, files, settings)
│   ├── js/
│   │   ├── buglens-board.js       # Kanban board (drag-drop, modal, CRUD)
│   │   ├── buglens-terminal.js    # xterm.js terminal frontend
│   │   ├── buglens-files.js       # File browser (tree + CodeMirror)
│   │   └── buglens-settings.js    # Settings page (color picker, API key)
│   └── views/
│       ├── board.php              # Board page template
│       ├── terminal.php           # Terminal page template
│       ├── files.php              # Files page template
│       └── settings.php           # Settings page template
├── public/
│   ├── css/buglens-widget.css     # Frontend widget styles (FAB, form, toast)
│   └── js/buglens-widget.js       # Frontend widget (selector, capture, submit)
├── vendor/
│   └── xterm/                     # xterm.js library (terminal emulator)
├── languages/
│   ├── buglens.pot                # Translation template
│   └── index.php                  # Directory guard
└── .gitignore
```

## Security

- **API authentication** — REST endpoints require either a valid API key (`X-BugLens-Key` header) or WordPress admin session with `manage_options` capability
- **Timing-safe comparison** — API key validation uses `hash_equals()` to prevent timing attacks
- **Nonce verification** — all AJAX endpoints verify WordPress nonces
- **Capability checks** — all admin pages and AJAX handlers check `manage_options` capability
- **Input sanitization** — all inputs sanitized via `sanitize_text_field()`, `absint()`, `sanitize_hex_color()`, JSON validation
- **Output escaping** — all output escaped via `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`
- **Path traversal protection** — file browser operations are sandboxed to the BugLens uploads directory via `realpath()` + prefix check
- **XSS prevention** — frontend widget and admin JS use safe DOM methods (`textContent`, `createElement`) instead of `innerHTML`
- **Directory protection** — upload directories have `index.php` guards against directory listing
- **Terminal safeguards** — warning dialog, session timeout (15 min), admin-only access

## Internationalization

BugLens is fully translation-ready. All user-facing strings use WordPress i18n functions (`__()`, `esc_html__()`, `esc_attr__()`, `wp_kses()`). A `.pot` file is included in `languages/buglens.pot`.

Export output (Markdown/JSON) is intentionally NOT translated — it's consumed by AI agents, not displayed to end users.

## Clean Uninstall

When you delete BugLens via the WordPress admin, it removes:
- All bug report posts and their meta data
- The `buglens_api_key` and `buglens_settings` options
- The entire `wp-content/uploads/buglens/` directory (screenshots, exports, terminal sessions)

No orphaned data is left behind.

## Development

### No Build Step Required

BugLens uses vanilla JavaScript (no React, no webpack, no npm). Just edit the PHP/JS/CSS files directly.

### Coding Standards

- PHP follows WordPress Coding Standards (WPCS)
- CSS uses BEM naming with `buglens-` prefix
- JS is vanilla ES5-compatible (no transpilation needed)
- All frontend JS is self-contained with no external dependencies

### Generating the POT File

```bash
wp i18n make-pot /path/to/buglens /path/to/buglens/languages/buglens.pot --domain=buglens
```

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Credits

Built by [2klika](https://2klika.hr) for AI-assisted WordPress development workflows.

**Third-party libraries:**
- [xterm.js](https://xtermjs.org/) v6.0.0 — Terminal emulator (MIT License)
- [html2canvas](https://html2canvas.hertzen.com/) — Screenshot capture (loaded from CDN, MIT License)
