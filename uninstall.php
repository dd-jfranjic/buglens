<?php
/**
 * BugLens Uninstall — clean up all plugin data when uninstalled.
 *
 * This file runs when the plugin is deleted via the WordPress admin.
 * No i18n needed — runs without frontend context.
 *
 * @package BugLens
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete all bug reports.
$buglens_reports = get_posts( [
    'post_type'   => 'buglens_report',
    'numberposts' => -1,
    'fields'      => 'ids',
    'post_status' => 'any',
] );

foreach ( $buglens_reports as $buglens_id ) {
    wp_delete_post( $buglens_id, true );
}

// Delete orphaned post meta for any reports that may have been missed.
$buglens_meta_keys = [
    '_buglens_page_url',
    '_buglens_selector',
    '_buglens_parent_chain',
    '_buglens_outer_html',
    '_buglens_inner_text',
    '_buglens_computed_styles',
    '_buglens_bounding_box',
    '_buglens_context',
    '_buglens_overlay_selector',
    '_buglens_browser',
    '_buglens_viewport',
    '_buglens_console_errors',
    '_buglens_screenshot_id',
    '_buglens_status',
];

foreach ( $buglens_meta_keys as $buglens_meta_key ) {
    delete_post_meta_by_key( $buglens_meta_key );
}

// Delete options.
delete_option( 'buglens_api_key' );
delete_option( 'buglens_settings' );

// Delete upload directory recursively.
$buglens_upload_dir = wp_upload_dir();
$buglens_dir        = $buglens_upload_dir['basedir'] . '/buglens';

if ( is_dir( $buglens_dir ) ) {
    $buglens_it    = new RecursiveDirectoryIterator( $buglens_dir, RecursiveDirectoryIterator::SKIP_DOTS );
    $buglens_files = new RecursiveIteratorIterator( $buglens_it, RecursiveIteratorIterator::CHILD_FIRST );

    foreach ( $buglens_files as $buglens_file ) {
        if ( $buglens_file->isDir() ) {
            rmdir( $buglens_file->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        } else {
            wp_delete_file( $buglens_file->getRealPath() );
        }
    }

    rmdir( $buglens_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
