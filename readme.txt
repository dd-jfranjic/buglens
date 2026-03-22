=== BugLens – Visual Bug Reporter for AI Agents ===
Contributors: jfranjic42, 2klika
Donate link: https://2klika.hr
Tags: bug-report, ai, developer-tools, debugging, screenshot
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 3.1.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visually select elements, capture screenshots, and create AI-optimized bug reports with structured data for AI coding agents.

== Description ==

BugLens bridges the gap between **visual bug reporting** and **AI-powered development workflows**. It lets users visually select any element on a page, capture a screenshot, and generate structured bug reports optimized for AI coding agents like Claude, GPT, Cursor, and others.

Instead of vague bug descriptions like *"the button looks weird"*, BugLens captures the exact CSS selector, computed styles, bounding box, DOM context, console errors, and a screenshot — everything an AI agent needs to understand and fix the issue.

= What Makes BugLens Different? =

Most bug reporters are designed for humans. BugLens is designed for **AI agents**:

* **Structured data** — CSS selectors, computed styles, outerHTML, parent chain, bounding box, viewport, browser info
* **Machine-readable exports** — every report is auto-exported as Markdown and JSON
* **REST API** — AI agents can programmatically fetch, create, and manage reports
* **Console error capture** — JavaScript errors are automatically included
* **Visual context** — screenshots with the selected element highlighted

= Features =

**Frontend Widget**

* Visual element selector with hover highlighting
* Automatic screenshot capture (html2canvas)
* Console error capture
* Smart overlay/modal detection
* Configurable FAB position and color
* Visibility controls (admins, logged-in, everyone)
* Vanilla JS — no jQuery, no build step, no dependencies
* Responsive and accessible

**Admin Dashboard**

* Kanban board with drag-and-drop (Open → In Progress → Resolved → Closed)
* Detail modal with full report data
* Page URL filter
* Delete with cleanup

**Developer Tools**

* REST API with API key authentication

**Export System**

* Auto-generated Markdown reports in `wp-content/uploads/buglens/`
* JSON index file (`reports.json`) for programmatic access
* Designed to be directly included in AI agent prompts

= How It Works =

1. **Report**: Click the floating bug icon, hover to select an element, describe the bug, submit
2. **Manage**: View reports on a Kanban board, drag between columns, click for details
3. **Fix**: Feed the structured report to your AI coding agent via file, API, or copy-paste

= Use with AI Agents =

**Claude Code / CLI:**
`cat wp-content/uploads/buglens/report-42.md`

**Via REST API:**
`curl -H "X-BugLens-Key: YOUR_KEY" https://yoursite.com/wp-json/buglens/v1/reports`

= Data Captured Per Report =

* Page URL, CSS selector, parent chain
* outerHTML (configurable limit), inner text
* Computed styles (color, font, display, position, etc.)
* Bounding box (position + dimensions)
* Viewport size, browser/user agent
* JavaScript console errors
* Screenshot (PNG with element highlighted)
* Overlay/modal context detection
* Status tracking (open, in_progress, resolved, closed)

== Installation ==

1. Upload the `buglens` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **BugLens > Settings** to configure the widget

= Configuration =

| Setting | Description | Default |
|---------|-------------|---------|
| API Key | Auto-generated key for REST API auth | Auto |
| FAB Position | Corner for the floating button | Bottom Right |
| Visibility | Who sees the widget | Admins only |
| Color | FAB and accent color | #F2C700 |
| outerHTML Limit | Max chars for element HTML | 5000 |
| Console Errors | Capture JS errors | Enabled |

== Frequently Asked Questions ==

= Who is BugLens for? =

BugLens is for developers and teams who use AI coding assistants (Claude, GPT, Cursor, Copilot, etc.) to fix bugs. It gives the AI all the structured context it needs to understand and fix issues without guessing.

= Can non-technical users report bugs? =

