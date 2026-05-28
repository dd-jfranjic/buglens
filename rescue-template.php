<?php
/**
 * BugLens Rescue Mode — Standalone fs endpoint for AI agents.
 *
 * lego-audit:ignore
 *
 * ARCHITECTURAL CONSTRAINT: This file MUST be single-file standalone.
 * It loads WITHOUT WordPress (no require wp-load.php, no include of helper files).
 * If WP fatal-irates, this file is the only way for AI agents to access fs.
 * Splitting into modules would require multiple file deploys, adding failure points
 * to the very thing meant to recover from failures.
 *
 * Works even when WordPress fatal-irates (mu-plugins/themes/plugins crash).
 * Distributed as template by BugLens plugin. On plugin activation, copied to
 *   wp-content/buglens-rescue-{random_slug}.php
 * with a unique URL slug per installation.
 *
 * Auth: SHA256 hash of secret stored in wp-content/buglens-rescue-secret-hash.txt
 *       (timing-safe compare). BUGLENS_RESCUE_KEY constant in wp-config.php
 *       takes precedence if defined (plain secret, not hash).
 *
 * Default: DISABLED. Returns HTTP 503 until secret is configured.
 *
 * Operations: read, write, delete, list, info, mkdir, rename
 *
 * Security layers (Option B — Standard Secure):
 *   - SHA256 timing-safe auth (2^192 search space)
 *   - Random URL slug (additional 2^192 search space)
 *   - HTTPS only (proxy-aware: HTTPS, X-Forwarded-Proto, X-Forwarded-SSL, CF-Visitor)
 *   - ABSPATH boundary (realpath check, FAIL-CLOSED if root unresolvable)
 *   - Blocked glob patterns: wp-config.php, .htaccess, .htpasswd,
 *     buglens-rescue-*.php (other rescue templates),
 *     buglens-rescue-*.json (state files), buglens-rescue-*.txt (secret hash)
 *   - Auto-lockout: 5 failed auth attempts per minute → 1h ban for that IP
 *   - Audit log: all attempts (success/fail) — falls back to /tmp if uploads/ unavailable
 *
 * @package BugLens
 * @since   3.2.0
 */

declare(strict_types=1);

// =============================================================================
// CONFIGURATION
// =============================================================================

/**
 * WordPress root directory. We are at wp-content/buglens-rescue-{slug}.php,
 * so go up 1 dir to wp-content's parent = ABSPATH.
 *
 * FAIL-CLOSED: if realpath() fails (filesystem error / permissions),
 * we abort with 500 rather than serve requests from undefined base.
 */
$BR_ABSPATH_RESOLVED = realpath(__DIR__ . '/..');
if ($BR_ABSPATH_RESOLVED === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'cannot_resolve_root',
        'message' => 'Filesystem root unresolvable. Check permissions.']);
    exit;
}
$BR_ABSPATH = $BR_ABSPATH_RESOLVED . '/';

/**
 * State directory — dedicated subfolder s .htaccess Deny.
 * CRITICAL: state files (secret hash, lockouts, audit) must NOT be web-accessible.
 * Direct access (e.g. GET /wp-content/buglens-rescue-state/secret-hash.txt) is
 * blocked by .htaccess that we auto-create on first request.
 *
 * On Nginx hosts (no .htaccess support), random folder slug provides additional
 * obscurity layer; security still relies on file content (SHA256 hash, not plaintext).
 */
$BR_STATE_DIR        = __DIR__ . '/buglens-rescue-state/';
$BR_SECRET_HASH_FILE = $BR_STATE_DIR . 'secret-hash.txt';
$BR_LOCKOUT_FILE     = $BR_STATE_DIR . 'lockouts.json';
$BR_AUDIT_LOG        = $BR_STATE_DIR . 'audit.jsonl';
$BR_AUDIT_FALLBACK   = sys_get_temp_dir() . '/buglens-rescue-audit-' . md5(__DIR__) . '.jsonl';

/** Security limits. */
const BR_MAX_FAILED_PER_MIN = 5;
const BR_LOCKOUT_DURATION   = 3600;
const BR_MAX_READ_BYTES     = 5 * 1024 * 1024;
const BR_MAX_WRITE_BYTES    = 5 * 1024 * 1024;

/**
 * Binary file extensions — read returns base64 + flag instead of raw content
 * (JSON encoding of binary data otherwise corrupts/silently fails).
 */
const BR_BINARY_EXTENSIONS = [
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'tiff',
    'woff', 'woff2', 'ttf', 'otf', 'eot',
    'zip', 'gz', 'tar', 'bz2', '7z',
    'pdf', 'mp4', 'mp3', 'mov', 'avi', 'webm',
    'sqlite', 'db',
];

