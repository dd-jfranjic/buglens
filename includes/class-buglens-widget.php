<?php
/**
 * BugLens Frontend Widget — conditionally enqueues the bug reporter on the front end.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BugLens_Widget {

    /**
     * Conditionally enqueue the frontend widget based on visibility settings.
     */
    public static function maybe_enqueue(): void {
        if ( is_admin() ) {
            return;
        }

        $settings   = get_option( 'buglens_settings', [] );
        $visibility = $settings['visibility'] ?? 'admins';

        // Check visibility setting.
        if ( $visibility === 'admins' && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( $visibility === 'logged_in' && ! is_user_logged_in() ) {
            return;
        }
        // 'everyone' — always show.

        wp_enqueue_style(
            'buglens-widget',
            BUGLENS_URL . 'public/css/buglens-widget.css',
            [],
            BUGLENS_VERSION
        );

        wp_enqueue_script(
            'buglens-widget',
            BUGLENS_URL . 'public/js/buglens-widget.js',
            [],
            BUGLENS_VERSION,
            true
        );

        wp_localize_script( 'buglens-widget', 'buglensConfig', [
            'restUrl'        => rest_url( 'buglens/v1/' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'position'       => $settings['fab_position'] ?? 'bottom-right',
            'color'          => $settings['color'] ?? '#F2C700',
            'maxHtml'        => (int) ( $settings['outerhtml_limit'] ?? 5000 ),
            'captureConsole' => (bool) ( $settings['capture_console'] ?? true ),
        ] );

        // i18n string for the console warning (instead of hardcoding in widget JS).
        wp_add_inline_script(
            'buglens-widget',
            'window.buglensI18n = ' . wp_json_encode( [
                'consoleWarn' => __( 'BugLens: Console error capture is active.', 'buglens' ),
            ] ) . ';',
            'before'
        );
    }
}
