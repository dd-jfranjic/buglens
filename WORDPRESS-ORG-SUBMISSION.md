# WordPress.org Plugin Submission Guide — BugLens

Step-by-step instructions for publishing BugLens on the WordPress.org plugin directory.

---

## Prerequisites

1. **WordPress.org account** — Register at https://login.wordpress.org/register
2. **Plugin is ready** — Code reviewed, readme.txt complete, screenshots taken
3. **SVN client** — WordPress.org uses SVN (not Git) for plugin hosting

---

## Step 1: Submit Plugin for Review

1. Go to: **https://wordpress.org/plugins/developers/add/**
2. Log in with your WordPress.org account
3. Fill in:
   - **Plugin Name**: `BugLens – Visual Bug Reporter for AI Agents`
   - **Plugin Description**: Brief description (they'll read your readme.txt)
   - **Plugin URL**: `https://github.com/dd-jfranjic/buglens`
4. Upload the plugin ZIP file (see "Create ZIP" below)
5. Check the boxes:
   - ✅ I confirm this plugin is GPL-compatible
   - ✅ I have read the plugin guidelines
6. Click **Submit**

### Create the ZIP for submission

```bash
cd /path/to/buglens-repo

# Create a clean ZIP (exclude git, assets, and submission docs)
zip -r buglens-2.0.0.zip . \
  -x ".git/*" \
  -x ".gitignore" \
  -x "assets/*" \
  -x "WORDPRESS-ORG-SUBMISSION.md" \
  -x "CHANGELOG.md"
```

### Review Timeline

- WordPress.org team manually reviews every plugin
- Typical wait: **3–10 business days**
- They may ask for changes (security, guidelines compliance)
- You'll get an email when approved (or with feedback)

### Potential Review Concerns

The plugin team may flag:
- **Terminal feature** (`proc_open`) — They may consider this a security risk. Be prepared to explain it's admin-only with safety checks. Worst case: they may ask you to remove it or make it a separate addon.
- **File write operations** — Explain these are sandboxed to the BugLens uploads directory only.
- **Minified vendor files** (xterm.js) — They may ask for the source. Point them to the official xterm.js npm package.

---

## Step 2: Set Up SVN Repository

After approval, WordPress.org creates an SVN repo at:
```
https://plugins.svn.wordpress.org/buglens/
```

You'll get an email with the slug (likely `buglens`).

### SVN Structure

```
buglens/
├── trunk/          ← Latest development code (same as your plugin files)
├── tags/
│   └── 2.0.0/     ← Tagged release (copy of trunk at release time)
└── assets/         ← WordPress.org listing images (NOT in the plugin ZIP)
    ├── banner-772x250.png     ← Plugin page banner
    ├── banner-1544x500.png    ← Hi-DPI banner
    ├── icon-128x128.png       ← Plugin icon
    ├── icon-256x256.png       ← Hi-DPI icon
    ├── screenshot-1.png       ← Screenshots (match readme.txt numbering)
    ├── screenshot-2.png
    ├── screenshot-3.png
    ├── screenshot-4.png
    ├── screenshot-5.png
    ├── screenshot-6.png
    ├── screenshot-7.png
    └── screenshot-8.png
```

---

## Step 3: Initial SVN Checkout & Upload

```bash
# Checkout the empty SVN repo
svn co https://plugins.svn.wordpress.org/buglens/ buglens-svn
cd buglens-svn

# Copy plugin files to trunk/
cp -r /path/to/buglens-repo/* trunk/
# Remove non-plugin files from trunk
rm -f trunk/CHANGELOG.md trunk/WORDPRESS-ORG-SUBMISSION.md trunk/README.md
rm -rf trunk/assets/

# Copy screenshots to assets/
mkdir -p assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-1.png assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-2.png assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-3.png assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-4.png assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-5.png assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-6.png assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-7.png assets/
cp /path/to/buglens-repo/assets/screenshots/screenshot-8.png assets/

# TODO: Create banner and icon images (see "Assets" section below)

# Add all files to SVN
svn add trunk/*
svn add assets/*

# Create the version tag
svn cp trunk/ tags/2.0.0/

# Commit everything
svn ci -m "Initial release: BugLens v2.0.0" --username YOUR_WPORG_USERNAME
# (Enter your WordPress.org password when prompted)
```

---

## Step 4: Create Plugin Assets (Banner & Icon)

WordPress.org shows these on the plugin listing page:

### Required Assets

| Asset | Size | Description |
|-------|------|-------------|
| `banner-772x250.png` | 772 x 250 px | Plugin page header banner |
| `banner-1544x500.png` | 1544 x 500 px | Hi-DPI version of banner |
| `icon-128x128.png` | 128 x 128 px | Plugin icon (search results) |
| `icon-256x256.png` | 256 x 256 px | Hi-DPI plugin icon |

### Design Suggestions

**Icon**: A magnifying glass / lens icon with a bug inside, using the BugLens yellow (#F2C700) on dark (#1A1A2E) background.

**Banner**: Dark background (#1A1A2E) with:
- BugLens logo/icon on the left
- "Visual Bug Reporter for AI Agents" tagline
- Maybe a subtle screenshot of the widget in action
- Yellow (#F2C700) accent color

You can create these in Figma, Canva, or any design tool.

---

## Step 5: Future Updates

When you release a new version:

```bash
cd buglens-svn

# Update trunk with new files
rsync -av --delete /path/to/buglens-repo/ trunk/ \
  --exclude='.git' \
  --exclude='assets' \
  --exclude='CHANGELOG.md' \
  --exclude='WORDPRESS-ORG-SUBMISSION.md' \
  --exclude='README.md'

# Create new version tag
svn cp trunk/ tags/2.1.0/

# Update readme.txt Stable tag
# (Make sure "Stable tag: 2.1.0" in trunk/readme.txt)

# Commit
svn ci -m "Release v2.1.0: description of changes" --username YOUR_WPORG_USERNAME
```

### Important: Version Numbers

- **buglens.php** header `Version: X.Y.Z` must match
- **readme.txt** `Stable tag: X.Y.Z` must match
- **tags/X.Y.Z/** directory must exist
- All three must be the same version number

---

## Step 6: After Publishing

### Verify Listing

- Visit `https://wordpress.org/plugins/buglens/`
- Check screenshots display correctly
- Check description renders from readme.txt
- Test "Install Now" from a fresh WordPress site

### Monitor

- Watch for support tickets: `https://wordpress.org/support/plugin/buglens/`
- Check download stats: `https://wordpress.org/plugins/buglens/advanced/`
- Respond to reviews

### Promote

- Add "Available on WordPress.org" badge to your GitHub README
- Share on Twitter/X, Reddit (r/WordPress, r/webdev), WordPress communities
- Write a blog post about the AI + WordPress workflow
- Submit to plugin roundup articles

---

## Quick Reference: SVN Commands

```bash
# Checkout
svn co https://plugins.svn.wordpress.org/buglens/ buglens-svn

# Add new files
svn add trunk/new-file.php

# Remove files
svn rm trunk/old-file.php

# Check status
svn stat

# Commit
svn ci -m "Commit message" --username YOUR_WPORG_USERNAME

# Update local copy
svn up
```

---

## Checklist Before Submission

- [x] `readme.txt` follows WordPress.org format
- [x] `Stable tag` matches plugin version
- [x] Plugin headers complete (Name, URI, Description, Version, Author, License)
- [x] GPL v2+ license
- [x] No hardcoded API keys or credentials
- [x] No external service calls without user consent
- [x] All strings translatable
- [x] Clean uninstall (uninstall.php)
- [x] Proper sanitization and escaping
- [x] Nonce verification on all forms/AJAX
- [x] Capability checks on all admin functions
- [x] No PHP errors/warnings with WP_DEBUG enabled
- [x] Screenshots prepared (8 screenshots)
- [ ] Banner images created (772x250 + 1544x500)
- [ ] Icon images created (128x128 + 256x256)
- [ ] WordPress.org account registered
