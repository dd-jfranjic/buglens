<?php
/**
 * BugLens Bridge Health — WP boot diagnostics + dry-run path preflight.
 *
 * /fs/health (GET, no auth)
 *   Returns: wp_loaded, php/mysql/wp versions, memory, plugin counts,
 *            last error_log lines, response time. AI agent can check
 *            "is WP boot OK?" before attempting risky operations.
 *
 * /fs/preflight (POST, auth required)
 *   Body: {paths: [{path, mode?}, ...]}  mode = 'read' or 'write' (default 'write')
 *   Returns per-path: {path, resolves, parent_exists, writable, blocked, error?}
 *   Pure dry-run — no write occurs.
 *
 * @package BugLens
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BugLens_Bridge_Health {

    const NAMESPACE = 'buglens/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/fs/health', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'health' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::NAMESPACE, '/fs/preflight', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'preflight' ],
            'permission_callback' => function ( $req ) {
                return BugLens_Bridge_Security::check_permission( $req, 'read' );
            },
        ] );
    }

    public static function health( WP_REST_Request $request ): WP_REST_Response {
        $start = microtime( true );
        global $wp_version, $wpdb;
        $payload = [
            'wp_loaded'           => function_exists( 'wp_loaded' ),
            'wp_version'          => $wp_version ?? 'unknown',
            'php_version'         => PHP_VERSION,
            'mysql_version'       => isset( $wpdb ) ? $wpdb->db_version() : 'unknown',
            'memory_used_mb'      => round( memory_get_usage( true ) / 1024 / 1024, 1 ),
            'memory_peak_mb'      => round( memory_get_peak_usage( true ) / 1024 / 1024, 1 ),
            'memory_limit'        => ini_get( 'memory_limit' ),
            'active_plugins'      => count( (array) get_option( 'active_plugins', [] ) ),
            'mu_plugins'          => count( wp_get_mu_plugins() ),
            'last_error_log'      => self::tail_error_log( 5 ),
            'rescue_status'       => class_exists( 'BugLens_Bridge_Rescue_Security' )
                                       ? BugLens_Bridge_Rescue_Security::status()
                                       : null,
            'request_time_ms'     => round( ( microtime( true ) - $start ) * 1000, 1 ),
        ];
        return new WP_REST_Response( $payload, 200 );
    }

    public static function preflight( WP_REST_Request $request ): WP_REST_Response {
        $paths = $request->get_param( 'paths' );
        if ( ! is_array( $paths ) || empty( $paths ) ) {
            return new WP_REST_Response( [ 'error' => 'missing_paths' ], 400 );
        }
        $results = [];
        foreach ( $paths as $entry ) {
            $path = (string) ( $entry['path'] ?? '' );
            $mode = (string) ( $entry['mode'] ?? 'write' );
            $results[] = self::preflight_one( $path, $mode );
        }
        return new WP_REST_Response( [ 'paths' => $results ], 200 );
    }

    private static function preflight_one( string $path, string $mode ): array {
        $abs = BugLens_Bridge_Security::validate_path( $path, $mode );
        if ( is_wp_error( $abs ) ) {
            return [ 'path' => $path, 'mode' => $mode,
                     'blocked' => true, 'reason' => $abs->get_error_message() ];
        }
        $parent = dirname( $abs );
        return [
            'path'           => $path,
            'mode'           => $mode,
            'blocked'        => false,
            'resolves'       => $abs,
            'exists'         => file_exists( $abs ),
            'parent_exists'  => is_dir( $parent ),
            'parent_writable'=> is_dir( $parent ) && is_writable( $parent ),
            'is_file'        => is_file( $abs ),
            'is_dir'         => is_dir( $abs ),
            'writable'       => file_exists( $abs ) ? is_writable( $abs ) : null,
        ];
    }

    /**
     * Return last N lines of PHP error log (best-effort, no fatal if unreadable).
     */
    private static function tail_error_log( int $lines ): array {
        $log = ini_get( 'error_log' );
        if ( ! $log || ! is_file( $log ) || ! is_readable( $log ) ) return [];
        $size = filesize( $log );
        $read = min( $size, 8192 );
        $fp = @fopen( $log, 'rb' );
        if ( ! $fp ) return [];
        fseek( $fp, max( 0, $size - $read ) );
        $tail = (string) fread( $fp, $read );
        fclose( $fp );
        $all = array_filter( explode( "\n", $tail ) );
        return array_slice( $all, -$lines );
    }
}
