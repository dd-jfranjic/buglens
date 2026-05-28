<?php
/**
 * BugLens Bridge Batch — atomic multi-file write.
 *
 * Solves the problem demonstrated 2026-05-28: partial deploys where N files
 * upload successfully but file N+1 fails, leaving WP in fatal state.
 *
 * /fs/batch-write — all-or-nothing write of N files (max 50, 10 MB total)
 *   Phases: validate → stage → verify-staged → atomic-commit → cleanup
 *   Any phase failure → rollback (cleanup staging) → 0 changes on disk
 *   Mid-rename failure returns `partial_commit` with list of committed files.
 *
 * Companion: BugLens_Bridge_Verify handles /fs/verify endpoint.
 *
 * Staging location: wp-content/uploads/_buglens-batch-staging/{nonce}/
 *   Same filesystem as targets → rename() is atomic per file.
 *
 * @package BugLens
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BugLens_Bridge_Batch {

    const NAMESPACE = 'buglens/v1';
    const MAX_BATCH_FILES       = 50;
    const MAX_BATCH_TOTAL_BYTES = 10 * 1024 * 1024;
    const MAX_INDIVIDUAL_BYTES  = 2 * 1024 * 1024;

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/fs/batch-write', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'batch_write' ],
            'permission_callback' => function ( $req ) {
                return BugLens_Bridge_Security::check_permission( $req, 'write' );
            },
        ] );
    }

    /**
     * Batch write — atomic per-file rename. Mid-rename failure reports which
     * files already committed (client can recover via /fs/verify + /fs/delete).
     */
    public static function batch_write( WP_REST_Request $request ): WP_REST_Response {
        $files = $request->get_param( 'files' );
        foreach ( [ self::validate_batch_input( $files ), self::validate_paths( $files ) ] as $r ) {
            if ( is_wp_error( $r ) ) {
                $d = $r->get_error_data();
                $s = is_array( $d ) && isset( $d['status'] ) ? (int) $d['status'] : 500;
                return new WP_REST_Response( [ 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ], $s );
            }
        }
        $validated = self::validate_paths( $files );
        $staging = self::prepare_staging_dir();
        if ( is_wp_error( $staging ) ) {
            return new WP_REST_Response( [ 'error' => 'staging_failed', 'message' => $staging->get_error_message() ], 500 );
        }
        try {
            self::stage_files( $validated, $staging );
            self::verify_staged( $validated, $staging );
            $written = self::atomic_commit( $validated, $staging );
            self::cleanup_staging( $staging );
            return new WP_REST_Response( [ 'written' => count( $written ), 'files' => $written ], 200 );
        } catch ( \Throwable $t ) {
            self::cleanup_staging( $staging );
            $msg = json_decode( $t->getMessage(), true );
            return new WP_REST_Response( is_array( $msg )
                ? [ 'error' => 'partial_commit' ] + $msg
                : [ 'error' => 'batch_failed', 'message' => $t->getMessage() ], 500 );
        }
    }

    private static function validate_batch_input( $files ) {
        if ( ! is_array( $files ) || empty( $files ) ) {
            return new WP_Error( 'missing_files', 'Param "files" must be a non-empty array.', [ 'status' => 400 ] );
        }
        if ( count( $files ) > self::MAX_BATCH_FILES ) {
            return new WP_Error( 'too_many_files',
                sprintf( 'Max %d files per batch.', self::MAX_BATCH_FILES ),
                [ 'status' => 413 ] );
        }
        $total = 0;
        foreach ( $files as $f ) {
            $bytes = strlen( (string) ( $f['content'] ?? '' ) );
            if ( $bytes > self::MAX_INDIVIDUAL_BYTES ) {
                return new WP_Error( 'file_too_large',
                    sprintf( 'File %s exceeds %d bytes.', $f['path'] ?? '?', self::MAX_INDIVIDUAL_BYTES ),
                    [ 'status' => 413 ] );
            }
            $total += $bytes;
        }
        if ( $total > self::MAX_BATCH_TOTAL_BYTES ) {
            return new WP_Error( 'batch_too_large',
                sprintf( 'Total %d bytes exceeds %d.', $total, self::MAX_BATCH_TOTAL_BYTES ),
                [ 'status' => 413 ] );
        }
        return true;
    }

    private static function validate_paths( array $files ) {
        $out = [];
        foreach ( $files as $f ) {
            $path = (string) ( $f['path'] ?? '' );
            $abs = BugLens_Bridge_Security::validate_path( $path, 'write' );
            if ( is_wp_error( $abs ) ) {
                return new WP_Error( 'invalid_path',
                    sprintf( 'Path %s: %s', $path, $abs->get_error_message() ),
                    [ 'status' => 400 ] );
            }
            $out[] = [ 'path' => $path, 'abs' => $abs,
                       'content' => (string) ( $f['content'] ?? '' ),
                       'sha256' => $f['sha256'] ?? null ];
        }
        return $out;
    }

    private static function prepare_staging_dir() {
        $base = wp_upload_dir()['basedir'] . '/_buglens-batch-staging';
        if ( ! is_dir( $base ) ) wp_mkdir_p( $base );
        $nonce = bin2hex( random_bytes( 8 ) );
        $dir = $base . '/' . $nonce;
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'staging_failed', 'Cannot create staging directory.', [ 'status' => 500 ] );
        }
        return $dir;
    }

    /**
     * @throws \RuntimeException on any failure (caught by batch_write).
     */
    private static function stage_files( array $validated, string $staging ): void {
        foreach ( $validated as $f ) {
            $stage_path = $staging . '/' . str_replace( '/', '_SLASH_', $f['path'] );
            if ( file_put_contents( $stage_path, $f['content'], LOCK_EX ) === false ) {
                throw new \RuntimeException( "Stage write failed for {$f['path']}" );
            }
        }
    }

    /** @throws \RuntimeException if any sha256 mismatch. */
    private static function verify_staged( array $validated, string $staging ): void {
        foreach ( $validated as $f ) {
            if ( $f['sha256'] === null ) continue;
            $stage_path = $staging . '/' . str_replace( '/', '_SLASH_', $f['path'] );
            if ( hash_file( 'sha256', $stage_path ) !== $f['sha256'] ) {
                throw new \RuntimeException( "SHA256 mismatch for {$f['path']}" );
            }
        }
    }

    /**
     * Per-file atomic rename. Mid-failure throws JSON-encoded message
     * including list of already-committed files (recoverable state info).
     *
     * @return array<int,array{path:string,bytes:int,sha256:string}>
     */
    private static function atomic_commit( array $validated, string $staging ): array {
        $written = [];
        foreach ( $validated as $f ) {
            $stage_path = $staging . '/' . str_replace( '/', '_SLASH_', $f['path'] );
            $parent = dirname( $f['abs'] );
            if ( ! is_dir( $parent ) ) @wp_mkdir_p( $parent );
            if ( ! @rename( $stage_path, $f['abs'] ) ) {
                throw new \RuntimeException( (string) json_encode( [
                    'rename_failed' => $f['path'],
                    'already_committed' => array_column( $written, 'path' ),
                ] ) );
            }
            @chmod( $f['abs'], 0644 );
            $written[] = [ 'path' => $f['path'], 'bytes' => strlen( $f['content'] ),
                           'sha256' => hash( 'sha256', $f['content'] ) ];
        }
        return $written;
    }

    private static function cleanup_staging( string $dir ): void {
        if ( ! is_dir( $dir ) ) return;
        foreach ( glob( $dir . '/*' ) ?: [] as $f ) @unlink( $f );
        @rmdir( $dir );
    }
}
