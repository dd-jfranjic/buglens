<?php
/**
 * BugLens Bridge Rescue Security — installs rescue endpoint + manages secret/slug/state.
 *
 * Companion to rescue-template.php (standalone fs endpoint that survives WP fatal).
 * State location: wp-content/buglens-rescue-state/ (.htaccess Deny + index.php silencer).
 *   - secret-hash.txt  SHA256 hash of secret (plain hex)
 *   - url-slug.txt     32-char slug → wp-content/buglens-rescue-{slug}.php
 *
 * @package BugLens
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BugLens_Bridge_Rescue_Security {

    public static function state_dir(): string {
        return WP_CONTENT_DIR . '/buglens-rescue-state/';
    }
    public static function secret_hash_file(): string {
        return self::state_dir() . 'secret-hash.txt';
    }
    public static function slug_file(): string {
        return self::state_dir() . 'url-slug.txt';
    }
    public static function template_path(): string {
        return defined( 'BUGLENS_DIR' )
            ? BUGLENS_DIR . 'rescue-template.php'
            : dirname( __DIR__ ) . '/rescue-template.php';
    }

    /**
     * Ensure state directory exists with .htaccess + index.php silencer.
     * Self-healing — call before any state file operation.
     *
     * @return bool True on success (or already exists).
     */
    public static function ensure_state_dir(): bool {
        $dir = self::state_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }
        $htaccess = $dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess,
                "# Auto-generated. Deny direct web access to rescue state files.\n" .
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order Deny,Allow\n  Deny from all\n</IfModule>\n"
            );
        }
        $index = $dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" );
        }
        return true;
    }

    /**
     * Install rescue endpoint. Idempotent — preserves existing slug if installed.
     * Cleans up orphan rescue-{old}.php files if slug rotated externally.
     *
     * @return string|WP_Error Slug on success.
     */
    public static function install_endpoint() {
        if ( ! self::ensure_state_dir() ) {
            return new WP_Error( 'rescue_state_dir', 'Cannot create state directory.' );
        }
        $template = self::template_path();
        if ( ! is_file( $template ) || ! is_readable( $template ) ) {
            return new WP_Error( 'rescue_template_missing', 'Rescue template not found.' );
        }
        $slug = self::get_slug();
        if ( $slug === null ) {
            $slug = self::generate_slug();
            file_put_contents( self::slug_file(), $slug );
            chmod( self::slug_file(), 0600 );
            self::cleanup_orphan_endpoints( $slug );
        }
        $target = WP_CONTENT_DIR . '/buglens-rescue-' . $slug . '.php';
        if ( ! copy( $template, $target ) ) {
            return new WP_Error( 'rescue_copy_failed', 'Cannot copy rescue template.' );
        }
        chmod( $target, 0644 );
        return $slug;
    }

    /**
     * Delete any wp-content/buglens-rescue-*.php files that don't match $keep_slug.
     * Prevents orphaned rescue endpoints from previous installs sa stale state.
     */
    private static function cleanup_orphan_endpoints( string $keep_slug ): void {
        $keep = WP_CONTENT_DIR . '/buglens-rescue-' . $keep_slug . '.php';
        foreach ( glob( WP_CONTENT_DIR . '/buglens-rescue-*.php' ) ?: [] as $f ) {
            if ( realpath( $f ) !== realpath( $keep ) ) @unlink( $f );
        }
    }

    public static function generate_slug(): string {
        return bin2hex( random_bytes( 16 ) );
    }
    public static function generate_secret(): string {
        return bin2hex( random_bytes( 32 ) );
    }
    public static function get_slug(): ?string {
        $f = self::slug_file();
        if ( ! is_file( $f ) || ! is_readable( $f ) ) return null;
        $s = trim( (string) file_get_contents( $f ) );
        return ( strlen( $s ) === 32 && ctype_xdigit( $s ) ) ? $s : null;
    }

    /**
     * Get SHA256 hash of stored secret (constant override > file). Null if unset.
     */
    public static function get_rescue_secret(): ?string {
        if ( defined( 'BUGLENS_RESCUE_KEY' ) ) {
            $k = BUGLENS_RESCUE_KEY;
            if ( is_string( $k ) && strlen( $k ) >= 16 ) return hash( 'sha256', $k );
        }
        $f = self::secret_hash_file();
        if ( ! is_file( $f ) || ! is_readable( $f ) ) return null;
        $h = trim( (string) file_get_contents( $f ) );
        return ( strlen( $h ) === 64 && ctype_xdigit( $h ) ) ? $h : null;
    }

    /**
     * Generate + store new secret. Returns PLAIN secret (show once, never again).
     *
     * @return string|WP_Error Plain secret on success.
     */
    public static function set_rescue_secret() {
        if ( ! self::ensure_state_dir() ) {
            return new WP_Error( 'rescue_state_dir', 'Cannot create state directory.' );
        }
        $secret = self::generate_secret();
        $hash   = hash( 'sha256', $secret );
        if ( file_put_contents( self::secret_hash_file(), $hash, LOCK_EX ) === false ) {
            return new WP_Error( 'rescue_secret_save', 'Cannot write secret hash file.' );
        }
        chmod( self::secret_hash_file(), 0600 );
        return $secret;
    }

    /** Disable rescue (rescue.php postaje passive → vraća 503). */
    public static function disable_rescue(): bool {
        $f = self::secret_hash_file();
        return is_file( $f ) ? @unlink( $f ) : true;
    }

    /** Status snapshot for admin UI. */
    public static function status(): array {
        $slug   = self::get_slug();
        $secret = self::get_rescue_secret();
        $url    = $slug ? site_url( '/wp-content/buglens-rescue-' . $slug . '.php' ) : null;
        $installed = $slug !== null && file_exists( WP_CONTENT_DIR . '/buglens-rescue-' . $slug . '.php' );
        $source = defined( 'BUGLENS_RESCUE_KEY' ) ? 'wp-config constant' : ( $secret ? 'state file hash' : null );
        return [
            'installed'        => $installed,
            'secret_set'       => $secret !== null,
            'state_dir_exists' => is_dir( self::state_dir() ),
            'slug'             => $slug,
            'rescue_url'       => $url,
            'source'           => $source,
        ];
    }
}
