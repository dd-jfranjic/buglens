# BugLens Rescue Mode

> AI agent emergency access when WordPress crashes.

## What it does

BugLens v3.1.0's Bridge API requires WordPress to boot successfully — if a
plugin, theme, or mu-plugin causes a fatal error, the Bridge becomes
unreachable. You'd need cPanel/FTP to recover.

**Rescue Mode (v3.2.0+)** installs a standalone PHP endpoint at
`wp-content/buglens-rescue-{random}.php` that works **independently of WordPress**:
no `wp-load.php`, no database, no plugin loading. If WP is broken, the rescue
endpoint still answers HTTP requests, allowing AI agents to read, write, or
delete files to fix the underlying issue.

## When to enable

Enable Rescue Mode if any of these apply:
- You deploy AI-generated code to production WordPress sites
- You want a recovery path that doesn't depend on cPanel access
- Multiple sites share a single AI agent and downtime is costly

If you only use BugLens locally or in development, Rescue Mode is unnecessary —
keep it disabled (the default).

## How to enable

Rescue Mode is **disabled by default**. The endpoint file exists after plugin
activation, but returns HTTP 503 until you provide a secret.

### Option 1 — Programmatic (via PHP)
```php
// One-time setup, e.g. from wp-cli `wp eval`:
$secret = BugLens_Bridge_Rescue_Security::set_rescue_secret();
// Returned PLAIN secret (shown once — save to password manager).
echo $secret;
```

### Option 2 — wp-config.php constant (no secret file on disk)
```php
// Add to wp-config.php:
define( 'BUGLENS_RESCUE_KEY', 'your-random-32-plus-char-secret' );
```
Generate the secret with: `openssl rand -hex 32`

The constant takes precedence over the secret hash file.

## How to use (from AI agent)

```bash
# Get rescue endpoint URL from BugLens status:
curl -s https://yoursite.com/wp-json/buglens/v1/fs/health | jq .rescue_status

# Direct call (when WP REST is down):
curl -X POST https://yoursite.com/wp-content/buglens-rescue-abc123.php \
  -H "Content-Type: application/json" \
  -H "X-BugLens-Rescue-Key: your-secret" \
  -d '{"op": "list", "path": "wp-content/mu-plugins"}'
```

### MCP server (Claude Code, etc.)
Set environment variables when launching MCP:
```bash
BUGLENS_URL=https://yoursite.com \
BUGLENS_KEY=your-api-key \
BUGLENS_RESCUE_URL=https://yoursite.com/wp-content/buglens-rescue-abc123.php \
BUGLENS_RESCUE_KEY=your-rescue-secret \
npx buglens-mcp
```

Use the `rescue_call` MCP tool when WP is in fatal state.

## How to disable

### Temporary disable (keeps rescue installed, returns 503)
```php
BugLens_Bridge_Rescue_Security::disable_rescue();
// Or delete: wp-content/buglens-rescue-state/secret-hash.txt
```

### Full removal
```bash
rm wp-content/buglens-rescue-*.php
rm -rf wp-content/buglens-rescue-state/
```

Or remove the `BUGLENS_RESCUE_KEY` constant from `wp-config.php`.

## Security

### Layers
1. **SHA256 timing-safe auth** — 32-char random secret = 2^192 search space.
2. **Random URL slug** — 32-char random per install = additional 2^192.
3. **HTTPS-only** — checks `HTTPS`, `X-Forwarded-Proto`, `X-Forwarded-SSL`, `CF-Visitor` headers (proxy-aware).
4. **Path boundary** — `realpath()` check ensures all access within ABSPATH; fail-closed if root unresolvable.
5. **Blocked patterns** — `wp-config.php`, `.htaccess`, `.htpasswd`, and ALL rescue artifacts (state dir, hash file, audit log, other rescue endpoints) at any nesting depth.
6. **Auto-lockout** — 5 failed auth attempts / minute → 1 hour IP ban.
7. **State dir protection** — auto-created `.htaccess` (Apache 2.4 + 2.2 fallback) blocks direct web access to secret hash, lockouts, audit log.
8. **Audit log** — all attempts (success + fail) logged in JSON Lines format.

### Threat model

| Attack | Mitigation |
|--------|-----------|
| Secret leaked (commit, screenshot, etc.) | Rotate secret: `set_rescue_secret()` returns new one |
| Brute-force | 32-char secret + auto-lockout = ~10^47 years to crack |
| URL slug discovery | 32-char random slug, not indexed, audit log catches scans |
| HTTPS spoofing via X-Forwarded-Proto | Acceptable on direct LiteSpeed/Apache; configure trusted proxies on Cloudflare |
| State file disclosure | `.htaccess` Deny + nested patterns + random folder slug (defense in depth) |
| Rate-limit bypass | Lockout file uses LOCK_EX; concurrent fails minimally tracked but acceptable |

### What rescue CANNOT do (by design)
- Read `wp-config.php` (blocked pattern)
- Read `.htaccess` / `.htpasswd` (blocked patterns)
- Read its own state files (blocked regex)
- Path traversal outside ABSPATH (`realpath()` check)
- Run wp-cli (deliberately scoped to file ops only)

## Audit log format

`wp-content/buglens-rescue-state/audit.jsonl` (one JSON object per line):

```json
{"ts":"2026-05-28T20:15:33+00:00","event":"read","ip":"2a01:4f8::1",
 "op":"read","path":"wp-content/themes/...","success":true,
 "detail":"","bytes":1234}
{"ts":"2026-05-28T20:16:01+00:00","event":"auth_failed","ip":"1.2.3.4",
 "op":null,"path":null,"success":false,"detail":"","bytes":null}
```

Review periodically. Failed auth attempts from unexpected IPs = consider IP whitelist or secret rotation.

## What rescue does NOT replace
- Regular Bridge API for everyday AI agent work (faster, no rescue secret needed)
- Off-site backups (Backuply, JetBackup, etc.)
- Server-level monitoring (Imunify, fail2ban, etc.)
- WordPress core/plugin updates

Rescue is a **last-resort recovery** mechanism, not a primary access channel.