/**
 * Blocked glob patterns (fnmatch). Matched against both full path AND basename.
 * Covers WordPress-sensitive files plus rescue endpoint siblings in WP root.
 */
const BR_BLOCKED_PATTERNS = [
    'wp-config.php',
    '.htaccess',
    '.htpasswd',
    'buglens-rescue-*.php',
];

/**
 * Regex patterns matching anywhere in path — catches nested state dirs and
 * any rescue artifacts (including audit logs, lockouts, secret hashes) even
 * if attacker bypasses random folder slug.
 */
const BR_BLOCKED_REGEX = [
    '#(?:^|/)buglens-rescue-state(?:/|$)#',           // entire state subdir
    '#(?:^|/)buglens-rescue-[^/]+\.php$#',            // any rescue endpoint at any depth
    '#(?:^|/)buglens-rescue-[^/]+\.(?:json|jsonl|txt)$#', // rescue state/audit files
    '#(?:^|/)secret-hash\.txt$#',                     // any file named secret-hash.txt
];

// =============================================================================
// MAIN ENTRY
// =============================================================================

try {
    br_main();
} catch (\Throwable $t) {
    // Don't leak internal details (file paths, stack traces) to client.
    // Full message goes to audit log for forensics.
    @br_audit('internal_error', br_client_ip(), null, null, false,
        sprintf('%s @ %s:%d', $t->getMessage(), $t->getFile(), $t->getLine()));
    br_respond(500, ['error' => 'internal_error',
        'message' => 'See server audit log for details.']);
}

function br_main(): void {
    br_require_https();
    br_ensure_state_dir();
    $ip = br_client_ip();
    br_check_lockout($ip);
    $secret = br_load_secret();
    if ($secret === null) {
        br_audit('not_configured', $ip, null, null, false);
        br_respond(503, ['error' => 'rescue_not_configured',
            'message' => 'Rescue mode is not configured. Generate secret via BugLens admin.']);
    }
    $payload = br_parse_request();
    if (!br_verify_auth($payload['key'] ?? '', $secret)) {
        br_record_failure($ip);
        br_audit('auth_failed', $ip, $payload['op'] ?? null, $payload['path'] ?? null, false);
        br_respond(403, ['error' => 'invalid_secret']);
    }
    br_clear_failures($ip);
    br_dispatch($payload, $ip);
}

/**
 * Auto-create state directory s .htaccess Deny + index.php silencer.
 * Self-contained — works even ako plugin obrisan ili nikad nije instaliran.
 * Apache/LiteSpeed honoraju .htaccess; Nginx hosts oslanjaju se na obscurity + hash auth.
 */
function br_ensure_state_dir(): void {
    global $BR_STATE_DIR;
    if (is_dir($BR_STATE_DIR)) return;
    if (!@mkdir($BR_STATE_DIR, 0755, true) && !is_dir($BR_STATE_DIR)) {
        br_respond(500, ['error' => 'state_dir_create_failed',
            'message' => 'Cannot create state directory.']);
    }
    @file_put_contents($BR_STATE_DIR . '.htaccess',
        "# Auto-generated by BugLens rescue. Deny direct web access to state files.\n" .
        "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n  Order Deny,Allow\n  Deny from all\n</IfModule>\n");
    @file_put_contents($BR_STATE_DIR . 'index.php', "<?php // Silence is golden.\n");
    @chmod($BR_STATE_DIR, 0755);
}

// =============================================================================
// SECURITY: HTTPS, AUTH, LOCKOUT
// =============================================================================

function br_require_https(): void {
    if (br_is_https()) return;
    br_respond(400, ['error' => 'https_required',
        'message' => 'Rescue endpoint requires HTTPS.']);
}

function br_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    $p = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (strtolower((string)$p) === 'https') return true;
    $s = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
    if (strtolower((string)$s) === 'on') return true;
    $cf = $_SERVER['HTTP_CF_VISITOR'] ?? '';
    if (is_string($cf) && strpos($cf, '"scheme":"https"') !== false) return true;
    return false;
}

function br_load_secret(): ?string {
    if (defined('BUGLENS_RESCUE_KEY')) {
        $k = BUGLENS_RESCUE_KEY;
        if (is_string($k) && strlen($k) >= 16) return $k;
    }
    global $BR_SECRET_HASH_FILE;
    if (is_file($BR_SECRET_HASH_FILE) && is_readable($BR_SECRET_HASH_FILE)) {
        $h = trim((string)@file_get_contents($BR_SECRET_HASH_FILE));
        if (strlen($h) === 64 && ctype_xdigit($h)) return $h;
    }
    return null;
}

function br_verify_auth(string $provided, string $stored): bool {
    if ($provided === '') return false;
    if (strlen($stored) === 64 && ctype_xdigit($stored)) {
        return hash_equals($stored, hash('sha256', $provided));
    }
    return hash_equals($stored, $provided);
}

