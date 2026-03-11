<?php
/**
 * BugLens Custom Post Type registration.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BugLens_CPT {

    public const POST_TYPE = 'buglens_report';

    /**
     * Register the buglens_report CPT and its post meta fields.
     */
    public static function register(): void {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'               => __( 'Bug Reports', 'buglens' ),
                'singular_name'      => __( 'Bug Report', 'buglens' ),
                'add_new_item'       => __( 'Add New Bug Report', 'buglens' ),
                'edit_item'          => __( 'Edit Bug Report', 'buglens' ),
                'view_item'          => __( 'View Bug Report', 'buglens' ),
                'search_items'       => __( 'Search Bug Reports', 'buglens' ),
                'not_found'          => __( 'No bug reports found', 'buglens' ),
                'not_found_in_trash' => __( 'No bug reports found in Trash', 'buglens' ),
                'all_items'          => __( 'All Reports', 'buglens' ),
                'menu_name'          => __( 'Bug Reports', 'buglens' ),
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => false,
            'supports'        => [ 'title', 'editor' ],
            'capability_type' => 'post',
            'show_in_rest'    => false, // We register our own REST endpoints.
        ] );

        // String meta fields — sanitize as text.
        $string_fields = [
            '_buglens_page_url',
            '_buglens_selector',
            '_buglens_parent_chain',
            '_buglens_outer_html',
            '_buglens_inner_text',
            '_buglens_context',
            '_buglens_overlay_selector',
            '_buglens_browser',
            '_buglens_viewport',
        ];

        foreach ( $string_fields as $key ) {
            register_post_meta( self::POST_TYPE, $key, [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => false,
                'sanitize_callback' => 'sanitize_text_field',
            ] );
        }

        // JSON string meta fields — validate as JSON.
        $json_fields = [
            '_buglens_computed_styles',
            '_buglens_bounding_box',
            '_buglens_console_errors',
        ];

        foreach ( $json_fields as $key ) {
            register_post_meta( self::POST_TYPE, $key, [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => false,
                'sanitize_callback' => [ self::class, 'sanitize_json_string' ],
            ] );
        }

        // Integer meta field.
        register_post_meta( self::POST_TYPE, '_buglens_screenshot_id', [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'absint',
        ] );

        // Status meta field — restricted values.
        register_post_meta( self::POST_TYPE, '_buglens_status', [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'default'           => 'open',
            'sanitize_callback' => [ self::class, 'sanitize_status' ],
        ] );
    }

    /**
     * Sanitize a JSON string. Returns the string if valid JSON, empty string otherwise.
     */
    public static function sanitize_json_string( string $value ): string {
        if ( $value === '' ) {
            return '';
        }

        // Attempt to decode — if valid JSON, return the original string.
        $decoded = json_decode( $value, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $value;
        }

        // Try unslashed version (WordPress sometimes double-slashes).
        $unslashed = wp_unslash( $value );
        $decoded   = json_decode( $unslashed, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $unslashed;
        }

        return '';
    }

    /**
     * Sanitize report status to one of the allowed values.
     */
    public static function sanitize_status( string $value ): string {
        $allowed = [ 'open', 'in_progress', 'resolved', 'closed' ];
        return in_array( $value, $allowed, true ) ? $value : 'open';
    }
}
