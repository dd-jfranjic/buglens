# BugLens – Visual Bug Reporter for AI Agents

![WordPress Plugin Version](https://img.shields.io/badge/version-3.0.1-blue)
![WordPress Tested](https://img.shields.io/badge/WordPress-6.9%2B-green)
![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-purple)
![License](https://img.shields.io/badge/license-GPL%20v2%2B-orange)

**BugLens** is a WordPress plugin that bridges the gap between **visual bug reporting** and **AI-powered development workflows**. Users visually select any element on a page, capture a screenshot, and BugLens generates structured bug reports optimized for AI coding agents (Claude, GPT, Cursor, Copilot, etc.).

Instead of vague descriptions like *"the button looks weird"*, BugLens captures the exact CSS selector, computed styles, bounding box, DOM context, console errors, and a screenshot — everything an AI agent needs to understand and fix the issue without guessing.

---

## Who Is This For?

BugLens is built for **developers and teams who use AI coding agents** to build and maintain WordPress sites.

**v3.0 introduces the Bridge API** — now BugLens works on **shared hosting** too. No SSH, no CLI installation needed. Your AI agent connects remotely via MCP.

### Supported AI Agents & Tools

| AI Agent | Connection Method |
|----------|------------------|
| **[Claude Code](https://docs.anthropic.com/en/docs/claude-code)** (Anthropic) | MCP Bridge (remote) or direct filesystem |
| **[Claude Desktop](https://claude.ai)** (Anthropic) | MCP Bridge (remote) |
| **[Cursor](https://cursor.sh/)** / **[Windsurf](https://codeium.com/windsurf)** | MCP Bridge (remote) or local filesystem |
| **[Codex CLI](https://github.com/openai/codex)** (OpenAI) | MCP Bridge (remote) or direct filesystem |
| **[Gemini CLI](https://github.com/google-gemini/gemini-cli)** (Google) | MCP Bridge (remote) or direct filesystem |
| **[VS Code Copilot](https://code.visualstudio.com/)** | MCP Bridge (remote) |
| **[Cline](https://github.com/cline/cline)** / **[Continue](https://continue.dev/)** / **JetBrains** | MCP Bridge (remote) |

### Two Ways to Connect

**Option A: Bridge (Shared Hosting / Remote)** — v3.0 NEW

Your AI agent runs locally and connects to your WordPress site over HTTPS. No server-side installation needed beyond the plugin.

```
┌──────────────────┐         HTTPS          ┌──────────────────────┐
│  Your Computer   │ ◄───────────────────── │  Shared Hosting      │
│                  │                         │                      │
│  AI Agent        │   MCP (stdio)           │  WordPress + BugLens │
│  (Claude, etc.)  │ ◄──────────┐           │    └── Bridge API    │
│                  │            │           │        /fs/read       │
│                  │   buglens-mcp           │        /fs/write      │
│                  │   (npm package)         │        /fs/search     │
└──────────────────┘                         │        /fs/tree       │
                                             └──────────────────────┘
```

**Option B: Direct Filesystem (VPS / Dedicated / Local)**

AI agent runs on the same server with direct file access.

```
┌─────────────────────────────────────────────────┐
│  Your Server (Local / VPS / Dedicated)          │
│                                                 │
│  WordPress + BugLens                            │
│    ├── wp-content/uploads/buglens/              │
│    │   ├── report-42.md    ← AI reads this      │
│    │   ├── reports.json    ← index of all bugs  │
│    │   └── screenshots/                         │
│    └── wp-json/buglens/v1/ ← REST API           │
│                                                 │
│  AI Agent (Claude Code / Codex / Gemini CLI)    │
│    └── reads reports → understands bug → fixes  │
└─────────────────────────────────────────────────┘
```

---

## Screenshots

### Frontend Widget — Floating Action Button
The golden bug icon appears on your site (configurable corner). Only visible to users you choose (admins, logged-in, or everyone).

![Frontend FAB](assets/screenshots/screenshot-1.png)

### Element Selector Mode
Click the FAB to enter selector mode. Hover over any element — BugLens highlights it and shows its tag/class. Click to select.

![Selector Mode](assets/screenshots/screenshot-2.png)

### Bug Report Form
After selecting an element, a slide-up form captures the title, description, and all technical data automatically. One click to submit.

![Report Form](assets/screenshots/screenshot-3.png)

### Admin Kanban Board
All bug reports in a drag-and-drop board. Four columns: Open, In Progress, Resolved, Closed. Filter by page URL.

![Kanban Board](assets/screenshots/screenshot-4.png)

### Report Detail Modal
Click any card for the full picture: screenshot, element selector, computed styles, outerHTML, console errors, bounding box — all the data an AI agent needs.

![Report Modal](assets/screenshots/screenshot-5.png)

### Settings
Configure the API key, widget position, visibility, color, outerHTML limit, and console error capture.

![Settings](assets/screenshots/screenshot-8.png)

---

## Real-World Use Cases

### Use Case 1: Solo Developer with Claude Code

> *"I'm building a WordPress site and use Claude Code as my AI assistant. When I spot a visual bug, I click BugLens, select the broken element, and submit. Then I tell Claude: 'Read wp-content/uploads/buglens/report-42.md and fix the bug.' Claude gets the exact selector, styles, HTML, and screenshot — it fixes the issue in one shot instead of going back and forth."*

```bash
# In your Claude Code session:
cat wp-content/uploads/buglens/report-42.md
# Claude now has: selector, computed styles, outerHTML, screenshot path, console errors
# → fixes the CSS/HTML directly
```

### Use Case 2: Client QA Feedback

> *"My client isn't technical but can click a button and describe what's wrong. I set BugLens visibility to 'Everyone' during review, gave the client the staging URL, and asked them to report anything that looks off. Each report comes with a screenshot, the exact element, and computed styles — I don't need to ask 'which button?' or 'what page?' anymore."*

### Use Case 3: Agency Team with AI Workflow

> *"Our QA team uses BugLens on staging sites. Developers use the REST API to pull reports into their AI coding tools. The Kanban board tracks status. When a bug is fixed, drag it to 'Resolved'. No Jira tickets for CSS issues anymore."*

```bash
# Pull all open bugs via API
curl -H "X-BugLens-Key: YOUR_KEY" \
  "https://staging.example.com/wp-json/buglens/v1/reports?status=open"

# Feed to AI agent
for id in 101 102 103; do
  curl -s -H "X-BugLens-Key: YOUR_KEY" \
    "https://staging.example.com/wp-json/buglens/v1/reports/$id"
done
```

### Use Case 4: Debugging CSS/JS Issues

> *"A page had a weird layout shift but only on mobile. I opened it on my phone, used BugLens to select the broken element, and it captured the viewport size (375x667), computed styles (including the problematic `position: fixed`), and a console error about a missing font. I fed the report to Claude and the fix was immediate."*

### Use Case 5: Onboarding New Developers

> *"When onboarding a new developer, I tell them: 'Install BugLens, browse the site, report anything that looks off.' They don't need to know how to inspect elements or write CSS selectors. BugLens captures everything automatically, and I review reports on the Kanban board."*

---

## Why BugLens?

| Traditional Bug Report | BugLens Report |
|---|---|
| "The button is broken" | `button.cta-primary` at `x:450 y:680 200x48px` |
| "It looks weird on mobile" | Viewport `375x667`, computed `font-size: 12px`, `overflow: hidden` |
| "There's an error somewhere" | `TypeError: Cannot read 'addEventListener'` at line 42 |
| "I think it's on the contact page" | `https://example.com/contact/#form-section` |
| *Screenshot from phone camera* | Clean PNG with element highlighted, full page context |
| "The color seems off" | `color: #333`, `background: rgba(0,0,0,0.8)`, `opacity: 0.5` |

---

## Features

### Frontend Widget
- **Visual element selector** — hover over any element to see its tag and classes, click to select
- **Automatic screenshot capture** — uses html2canvas to capture the viewport with the selected element highlighted
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

### Bridge API (v3.0 NEW)

Full filesystem access for AI coding agents over HTTPS. Works on **shared hosting** — no SSH needed.

- **12 filesystem endpoints** — read, write, create, delete, rename, list, search, info, diff, bulk-read, tree, wp-cli
- **3 bug report endpoints** — list, detail, status update (via MCP)
- **`buglens-mcp` npm package** — zero-install MCP server (`npx buglens-mcp`)
- **Config snippets** — copy-paste setup for 8+ AI tools (Claude Code, Claude Desktop, Cursor, Windsurf, Codex, Gemini, VS Code, Cline/JetBrains)
- **4-layer security** — API key (always on) + optional IP whitelist, time-limited tokens, path restrictions, read-only mode
- **Path traversal protection** — blocks `..`, validates via `realpath()` within ABSPATH
- **Blocked paths** — wp-config.php, .htaccess, .htpasswd blocked by default

#### Quick Setup

1. Activate BugLens on your WordPress site
2. Go to **BugLens > Bridge**, enable the Bridge API
3. Copy the config snippet for your AI tool
4. Paste into your tool's MCP config

**Claude Code:**
```bash
BUGLENS_URL=https://yoursite.com BUGLENS_KEY=your_key \
  claude mcp add buglens -- npx -y buglens-mcp
```

**Cursor / Claude Desktop / Windsurf / Gemini:**
```json
{
  "mcpServers": {
    "buglens": {
      "command": "npx",
      "args": ["-y", "buglens-mcp"],
      "env": {
        "BUGLENS_URL": "https://yoursite.com",
        "BUGLENS_KEY": "your_key"
      }
    }
  }
}
```

#### Bridge Endpoints

```
POST /wp-json/buglens/v1/fs/read       — read file contents
POST /wp-json/buglens/v1/fs/write      — write to existing file
POST /wp-json/buglens/v1/fs/create     — create new file/directory
POST /wp-json/buglens/v1/fs/delete     — delete file/empty directory
POST /wp-json/buglens/v1/fs/rename     — rename/move file
POST /wp-json/buglens/v1/fs/list       — list directory contents
POST /wp-json/buglens/v1/fs/search     — search across files (like grep)
POST /wp-json/buglens/v1/fs/info       — file metadata (size, perms, mime)
POST /wp-json/buglens/v1/fs/diff       — compare content against file on disk
POST /wp-json/buglens/v1/fs/bulk-read  — read multiple files at once (max 20)
POST /wp-json/buglens/v1/fs/tree       — recursive directory tree
POST /wp-json/buglens/v1/fs/wp-cli     — execute WP-CLI command (if available)
POST /wp-json/buglens/v1/fs/token      — generate time-limited auth token
```

### Reports REST API
```
GET    /wp-json/buglens/v1/reports              — list reports (paginated, filterable)
POST   /wp-json/buglens/v1/reports              — create report (with metadata + screenshot)
GET    /wp-json/buglens/v1/reports/{id}          — get full report details
PATCH  /wp-json/buglens/v1/reports/{id}          — update status
DELETE /wp-json/buglens/v1/reports/{id}          — delete report
GET    /wp-json/buglens/v1/reports/{id}/screenshot — get screenshot URL
```
Authentication: `X-BugLens-Key` header (API key) or WordPress admin session (nonce).

### Export System
- **Markdown reports** — each report auto-exported as `.md` in `wp-content/uploads/buglens/`
- **JSON index** — `reports.json` with all report metadata and file paths
- **AI-ready** — format designed to be directly included in AI agent prompts

---

## How It Works

### Step 1: Report a Bug

1. Click the floating bug icon (FAB) on any page
2. The page enters **selector mode** — hover over elements to highlight them
3. Click an element to select it
4. A slide-up form appears with pre-filled element info + screenshot
5. Add a title and description, then submit

### Step 2: Manage on Kanban Board

1. Go to **BugLens > Board** in WordPress admin
2. See all reports organized by status
3. Drag cards between columns to update status
4. Click a card for full details

### Step 3: Feed to Your AI Agent

Bug reports are automatically exported as Markdown files:

```bash
# Read the index
cat wp-content/uploads/buglens/reports.json

# Read a specific report — feed this to your AI agent
cat wp-content/uploads/buglens/report-42.md
```

Or use the REST API:

```bash
curl -H "X-BugLens-Key: YOUR_API_KEY" \
  https://yoursite.com/wp-json/buglens/v1/reports/42
```

#### Example Report Output

```markdown
# BugLens Report #42

| Field | Value |
|-------|-------|
| Status | open |
| Page | https://yoursite.com/contact/ |
| Browser | Chrome/122.0 |
| Viewport | 1920x1080 |

## Target Element
- **Selector:** `form.contact-form > button.submit-btn`
- **Parent chain:** `body > main > section.contact > form > button`
- **Bounding box:** x:450, y:680, 200x48

## Description
Submit button doesn't change color on hover. Expected: green hover state.

## Console Errors
[0] TypeError: Cannot read properties of undefined (reading 'addEventListener')
```

---

## Installation

### From WordPress.org (recommended)

1. In WordPress admin, go to **Plugins > Add New**
2. Search for "BugLens"
3. Click **Install Now**, then **Activate**

### From GitHub

1. Download the [latest release](https://github.com/dd-jfranjic/buglens/releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP and activate

### Manual

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/dd-jfranjic/buglens.git
```

### Requirements

- WordPress 6.0+
- PHP 8.0+

---

## Configuration

After activation, go to **BugLens > Settings**:

| Setting | Description | Default |
|---------|-------------|---------|
| **API Key** | Auto-generated 40-char key for REST API auth. Can be regenerated. | Auto |
| **FAB Position** | Corner of screen for the floating button | Bottom Right |
| **Widget Visibility** | Who sees the bug report widget | Admins only |
| **Widget Color** | Primary color for the FAB and accents | `#F2C700` |
| **outerHTML Limit** | Max characters captured for element HTML | 5000 |
| **Console Errors** | Auto-capture JS console errors | Enabled |

---

## Data Captured Per Report

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
| **Screenshot** | PNG screenshot with element highlighted |
| **Context** | Whether element is on-page or inside an overlay/modal |
| **Status** | open, in_progress, resolved, closed |

---

## Plugin Structure

```
buglens/
├── buglens.php                         # Main plugin file
├── uninstall.php                       # Clean removal of all data
├── includes/
│   ├── class-buglens-cpt.php           # Custom Post Type + meta fields
│   ├── class-buglens-rest-api.php      # REST API (CRUD + screenshot)
│   ├── class-buglens-export.php        # Markdown + JSON export
│   ├── class-buglens-widget.php        # Frontend widget loading
│   ├── class-buglens-admin.php         # Admin menus, settings, assets
│   ├── class-buglens-bridge.php        # Bridge REST API (12 FS endpoints)
│   └── class-buglens-bridge-security.php # Bridge security layer
├── admin/
│   ├── css/buglens-admin.css           # Admin styles
│   ├── js/                             # Board, terminal, files, bridge, settings JS
│   └── views/                          # PHP templates (board, settings, bridge)
├── public/
│   ├── css/buglens-widget.css          # Frontend widget styles
│   └── js/buglens-widget.js            # Frontend widget logic
├── mcp-server/                         # buglens-mcp npm package
│   ├── package.json                    # npm config (npx buglens-mcp)
│   ├── bin/buglens-mcp.js              # CLI entry point
│   └── src/
│       ├── index.js                    # MCP server (15 tools)
│       └── client.js                   # HTTP client for Bridge API
└── languages/buglens.pot               # Translation template
```

---

## Security

- **API key auth** — timing-safe `hash_equals()` comparison
- **Nonce verification** — on all AJAX endpoints
- **Capability checks** — `manage_options` required for admin features
- **Input sanitization** — `sanitize_text_field()`, `absint()`, `sanitize_hex_color()`, JSON validation
- **Output escaping** — `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`
- **Path traversal protection** — file operations sandboxed via `realpath()` + prefix check
- **XSS prevention** — safe DOM methods (`textContent`, `createElement`), no `innerHTML`
- **Bridge security** (v3.0) — 4 optional layers: IP whitelist, time-limited tokens (SHA-256 hashed), path restrictions (allow/block lists), read-only mode
- **Blocked paths** (v3.0) — wp-config.php, .htaccess, .htpasswd blocked by default on Bridge endpoints

---

## Clean Uninstall

When deleted via WordPress admin, BugLens removes:
- All bug report posts and meta data
- Options (`buglens_api_key`, `buglens_settings`)
- The entire `wp-content/uploads/buglens/` directory

No orphaned data.

---

## Development

### No Build Step Required

Vanilla JavaScript — no React, no webpack, no npm. Edit PHP/JS/CSS directly.

### Coding Standards

- PHP: WordPress Coding Standards (WPCS)
- CSS: BEM naming with `buglens-` prefix
- JS: Vanilla ES5-compatible, no transpilation
- i18n: All strings wrapped in `__()` / `esc_html__()`

### Generate POT File

```bash
wp i18n make-pot ./buglens ./buglens/languages/buglens.pot --domain=buglens
```

---

## License

GPL v2 or later. See [LICENSE](LICENSE).

---

## Credits

Built by [2klika](https://2klika.hr) for AI-assisted WordPress development workflows.

**Third-party libraries:**
- [html2canvas](https://html2canvas.hertzen.com/) — Screenshot capture (loaded from CDN, MIT License)
- [@modelcontextprotocol/sdk](https://github.com/modelcontextprotocol/typescript-sdk) — MCP server SDK for buglens-mcp (MIT License)