function br_check_lockout(string $ip): void {
    global $BR_LOCKOUT_FILE;
    $data = br_read_json($BR_LOCKOUT_FILE);
    $ban = $data['bans'][$ip] ?? 0;
    if ($ban > time()) {
        br_respond(429, ['error' => 'ip_locked_out',
            'retry_after' => $ban - time(), 'message' => 'Too many failed attempts.']);
    }
}

function br_record_failure(string $ip): void {
    global $BR_LOCKOUT_FILE;
    $data = br_read_json($BR_LOCKOUT_FILE);
    $now = time();
    $data['attempts'] ??= [];
    $data['attempts'][$ip] ??= [];
    $data['attempts'][$ip] = array_values(array_filter(
        $data['attempts'][$ip],
        fn($t) => $t > ($now - 60)
    ));
    $data['attempts'][$ip][] = $now;
    if (count($data['attempts'][$ip]) >= BR_MAX_FAILED_PER_MIN) {
        $data['bans'][$ip] = $now + BR_LOCKOUT_DURATION;
        unset($data['attempts'][$ip]);
    }
    br_write_json($BR_LOCKOUT_FILE, $data);
}

function br_clear_failures(string $ip): void {
    global $BR_LOCKOUT_FILE;
    $data = br_read_json($BR_LOCKOUT_FILE);
    unset($data['attempts'][$ip]);
    br_write_json($BR_LOCKOUT_FILE, $data);
}

// =============================================================================
// REQUEST PARSING + DISPATCH
// =============================================================================

function br_parse_request(): array {
    $raw = (string)@file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
    if (empty($body['key'])) $body['key'] = $_SERVER['HTTP_X_BUGLENS_RESCUE_KEY'] ?? '';
    return $body;
}

function br_dispatch(array $payload, string $ip): void {
    $op = (string)($payload['op'] ?? '');
    $path = (string)($payload['path'] ?? '');
    $valid_ops = ['read', 'write', 'delete', 'list', 'info', 'mkdir', 'rename'];
    if (!in_array($op, $valid_ops, true)) {
        br_audit('invalid_op', $ip, $op, $path, false);
        br_respond(400, ['error' => 'invalid_op', 'valid' => $valid_ops]);
    }
    $abs = br_validate_path($path, $op);
    if ($abs === null) {
        br_audit($op, $ip, $op, $path, false, 'path_validation_failed');
        return;
    }
    $fn = 'br_op_' . $op;
    $result = $fn($abs, $payload);
    br_audit($op, $ip, $op, $path, true, '', $result['bytes'] ?? null);
    br_respond(200, $result);
}

// =============================================================================
// PATH VALIDATION (ABSPATH BOUNDARY + BLOCKED PATTERNS)
// =============================================================================

function br_validate_path(string $rel, string $op): ?string {
    global $BR_ABSPATH;
    if (strpos($rel, '..') !== false) {
        br_respond(403, ['error' => 'path_traversal_denied']);
    }
    $rel = ltrim($rel, '/');
    br_check_blocked_patterns($rel);
    $abs = $BR_ABSPATH . $rel;
    $real = realpath($abs);
    if ($real !== false) {
        if (!str_starts_with($real, $BR_ABSPATH)) {
            br_respond(403, ['error' => 'path_outside_root']);
        }
        return $real;
    }
    $parent = realpath(dirname($abs));
    if ($parent === false || !str_starts_with($parent, $BR_ABSPATH)) {
        if (in_array($op, ['read', 'info', 'list', 'delete', 'rename'], true)) {
            br_respond(404, ['error' => 'path_not_found']);
        }
        br_respond(403, ['error' => 'parent_outside_root']);
    }
    return $parent . '/' . basename($abs);
}

/**
 * Apply both fnmatch glob patterns AND regex patterns.
 * Glob = WP-sensitive files matched at root or basename level.
 * Regex = rescue artifacts matched at ANY depth (state dir, audit, hash).
 */
function br_check_blocked_patterns(string $rel): void {
    foreach (BR_BLOCKED_PATTERNS as $pat) {
        if (fnmatch($pat, $rel, FNM_PATHNAME) || fnmatch($pat, basename($rel))) {
            br_respond(403, ['error' => 'path_blocked', 'pattern' => $pat]);
        }
    }
    foreach (BR_BLOCKED_REGEX as $rx) {
        if (preg_match($rx, $rel)) {
            br_respond(403, ['error' => 'path_blocked', 'reason' => 'rescue_artifact']);
        }
    }
}

// =============================================================================
// OPERATIONS
// =============================================================================