Yes. The frontend widget is simple: click the bug icon, select the element, describe the issue, submit. No technical knowledge needed. The technical data (selectors, styles, etc.) is captured automatically.

= Does it work with page builders? =

Yes. BugLens works on any WordPress page regardless of how it was built — Gutenberg, Elementor, WPBakery, Divi, custom themes, etc. It operates at the DOM level.

= Is the REST API secure? =

Yes. All API endpoints require either a valid API key (sent via `X-BugLens-Key` header, validated with timing-safe `hash_equals()`) or a WordPress admin session with `manage_options` capability. All inputs are sanitized and all outputs are escaped.

= Does it affect frontend performance? =

BugLens loads one CSS file (~3KB) and one JS file (~15KB) on the frontend — only for users who match the visibility setting (default: admins only). No jQuery dependency. Minimal impact.

= Can I use it on a staging site only? =

Yes. Install and activate it only on your staging/development site. The widget visibility can also be set to "Admins only" so clients never see it.

= What happens when I uninstall? =

BugLens performs a clean removal: all reports, meta data, options, and uploaded files (screenshots, exports) are deleted. No orphaned data.

= Does it support multisite? =

BugLens works on individual sites within a multisite network. Each site has its own reports, settings, and API key.

== Screenshots ==

1. Frontend widget — floating action button in the corner of the page
2. Element selector mode — hover to highlight, click to select
3. Bug report form — slide-up panel with title, description, and captured data
4. Admin Kanban board — drag-and-drop bug reports between status columns
5. Report detail modal — full report with screenshot, element info, styles, HTML
6. Settings page — API key, widget position, visibility, color

== Changelog ==

= 3.0.0 — 2026-03-12 =
* **New**: Bridge API — full filesystem access for AI coding agents via REST API
* **New**: Bridge Security — API key + optional IP whitelist, time-limited tokens, path restrictions, read-only mode
* **New**: buglens-mcp npm package — MCP server supporting Claude Code, Cursor, Windsurf, Codex, Gemini, VS Code, Cline, JetBrains
* **New**: "Connect Your AI Agent" settings page with copy-paste config snippets for 8+ AI tools
* **New**: 12 filesystem endpoints: read, write, create, delete, rename, list, search, info, diff, bulk-read, tree, wp-cli
* **New**: 3 bug report endpoints exposed via MCP: list, detail, status update

= 2.0.0 — 2026-03-11 =
* **New**: Kanban board with drag-and-drop status management
* **New**: REST API with full CRUD operations and API key authentication
* **New**: Automatic Markdown and JSON export for AI agent consumption
* **New**: Console error capture (auto-collects JS errors on the page)
* **New**: Smart overlay/modal context detection
* **New**: Report detail modal with full data display
* **New**: Page URL filter on the board
* **New**: Delete reports with full cleanup (post, meta, screenshot, export files)
* **New**: Configurable outerHTML capture limit
* **New**: Translation-ready with .pot file
* **New**: Clean uninstall removes all data
* **Improved**: Security — timing-safe API key comparison, nonce verification, capability checks
* **Improved**: Frontend widget — fully self-contained vanilla JS, no innerHTML usage
* **Improved**: Accessibility — keyboard navigation, ARIA attributes, prefers-reduced-motion

= 1.0.0 — 2026-03-10 =
* Initial release
* Frontend visual element selector with hover highlighting
* Screenshot capture via html2canvas
* Floating action button with configurable position and color
* Bug report form with title and description
* Visibility controls (admins, logged-in, everyone)
* Custom post type for bug reports
* Basic admin interface

== Upgrade Notice ==

= 3.0.0 =
Major update: adds Bridge API for remote AI agent access on shared hosting. Includes buglens-mcp npm package with 15 MCP tools.

= 2.0.0 =
Major update: adds Kanban board, REST API, and AI-optimized exports. Recommended for all users.

= 1.0.0 =
Initial release.
