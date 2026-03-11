<?php
/**
 * BugLens REST API endpoints.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BugLens_REST_API {

    public const REST_NAMESPACE = 'buglens/v1';

    /**
     * Register all REST routes.
     */
    public static function register(): void {
        register_rest_route( self::REST_NAMESPACE, '/reports', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_reports' ],
                'permission_callback' => [ self::class, 'check_read_permission' ],
                'args'                => [
                    'per_page' => [
                        'type'              => 'integer',
                        'default'           => 50,
                        'minimum'           => 1,
                        'maximum'           => 100,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'page' => [
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'status' => [
                        'type'              => 'string',
                        'enum'              => [ 'open', 'in_progress', 'resolved', 'closed' ],
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'create_report' ],
                'permission_callback' => [ self::class, 'check_create_permission' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/reports/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_report' ],
                'permission_callback' => [ self::class, 'check_read_permission' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ self::class, 'update_report' ],
                'permission_callback' => [ self::class, 'check_write_permission' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'status' => [
                        'type'              => 'string',
                        'enum'              => [ 'open', 'in_progress', 'resolved', 'closed' ],
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete_report' ],
                'permission_callback' => [ self::class, 'check_write_permission' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/reports/(?P<id>\d+)/screenshot', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_screenshot' ],
                'permission_callback' => [ self::class, 'check_read_permission' ],
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    /**
     * Check read permission via API key (timing-safe) or WP admin capability.
     */
    public static function check_read_permission( WP_REST_Request $request ): bool {
        $key = $request->get_header( 'X-BugLens-Key' )
            ?? $request->get_param( 'buglens_key' );

        $stored_key = get_option( 'buglens_api_key', '' );

        if ( is_string( $key ) && $key !== '' && is_string( $stored_key ) && $stored_key !== '' ) {
            if ( hash_equals( $stored_key, $key ) ) {
                return true;
            }
        }

        return current_user_can( 'manage_options' );
    }

    /**
     * Check write permission via API key (timing-safe) or WP admin capability.
     */
    public static function check_write_permission( WP_REST_Request $request ): bool {
        $key = $request->get_header( 'X-BugLens-Key' )
            ?? $request->get_param( 'buglens_key' );

        $stored_key = get_option( 'buglens_api_key', '' );

        if ( is_string( $key ) && $key !== '' && is_string( $stored_key ) && $stored_key !== '' ) {
            if ( hash_equals( $stored_key, $key ) ) {
                return true;
            }
        }

        return current_user_can( 'manage_options' );
    }

    /**
     * Check create permission — nonce for frontend widget, or API key.
     */
    public static function check_create_permission( WP_REST_Request $request ): bool {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( is_string( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            $settings   = get_option( 'buglens_settings', [] );
            $visibility = $settings['visibility'] ?? 'admins';

            if ( $visibility === 'everyone' ) {
                return true;
            }
            if ( $visibility === 'logged_in' && is_user_logged_in() ) {
                return true;
            }
            if ( $visibility === 'admins' && current_user_can( 'manage_options' ) ) {
                return true;
            }
        }

        // API key also works for create.
        return self::check_read_permission( $request );
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * List reports with pagination and optional status filter.
     */
    public static function list_reports( WP_REST_Request $request ): WP_REST_Response {
        $per_page = absint( $request->get_param( 'per_page' ) ?? 50 );
        $page     = absint( $request->get_param( 'page' ) ?? 1 );

        $args = [
            'post_type'      => BugLens_CPT::POST_TYPE,
            'posts_per_page' => min( $per_page, 100 ),
            'paged'          => max( $page, 1 ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $status_filter = $request->get_param( 'status' );
        if ( $status_filter ) {
            $args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'   => '_buglens_status',
                    'value' => sanitize_text_field( $status_filter ),
                ],
            ];
        }

        $query   = new WP_Query( $args );
        $reports = [];

        foreach ( $query->posts as $post ) {
            $reports[] = self::format_report_summary( $post );
        }

        return new WP_REST_Response( [
            'total'   => $query->found_posts,
            'pages'   => $query->max_num_pages,
            'reports' => $reports,
        ], 200 );
    }

    /**
     * Get a single report by ID.
     */
    public static function get_report( WP_REST_Request $request ): WP_REST_Response {
        $post = get_post( absint( $request['id'] ) );
        if ( ! $post || $post->post_type !== BugLens_CPT::POST_TYPE ) {
            return new WP_REST_Response(
                [ 'error' => __( 'Report not found.', 'buglens' ) ],
                404
            );
        }

        return new WP_REST_Response( self::format_report_full( $post ), 200 );
    }

    /**
     * Create a new bug report.
     */
    public static function create_report( WP_REST_Request $request ): WP_REST_Response {
        $title       = sanitize_text_field( $request->get_param( 'title' ) ?? __( 'Untitled Report', 'buglens' ) );
        $description = sanitize_textarea_field( $request->get_param( 'description' ) ?? '' );

        $post_id = wp_insert_post( [
            'post_type'    => BugLens_CPT::POST_TYPE,
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) {
            return new WP_REST_Response(
                /* translators: %s: error message from WordPress */
                [ 'error' => sprintf( __( 'Failed to create report: %s', 'buglens' ), $post_id->get_error_message() ) ],
                500
            );
        }

        // Save metadata.
        $meta_fields = [
            'page_url', 'selector', 'parent_chain', 'outer_html', 'inner_text',
            'computed_styles', 'bounding_box', 'context', 'overlay_selector',
            'browser', 'viewport', 'console_errors',
        ];

        // JSON string fields need wp_slash to survive wp_unslash in update_post_meta.
        $json_fields = [ 'computed_styles', 'bounding_box', 'console_errors' ];

        foreach ( $meta_fields as $field ) {
            $value = $request->get_param( $field );
            if ( $value !== null ) {
                $save_value = in_array( $field, $json_fields, true ) ? wp_slash( $value ) : $value;
                update_post_meta( $post_id, '_buglens_' . $field, $save_value );
            }
        }

        update_post_meta( $post_id, '_buglens_status', 'open' );

        // Handle screenshot (base64 PNG).
        $screenshot_b64 = $request->get_param( 'screenshot' );
        if ( $screenshot_b64 ) {
            $screenshot_id = self::save_screenshot( $post_id, $screenshot_b64 );
            if ( $screenshot_id ) {
                update_post_meta( $post_id, '_buglens_screenshot_id', $screenshot_id );
            }
        }

        // Generate export files.
        if ( class_exists( 'BugLens_Export' ) ) {
            BugLens_Export::export_report( $post_id );
            BugLens_Export::export_index();
        }

        $post = get_post( $post_id );
        return new WP_REST_Response( self::format_report_full( $post ), 201 );
    }

    /**
     * Update a report (currently supports status changes).
     */
    public static function update_report( WP_REST_Request $request ): WP_REST_Response {
        $post = get_post( absint( $request['id'] ) );
        if ( ! $post || $post->post_type !== BugLens_CPT::POST_TYPE ) {
            return new WP_REST_Response(
                [ 'error' => __( 'Report not found.', 'buglens' ) ],
                404
            );
        }

        $status = $request->get_param( 'status' );
        if ( $status && in_array( $status, [ 'open', 'in_progress', 'resolved', 'closed' ], true ) ) {
            update_post_meta( $post->ID, '_buglens_status', $status );
        }

        // Re-export.
        if ( class_exists( 'BugLens_Export' ) ) {
            BugLens_Export::export_report( $post->ID );
            BugLens_Export::export_index();
        }

        return new WP_REST_Response( self::format_report_full( get_post( $post->ID ) ), 200 );
    }

    /**
     * Delete a report permanently.
     */
    public static function delete_report( WP_REST_Request $request ): WP_REST_Response {
        $post = get_post( absint( $request['id'] ) );
        if ( ! $post || $post->post_type !== BugLens_CPT::POST_TYPE ) {
            return new WP_REST_Response(
                [ 'error' => __( 'Report not found.', 'buglens' ) ],
                404
            );
        }

        $result = wp_delete_post( $post->ID, true );
        if ( ! $result ) {
            return new WP_REST_Response(
                [ 'error' => __( 'Failed to delete report.', 'buglens' ) ],
                500
            );
        }

        return new WP_REST_Response( [ 'deleted' => true, 'id' => $post->ID ], 200 );
    }

    /**
     * Get screenshot — redirect to the attachment URL instead of streaming bytes.
     */
    public static function get_screenshot( WP_REST_Request $request ): WP_REST_Response {
        $post = get_post( absint( $request['id'] ) );
        if ( ! $post || $post->post_type !== BugLens_CPT::POST_TYPE ) {
            return new WP_REST_Response(
                [ 'error' => __( 'Report not found.', 'buglens' ) ],
                404
            );
        }

        $screenshot_id = get_post_meta( $post->ID, '_buglens_screenshot_id', true );
        if ( ! $screenshot_id ) {
            return new WP_REST_Response(
                [ 'error' => __( 'No screenshot attached to this report.', 'buglens' ) ],
                404
            );
        }

        $url = wp_get_attachment_url( (int) $screenshot_id );
        if ( ! $url ) {
            return new WP_REST_Response(
                [ 'error' => __( 'Screenshot file missing.', 'buglens' ) ],
                404
            );
        }

        return new WP_REST_Response(
            [
                'url'     => $url,
                'post_id' => $post->ID,
            ],
            200,
            [ 'Location' => $url ]
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Save a base64-encoded screenshot as a WordPress attachment.
     */
    private static function save_screenshot( int $post_id, string $base64 ): ?int {
        $data = base64_decode( preg_replace( '#^data:image/\w+;base64,#i', '', $base64 ) );
        if ( ! $data ) {
            return null;
        }

        $upload_dir = wp_upload_dir();
        $filename   = 'buglens-report-' . $post_id . '.png';
        $dir        = $upload_dir['basedir'] . '/buglens/screenshots';
        wp_mkdir_p( $dir );
        $filepath = $dir . '/' . $filename;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $filepath, $data );

        $attachment_id = wp_insert_attachment( [
            'guid'           => $upload_dir['baseurl'] . '/buglens/screenshots/' . $filename,
            'post_mime_type' => 'image/png',
            'post_title'     => sprintf(
                /* translators: %d: report post ID */
                __( 'BugLens Report #%d', 'buglens' ),
                $post_id
            ),
            'post_status'    => 'inherit',
        ], $filepath, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata( $attachment_id, $filepath );
        wp_update_attachment_metadata( $attachment_id, $meta );

        return $attachment_id;
    }

    /**
     * Format a report for list/summary responses.
     */
    private static function format_report_summary( WP_Post $post ): array {
        $screenshot_id = get_post_meta( $post->ID, '_buglens_screenshot_id', true );
        return [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'status'         => get_post_meta( $post->ID, '_buglens_status', true ) ?: 'open',
            'page_url'       => get_post_meta( $post->ID, '_buglens_page_url', true ),
            'selector'       => get_post_meta( $post->ID, '_buglens_selector', true ),
            'context'        => get_post_meta( $post->ID, '_buglens_context', true ),
            'reported_at'    => $post->post_date_gmt,
            'has_screenshot' => (bool) $screenshot_id,
            'screenshot_url' => $screenshot_id ? wp_get_attachment_url( (int) $screenshot_id ) : null,
        ];
    }

    /**
     * Format a report for full/detail responses.
     */
    private static function format_report_full( WP_Post $post ): array {
        $data          = self::format_report_summary( $post );
        $screenshot_id = get_post_meta( $post->ID, '_buglens_screenshot_id', true );

        $data['description']    = $post->post_content;
        $data['parent_chain']   = get_post_meta( $post->ID, '_buglens_parent_chain', true );
        $data['outer_html']     = get_post_meta( $post->ID, '_buglens_outer_html', true );
        $data['inner_text']     = get_post_meta( $post->ID, '_buglens_inner_text', true );

        $raw_styles            = get_post_meta( $post->ID, '_buglens_computed_styles', true ) ?: '{}';
        $data['computed_styles'] = json_decode( $raw_styles, true )
            ?? json_decode( stripslashes( $raw_styles ), true )
            ?? [];

        $raw_box              = get_post_meta( $post->ID, '_buglens_bounding_box', true ) ?: '{}';
        $data['bounding_box'] = json_decode( $raw_box, true )
            ?? json_decode( stripslashes( $raw_box ), true )
            ?? [];

        $data['overlay_selector'] = get_post_meta( $post->ID, '_buglens_overlay_selector', true );
        $data['browser']          = get_post_meta( $post->ID, '_buglens_browser', true );
        $data['viewport']         = get_post_meta( $post->ID, '_buglens_viewport', true );
        $data['console_errors']   = json_decode(
            get_post_meta( $post->ID, '_buglens_console_errors', true ) ?: '[]',
            true
        );
        $data['screenshot_url'] = $screenshot_id ? wp_get_attachment_url( (int) $screenshot_id ) : null;

        return $data;
    }
}
