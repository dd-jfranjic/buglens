<?php
/**
 * Plugin Name: BugLens – Visual Bug Reporter for AI Agents
 * Plugin URI:  https://2klika.hr
 * Description: Visually select elements, capture screenshots, and create AI-optimized bug reports.
 * Version:     3.0.0
 * Author:      2klika
 * Author URI:  https://2klika.hr
 * License:     GPL v2 or later
 * Text Domain: buglens
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BUGLENS_VERSION', '3.0.0' );
define( 'BUGLENS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUGLENS_URL', plugin_dir_url( __FILE__ ) );
define( 'BUGLENS_BASENAME', plugin_basename( __FILE__ ) );

// Core includes.
require_once BUGLENS_DIR . 'includes/class-buglens-cpt.php';
require_once BUGLENS_DIR . 'includes/class-buglens-rest-api.php';
require_once BUGLENS_DIR . 'includes/class-buglens-export.php';
require_once BUGLENS_DIR . 'includes/class-buglens-widget.php';
require_once BUGLENS_DIR . 'includes/class-buglens-admin.php';
require_once BUGLENS_DIR . 'includes/class-buglens-bridge-security.php';
require_once BUGLENS_DIR . 'includes/class-buglens-bridge.php';

// Initialize.
add_action( 'init', [ BugLens_CPT::class, 'register' ] );
add_action( 'rest_api_init', [ BugLens_REST_API::class, 'register' ] );
add_action( 'rest_api_init', [ BugLens_Bridge::class, 'register' ] );
add_action( 'wp_enqueue_scripts', [ BugLens_Widget::class, 'maybe_enqueue' ] );

// Admin.
add_action( 'admin_menu', [ BugLens_Admin::class, 'register_menu' ] );
add_action( 'admin_init', [ BugLens_Admin::class, 'register_settings' ] );
add_action( 'admin_enqueue_scripts', [ BugLens_Admin::class, 'enqueue_admin_assets' ] );
add_action( 'wp_ajax_buglens_regenerate_key', [ BugLens_Admin::class, 'ajax_regenerate_key' ] );


/**
 * Cleanup on report delete — remove screenshot + export files before post is gone.
 */
add_action( 'before_delete_post', function ( int $post_id ): void {
    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== BugLens_CPT::POST_TYPE ) {
        return;
    }

    $upload_dir  = wp_upload_dir();
    $buglens_dir = $upload_dir['basedir'] . '/buglens';

    // Delete screenshot attachment + file.
    $screenshot_id = get_post_meta( $post_id, '_buglens_screenshot_id', true );
    if ( $screenshot_id ) {
        wp_delete_attachment( (int) $screenshot_id, true );
    }

    // Delete MD export file.
    $md_file = $buglens_dir . '/report-' . $post_id . '.md';
    if ( file_exists( $md_file ) ) {
        wp_delete_file( $md_file );
    }
} );

/**
 * Rebuild JSON index after report is fully deleted from DB.
 */
add_action( 'deleted_post', function ( int $post_id, WP_Post $post ): void {
    if ( $post->post_type !== BugLens_CPT::POST_TYPE ) {
        return;
    }
    if ( class_exists( 'BugLens_Export' ) ) {
        BugLens_Export::export_index();
    }
}, 10, 2 );

/**
 * Activation hook — create upload directory, security index files, and default options.
 */
register_activation_hook( __FILE__, function (): void {
    $upload_dir  = wp_upload_dir();
    $buglens_dir = $upload_dir['basedir'] . '/buglens';
    $screenshots = $buglens_dir . '/screenshots';

    if ( ! file_exists( $screenshots ) ) {
        wp_mkdir_p( $screenshots );
    }

    // Create index.php in upload dirs to prevent directory listing.
    $index_content = "<?php\n// Silence is golden.\n";
    foreach ( [ $buglens_dir, $screenshots ] as $dir ) {
        $index_file = $dir . '/index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, $index_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
    }

    // Generate API key if not exists.
    if ( ! get_option( 'buglens_api_key' ) ) {
        update_option( 'buglens_api_key', wp_generate_password( 40, false ) );
    }

    // Default settings.
    if ( ! get_option( 'buglens_settings' ) ) {
        update_option( 'buglens_settings', [
            'fab_position'    => 'bottom-right',
            'visibility'      => 'admins',
            'color'           => '#F2C700',
            'outerhtml_limit' => 5000,
            'capture_console' => true,
        ] );
    }

    // Flush rewrite rules for CPT.
    BugLens_CPT::register();
    flush_rewrite_rules();
} );

/**
 * Deactivation hook — flush rewrite rules to clean up CPT rewrites.
 */
register_deactivation_hook( __FILE__, function (): void {
    flush_rewrite_rules();
} );
