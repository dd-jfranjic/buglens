<?php
/**
 * BugLens Bridge Verify — bulk file existence + sha256 verification endpoint.
 *
 * POST /fs/verify  — body {files: [{path, sha256?}, ...]}
 *   Returns per-file: {path, exists, size, sha256, matches_expected?}
 *
 * Split out from BugLens_Bridge_Batch (Task 2 of v3.2.0) per LEGO < 200 LoC rule.
 * Conceptually distinct: batch = write transaction, verify = read assertion.
 *
 * @package BugLens
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BugLens_Bridge_Verify {

    const NAMESPACE = 'buglens/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/fs/verify', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'verify' ],
            'permission_callback' => function ( $req ) {
                return BugLens_Bridge_Security::check_permission( $req, 'read' );
            },
        ] );
    }

    public static function verify( WP_REST_Request $request ): WP_REST_Response {
        $files = $request->get_param( 'files' );
        if ( ! is_array( $files ) || empty( $files ) ) {
            return new WP_REST_Response( [ 'error' => 'missing_files' ], 400 );
        }
        $results = [];
        foreach ( $files as $f ) {
            $path = (string) ( $f['path'] ?? '' );
            $expected = $f['sha256'] ?? null;
            $abs = BugLens_Bridge_Security::validate_path( $path, 'read' );
            if ( is_wp_error( $abs ) ) {
                $results[] = [ 'path' => $path, 'error' => $abs->get_error_message() ];
                continue;
            }
            $results[] = self::verify_one( $abs, $path, $expected );
        }
        return new WP_REST_Response( [ 'files' => $results ], 200 );
    }

    private static function verify_one( string $abs, string $path, ?string $expected ): array {
        if ( ! is_file( $abs ) ) return [ 'path' => $path, 'exists' => false ];
        $sha = @hash_file( 'sha256', $abs );
        if ( $sha === false ) return [ 'path' => $path, 'exists' => true, 'error' => 'hash_read_failed' ];
        $r = [ 'path' => $path, 'exists' => true, 'size' => filesize( $abs ), 'sha256' => $sha ];
        if ( $expected !== null ) $r['matches_expected'] = hash_equals( (string) $expected, $sha );
        return $r;
    }
}