function br_op_read(string $path, array $p): array {
    if (!is_file($path)) br_respond(404, ['error' => 'not_a_file']);
    if (filesize($path) > BR_MAX_READ_BYTES) br_respond(413, ['error' => 'file_too_large']);
    $content = (string)@file_get_contents($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, BR_BINARY_EXTENSIONS, true)) {
        return ['path' => br_rel($path), 'bytes' => strlen($content),
                'encoding' => 'base64', 'content' => base64_encode($content)];
    }
    // Final safety: ensure UTF-8 valid for JSON encoding
    if (!mb_check_encoding($content, 'UTF-8')) {
        return ['path' => br_rel($path), 'bytes' => strlen($content),
                'encoding' => 'base64', 'content' => base64_encode($content),
                'note' => 'invalid_utf8_returned_as_base64'];
    }
    return ['path' => br_rel($path), 'bytes' => strlen($content),
            'encoding' => 'utf8', 'content' => $content];
}

function br_op_write(string $path, array $p): array {
    $content = (string)($p['content'] ?? '');
    if (strlen($content) > BR_MAX_WRITE_BYTES) br_respond(413, ['error' => 'content_too_large']);
    if (!is_dir(dirname($path))) br_respond(404, ['error' => 'parent_dir_missing']);
    $ok = @file_put_contents($path, $content);
    if ($ok === false) br_respond(500, ['error' => 'write_failed']);
    @chmod($path, 0644);
    return ['path' => br_rel($path), 'bytes' => $ok, 'sha256' => hash('sha256', $content)];
}

function br_op_delete(string $path, array $p): array {
    if (!file_exists($path)) br_respond(404, ['error' => 'not_found']);
    $ok = is_dir($path) ? @rmdir($path) : @unlink($path);
    if (!$ok) br_respond(500, ['error' => 'delete_failed']);
    return ['path' => br_rel($path), 'deleted' => true];
}

function br_op_list(string $path, array $p): array {
    if (!is_dir($path)) br_respond(404, ['error' => 'not_a_directory']);
    $items = [];
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $path . '/' . $name;
        $items[] = ['name' => $name, 'is_dir' => is_dir($full),
                    'size' => is_file($full) ? filesize($full) : 0];
    }
    return ['path' => br_rel($path), 'items' => $items];
}

function br_op_info(string $path, array $p): array {
    if (!file_exists($path)) br_respond(404, ['error' => 'not_found']);
    return ['path' => br_rel($path), 'exists' => true,
            'is_dir' => is_dir($path), 'is_file' => is_file($path),
            'size' => is_file($path) ? filesize($path) : 0,
            'mtime' => filemtime($path), 'perms' => substr(sprintf('%o', fileperms($path)), -4)];
}

function br_op_mkdir(string $path, array $p): array {
    if (is_dir($path)) return ['path' => br_rel($path), 'created' => false, 'reason' => 'already_exists'];
    if (!@mkdir($path, 0755, true)) br_respond(500, ['error' => 'mkdir_failed']);
    return ['path' => br_rel($path), 'created' => true];
}

function br_op_rename(string $path, array $p): array {
    $to_rel = (string)($p['to'] ?? '');
    if ($to_rel === '') br_respond(400, ['error' => 'missing_to_path']);
    $to_abs = br_validate_path($to_rel, 'write');
    if (!file_exists($path)) br_respond(404, ['error' => 'source_not_found']);
    if (!@rename($path, $to_abs)) br_respond(500, ['error' => 'rename_failed']);
    return ['from' => br_rel($path), 'to' => br_rel($to_abs), 'renamed' => true];
}

// =============================================================================
// HELPERS: CLIENT IP, REL PATH, JSON I/O, AUDIT, RESPONSE
// =============================================================================

function br_client_ip(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) return (string)$_SERVER['HTTP_X_REAL_IP'];
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function br_rel(string $abs): string {
    global $BR_ABSPATH;
    return str_starts_with($abs, $BR_ABSPATH) ? substr($abs, strlen($BR_ABSPATH)) : $abs;
}

function br_read_json(string $file): array {
    if (!is_file($file)) return [];
    $raw = (string)@file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function br_write_json(string $file, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    @file_put_contents($file, $json, LOCK_EX);
    @chmod($file, 0600);
}

function br_audit(string $event, string $ip, ?string $op, ?string $path, bool $success, string $detail = '', ?int $bytes = null): void {
    global $BR_AUDIT_LOG, $BR_AUDIT_FALLBACK;
    $entry = ['ts' => gmdate('c'), 'event' => $event, 'ip' => $ip, 'op' => $op,
              'path' => $path, 'success' => $success, 'detail' => $detail, 'bytes' => $bytes];
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    if (!@file_put_contents($BR_AUDIT_LOG, $line, FILE_APPEND | LOCK_EX)) {
        @file_put_contents($BR_AUDIT_FALLBACK, $line, FILE_APPEND | LOCK_EX);
    }
}

function br_respond(int $status, array $payload): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
