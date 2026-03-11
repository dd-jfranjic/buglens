<?php
/**
 * BugLens Export — generates machine-readable Markdown and JSON index files.
 *
 * No i18n needed: export output is consumed by AI agents and automation tools,
 * not displayed to end users in the WordPress admin.
 *
 * Uses file_put_contents() directly because:
 *  - WP_Filesystem requires credentials prompting (FTP/SSH) which is unsuitable for
 *    automated background writes to the uploads directory.
 *  - The uploads directory is writable by the web server by design.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BugLens_Export {

    /**
     * Get the base BugLens uploads directory path.
     */
    private static function get_buglens_dir(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/buglens';
    }

    /**
     * Export a single report as a Markdown file.
     */
    public static function export_report( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== BugLens_CPT::POST_TYPE ) {
            return;
        }

        $dir = self::get_buglens_dir();
        wp_mkdir_p( $dir );

        $status           = get_post_meta( $post_id, '_buglens_status', true ) ?: 'open';
        $page_url         = get_post_meta( $post_id, '_buglens_page_url', true );
        $selector         = get_post_meta( $post_id, '_buglens_selector', true );
        $parent_chain     = get_post_meta( $post_id, '_buglens_parent_chain', true );
        $outer_html       = get_post_meta( $post_id, '_buglens_outer_html', true );
        $inner_text       = get_post_meta( $post_id, '_buglens_inner_text', true );
        $computed_styles  = get_post_meta( $post_id, '_buglens_computed_styles', true );
        $bounding_box     = get_post_meta( $post_id, '_buglens_bounding_box', true );
        $context          = get_post_meta( $post_id, '_buglens_context', true ) ?: 'page';
        $overlay_selector = get_post_meta( $post_id, '_buglens_overlay_selector', true );
        $browser          = get_post_meta( $post_id, '_buglens_browser', true );
        $viewport         = get_post_meta( $post_id, '_buglens_viewport', true );
        $console_errors   = get_post_meta( $post_id, '_buglens_console_errors', true );
        $screenshot_id    = get_post_meta( $post_id, '_buglens_screenshot_id', true );

        // Parse JSON fields for display.
        $styles_arr = json_decode( $computed_styles ?: '{}', true )
            ?? json_decode( stripslashes( $computed_styles ?: '{}' ), true )
            ?? [];
        $box_arr = json_decode( $bounding_box ?: '{}', true )
            ?? json_decode( stripslashes( $bounding_box ?: '{}' ), true )
            ?? [];
        $errors_arr = json_decode( $console_errors ?: '[]', true )
            ?? json_decode( stripslashes( $console_errors ?: '[]' ), true )
            ?? [];

        // Build markdown.
        $md  = "# BugLens Report #{$post_id}\n\n";
        $md .= "| Field | Value |\n|-------|-------|\n";
        $md .= "| Status | {$status} |\n";
        $md .= "| Page | {$page_url} |\n";
        $md .= "| Reported | {$post->post_date_gmt} |\n";
        $md .= "| Browser | {$browser} |\n";
        $md .= "| Viewport | {$viewport} |\n";
        $md .= "| Context | {$context} |\n";
        $md .= "\n## Target Element\n\n";
        $md .= "- **Selector:** `{$selector}`\n";
        $md .= "- **Parent chain:** `{$parent_chain}`\n";
        $md .= "- **Context:** {$context}\n";

        if ( $overlay_selector ) {
            $md .= "- **Overlay container:** `{$overlay_selector}`\n";
        }

        if ( ! empty( $box_arr ) ) {
            $bx = $box_arr['x'] ?? $box_arr['left'] ?? '';
            $by = $box_arr['y'] ?? $box_arr['top'] ?? '';
            $bw = $box_arr['width'] ?? '';
            $bh = $box_arr['height'] ?? '';
            $md .= "- **Bounding box:** x:{$bx}, y:{$by}, {$bw}x{$bh}\n";
        }

        if ( ! empty( $styles_arr ) ) {
            $md .= "- **Computed styles:**\n";
            foreach ( $styles_arr as $prop => $val ) {
                $md .= "  - {$prop}: {$val}\n";
            }
        }

        if ( $outer_html ) {
            $md .= "\n### outerHTML\n\n```html\n{$outer_html}\n```\n";
        }

        if ( $inner_text ) {
            $md .= "\n### Inner text\n\n{$inner_text}\n";
        }

        $md .= "\n## Description\n\n{$post->post_content}\n";

        if ( ! empty( $errors_arr ) ) {
            $md .= "\n## Console Errors\n\n";
            foreach ( $errors_arr as $i => $error ) {
                $md .= "[{$i}] {$error}\n";
            }
        }

        if ( $screenshot_id ) {
            $md .= "\n## Screenshot\n\n![screenshot](./screenshots/buglens-report-{$post_id}.png)\n";
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $dir . '/report-' . $post_id . '.md', $md );
    }

    /**
     * Export the reports index as a JSON file.
     */
    public static function export_index(): void {
        $dir = self::get_buglens_dir();
        wp_mkdir_p( $dir );

        $query = new WP_Query( [
            'post_type'      => BugLens_CPT::POST_TYPE,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $reports = [];
        foreach ( $query->posts as $post ) {
            $screenshot_id = get_post_meta( $post->ID, '_buglens_screenshot_id', true );
            $reports[]     = [
                'id'              => $post->ID,
                'title'           => $post->post_title,
                'status'          => get_post_meta( $post->ID, '_buglens_status', true ) ?: 'open',
                'page_url'        => get_post_meta( $post->ID, '_buglens_page_url', true ),
                'selector'        => get_post_meta( $post->ID, '_buglens_selector', true ),
                'context'         => get_post_meta( $post->ID, '_buglens_context', true ) ?: 'page',
                'reported_at'     => $post->post_date_gmt,
                'has_screenshot'  => (bool) $screenshot_id,
                'markdown_file'   => 'report-' . $post->ID . '.md',
                'screenshot_file' => $screenshot_id ? 'screenshots/buglens-report-' . $post->ID . '.png' : null,
                'api_url'         => '/wp-json/buglens/v1/reports/' . $post->ID,
            ];
        }

        $index = [
            'plugin'    => 'buglens',
            'version'   => BUGLENS_VERSION,
            'site'      => home_url(),
            'generated' => gmdate( 'c' ),
            'total'     => count( $reports ),
            'reports'   => $reports,
        ];

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents(
            $dir . '/reports.json',
            wp_json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        );
    }
}
