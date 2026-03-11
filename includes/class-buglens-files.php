<?php
/**
 * BugLens Files — AJAX file browser for the BugLens uploads directory.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BugLens_Files {

    /**
     * Get the root BugLens uploads directory.
     */
    private static function get_root(): string {
        return wp_upload_dir()['basedir'] . '/buglens';
    }

    /**
     * Resolve a relative path to a safe absolute path within the BugLens root.
     * Returns false if the path escapes the root directory.
     */
    private static function resolve_safe_path( string $relative_path ): string|false {
        $root = self::get_root();
        $full = $root . '/' . ltrim( $relative_path, '/' );
        $real = realpath( $full );

        if ( $real === false ) {
            $parent = realpath( dirname( $full ) );
            if ( $parent === false || ! str_starts_with( $parent, $root ) ) {
                return false;
            }
            return $parent . '/' . basename( $full );
        }

        if ( ! str_starts_with( $real, $root ) ) {
            return false;
        }
        return $real;
    }

    /**
     * AJAX: List directory contents.
     */
    public static function ajax_list(): void {
        check_ajax_referer( 'buglens_files', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $path      = sanitize_text_field( wp_unslash( $_POST['path'] ?? '/' ) );
        $full_path = self::resolve_safe_path( $path );

        if ( ! $full_path || ! is_dir( $full_path ) ) {
            wp_send_json_error( __( 'Invalid directory.', 'buglens' ) );
        }

        $root  = self::get_root();
        $items = [];

        foreach ( scandir( $full_path ) as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            if ( str_starts_with( $item, '.terminal-' ) ) {
                continue;
            }
            // Skip WordPress-generated thumbnail variants (e.g. image-300x200.png).
            if ( preg_match( '/-\d+x\d+\.(png|jpe?g|gif|webp)$/i', $item ) ) {
                continue;
            }

            $item_path = $full_path . '/' . $item;
            $is_dir    = is_dir( $item_path );
            $items[]   = [
                'name'     => $item,
                'path'     => str_replace( $root, '', $item_path ),
                'is_dir'   => $is_dir,
                'size'     => $is_dir ? null : filesize( $item_path ),
                'modified' => filemtime( $item_path ),
                'type'     => $is_dir ? 'directory' : strtolower( pathinfo( $item, PATHINFO_EXTENSION ) ),
            ];
        }

        usort( $items, function ( array $a, array $b ): int {
            if ( $a['is_dir'] !== $b['is_dir'] ) {
                return $b['is_dir'] - $a['is_dir'];
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        wp_send_json_success( [
            'items' => $items,
            'path'  => str_replace( $root, '', $full_path ) ?: '/',
        ] );
    }

    /**
     * AJAX: Read a file's contents.
     */
    public static function ajax_read(): void {
        check_ajax_referer( 'buglens_files', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $path      = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
        $full_path = self::resolve_safe_path( $path );

        if ( ! $full_path || ! is_file( $full_path ) ) {
            wp_send_json_error( __( 'File not found.', 'buglens' ) );
        }

        $ext        = strtolower( pathinfo( $full_path, PATHINFO_EXTENSION ) );
        $image_exts = [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ];

        if ( in_array( $ext, $image_exts, true ) ) {
            $upload_dir = wp_upload_dir();
            $url        = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $full_path );
            wp_send_json_success( [
                'type' => 'image',
                'url'  => $url,
                'name' => basename( $full_path ),
                'size' => filesize( $full_path ),
            ] );
        }

        $size = filesize( $full_path );
        if ( $size > 2 * 1024 * 1024 ) {
            wp_send_json_error( __( 'File too large (max 2 MB).', 'buglens' ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $content  = file_get_contents( $full_path );
        $mode_map = [
            'json' => 'application/json',
            'md'   => 'text/x-markdown',
            'html' => 'text/html',
            'php'  => 'application/x-httpd-php',
            'css'  => 'text/css',
            'js'   => 'text/javascript',
            'txt'  => 'text/plain',
        ];

        wp_send_json_success( [
            'type'    => 'text',
            'content' => $content,
            'name'    => basename( $full_path ),
            'size'    => $size,
            'mode'    => $mode_map[ $ext ] ?? 'text/plain',
        ] );
    }

    /**
     * AJAX: Write content to a file.
     */
    public static function ajax_write(): void {
        check_ajax_referer( 'buglens_files', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $path      = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File content must preserve original formatting (code files). Path is validated via resolve_safe_path().
        $content   = wp_unslash( $_POST['content'] ?? '' );
        $full_path = self::resolve_safe_path( $path );

        if ( ! $full_path || ! file_exists( $full_path ) ) {
            wp_send_json_error( __( 'Invalid path.', 'buglens' ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $result = file_put_contents( $full_path, $content );
        if ( $result === false ) {
            wp_send_json_error( __( 'Failed to write file.', 'buglens' ) );
        }
        wp_send_json_success( [ 'bytes' => $result ] );
    }

    /**
     * AJAX: Download a file.
     */
    public static function ajax_download(): void {
        check_ajax_referer( 'buglens_files', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $path      = sanitize_text_field( wp_unslash( $_GET['path'] ?? '' ) );
        $full_path = self::resolve_safe_path( $path );

        if ( ! $full_path || ! is_file( $full_path ) ) {
            wp_send_json_error( __( 'File not found.', 'buglens' ) );
        }

        header( 'Content-Type: ' . ( mime_content_type( $full_path ) ?: 'application/octet-stream' ) );
        $filename = sanitize_file_name( basename( $full_path ) );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $full_path ) );
        readfile( $full_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }
}
