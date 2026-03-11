<?php
/**
 * BugLens Admin — menus, settings fields, and admin asset enqueueing.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BugLens_Admin {

    /**
     * Register top-level menu and submenu pages.
     */
    public static function register_menu(): void {
        add_menu_page(
            __( 'BugLens', 'buglens' ),
            __( 'BugLens', 'buglens' ),
            'manage_options',
            'buglens-board',
            [ self::class, 'render_board_page' ],
            'dashicons-search',
            80
        );

        add_submenu_page(
            'buglens-board',
            __( 'Board', 'buglens' ),
            __( 'Board', 'buglens' ),
            'manage_options',
            'buglens-board',
            [ self::class, 'render_board_page' ]
        );

        add_submenu_page(
            'buglens-board',
            __( 'All Reports', 'buglens' ),
            __( 'All Reports', 'buglens' ),
            'manage_options',
            'edit.php?post_type=buglens_report'
        );

        add_submenu_page(
            'buglens-board',
            __( 'Files', 'buglens' ),
            __( 'Files', 'buglens' ),
            'manage_options',
            'buglens-files',
            [ self::class, 'render_files_page' ]
        );

        add_submenu_page(
            'buglens-board',
            __( 'Terminal', 'buglens' ),
            __( 'Terminal', 'buglens' ),
            'manage_options',
            'buglens-terminal',
            [ self::class, 'render_terminal_page' ]
        );

        add_submenu_page(
            'buglens-board',
            __( 'Settings', 'buglens' ),
            __( 'Settings', 'buglens' ),
            'manage_options',
            'buglens-settings',
            [ self::class, 'render_settings_page' ]
        );
    }

    /**
     * Register settings, section, and fields via Settings API.
     */
    public static function register_settings(): void {
        register_setting( 'buglens_settings_group', 'buglens_settings', [
            'sanitize_callback' => [ self::class, 'sanitize_settings' ],
        ] );

        add_settings_section(
            'buglens_general',
            __( 'General Settings', 'buglens' ),
            null,
            'buglens-settings'
        );

        add_settings_field(
            'buglens_api_key',
            __( 'API Key', 'buglens' ),
            [ self::class, 'render_api_key_field' ],
            'buglens-settings',
            'buglens_general'
        );

        add_settings_field(
            'buglens_fab_position',
            __( 'FAB Position', 'buglens' ),
            [ self::class, 'render_fab_position_field' ],
            'buglens-settings',
            'buglens_general'
        );

        add_settings_field(
            'buglens_visibility',
            __( 'Widget Visibility', 'buglens' ),
            [ self::class, 'render_visibility_field' ],
            'buglens-settings',
            'buglens_general'
        );

        add_settings_field(
            'buglens_color',
            __( 'Widget Color', 'buglens' ),
            [ self::class, 'render_color_field' ],
            'buglens-settings',
            'buglens_general'
        );

        add_settings_field(
            'buglens_outerhtml_limit',
            __( 'outerHTML Limit', 'buglens' ),
            [ self::class, 'render_outerhtml_field' ],
            'buglens-settings',
            'buglens_general'
        );

        add_settings_field(
            'buglens_capture_console',
            __( 'Capture Console Errors', 'buglens' ),
            [ self::class, 'render_console_field' ],
            'buglens-settings',
            'buglens_general'
        );
    }

    /**
     * Sanitize all settings on save.
     */
    public static function sanitize_settings( array $input ): array {
        $valid_positions  = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ];
        $valid_visibility = [ 'admins', 'logged_in', 'everyone' ];

        return [
            'fab_position'    => in_array( $input['fab_position'] ?? '', $valid_positions, true )
                ? $input['fab_position'] : 'bottom-right',
            'visibility'      => in_array( $input['visibility'] ?? '', $valid_visibility, true )
                ? $input['visibility'] : 'admins',
            'color'           => sanitize_hex_color( $input['color'] ?? '#F2C700' ) ?: '#F2C700',
            'outerhtml_limit' => max( 500, min( 50000, intval( $input['outerhtml_limit'] ?? 5000 ) ) ),
            'capture_console' => ! empty( $input['capture_console'] ),
        ];
    }

    // =========================================================================
    // Field Renderers
    // =========================================================================

    /**
     * API Key — read-only display + copy + regenerate via AJAX.
     * JS logic is in admin/js/buglens-settings.js (no inline scripts).
     */
    public static function render_api_key_field(): void {
        $key   = get_option( 'buglens_api_key', '' );
        $nonce = wp_create_nonce( 'buglens_regenerate_key' );
        ?>
        <div class="buglens-api-key-wrap">
            <input type="text"
                   id="buglens-api-key"
                   value="<?php echo esc_attr( $key ); ?>"
                   readonly
                   class="regular-text buglens-mono" />
            <button type="button"
                    class="button buglens-copy-btn"
                    id="buglens-copy-key"
                    title="<?php echo esc_attr__( 'Copy to clipboard', 'buglens' ); ?>">
                <span class="dashicons dashicons-clipboard"></span>
                <?php echo esc_html__( 'Copy', 'buglens' ); ?>
            </button>
            <button type="button"
                    class="button button-secondary"
                    id="buglens-regenerate-key"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    title="<?php echo esc_attr__( 'Regenerate API Key', 'buglens' ); ?>">
                <span class="dashicons dashicons-update"></span>
                <?php echo esc_html__( 'Regenerate', 'buglens' ); ?>
            </button>
        </div>
        <p class="description">
            <?php
            echo wp_kses(
                __( 'Use this key in the <code>X-BugLens-Key</code> header for REST API authentication.', 'buglens' ),
                [ 'code' => [] ]
            );
            ?>
        </p>
        <?php
    }

    /**
     * FAB Position — 4 radio buttons.
     */
    public static function render_fab_position_field(): void {
        $settings = get_option( 'buglens_settings', [] );
        $current  = $settings['fab_position'] ?? 'bottom-right';

        $options = [
            'bottom-right' => __( 'Bottom Right', 'buglens' ),
            'bottom-left'  => __( 'Bottom Left', 'buglens' ),
            'top-right'    => __( 'Top Right', 'buglens' ),
            'top-left'     => __( 'Top Left', 'buglens' ),
        ];

        echo '<div class="buglens-radio-group">';
        foreach ( $options as $value => $label ) {
            $checked = checked( $current, $value, false );
            printf(
                '<label class="buglens-radio-label"><input type="radio" name="buglens_settings[fab_position]" value="%s" %s /> %s</label>',
                esc_attr( $value ),
                esc_attr( $checked ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns safe HTML attribute.
                esc_html( $label )
            );
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Corner of the screen where the bug-report button appears.', 'buglens' ) . '</p>';
    }

    /**
     * Widget Visibility — 3 radio buttons.
     */
    public static function render_visibility_field(): void {
        $settings = get_option( 'buglens_settings', [] );
        $current  = $settings['visibility'] ?? 'admins';

        $allowed_html = [
            'code' => [],
        ];

        $options = [
            'admins'    => wp_kses(
                __( 'Admins only — Only users with <code>manage_options</code> capability', 'buglens' ),
                $allowed_html
            ),
            'logged_in' => wp_kses(
                __( 'Logged-in users — Any authenticated user', 'buglens' ),
                $allowed_html
            ),
            'everyone'  => wp_kses(
                __( 'Everyone — All visitors (use with caution)', 'buglens' ),
                $allowed_html
            ),
        ];

        echo '<div class="buglens-radio-group buglens-radio-group--vertical">';
        foreach ( $options as $value => $label ) {
            $checked = checked( $current, $value, false );
            printf(
                '<label class="buglens-radio-label"><input type="radio" name="buglens_settings[visibility]" value="%s" %s /> %s</label>',
                esc_attr( $value ),
                esc_attr( $checked ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns safe HTML attribute.
                wp_kses( $label, [ 'code' => [] ] ) // Already sanitized via wp_kses above, re-escaped for Plugin Check compliance.
            );
        }
        echo '</div>';
    }

    /**
     * Widget Color — color picker input.
     * JS initialization is in admin/js/buglens-settings.js (no inline scripts).
     */
    public static function render_color_field(): void {
        $settings = get_option( 'buglens_settings', [] );
        $color    = $settings['color'] ?? '#F2C700';
        ?>
        <input type="text"
               name="buglens_settings[color]"
               id="buglens-color-picker"
               value="<?php echo esc_attr( $color ); ?>"
               class="buglens-color-field"
               data-default-color="#F2C700" />
        <p class="description">
            <?php echo esc_html__( 'Primary color for the floating action button and widget accents.', 'buglens' ); ?>
        </p>
        <?php
    }

    /**
     * outerHTML Limit — number input.
     */
    public static function render_outerhtml_field(): void {
        $settings = get_option( 'buglens_settings', [] );
        $limit    = $settings['outerhtml_limit'] ?? 5000;
        ?>
        <input type="number"
               name="buglens_settings[outerhtml_limit]"
               value="<?php echo esc_attr( $limit ); ?>"
               min="500"
               max="50000"
               step="500"
               class="small-text" />
        <span><?php echo esc_html__( 'characters', 'buglens' ); ?></span>
        <p class="description">
            <?php echo esc_html__( 'Maximum number of characters captured for the selected element\'s outer HTML. Higher values give more context but increase report size.', 'buglens' ); ?>
        </p>
        <?php
    }

    /**
     * Capture Console Errors — checkbox.
     */
    public static function render_console_field(): void {
        $settings = get_option( 'buglens_settings', [] );
        $capture  = $settings['capture_console'] ?? true;
        ?>
        <label>
            <input type="checkbox"
                   name="buglens_settings[capture_console]"
                   value="1"
                   <?php checked( $capture ); ?> />
            <?php echo esc_html__( 'Automatically capture JavaScript console errors and include them in bug reports', 'buglens' ); ?>
        </label>
        <?php
    }

    // =========================================================================
    // Page Renderers
    // =========================================================================

    public static function render_board_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'buglens' ) );
        }
        include BUGLENS_DIR . 'admin/views/board.php';
    }

    public static function render_files_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'buglens' ) );
        }
        include BUGLENS_DIR . 'admin/views/files.php';
    }

    public static function render_terminal_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'buglens' ) );
        }
        include BUGLENS_DIR . 'admin/views/terminal.php';
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'buglens' ) );
        }
        include BUGLENS_DIR . 'admin/views/settings.php';
    }

    // =========================================================================
    // Admin Assets
    // =========================================================================

    /**
     * Enqueue admin CSS and JS for BugLens pages only.
     */
    public static function enqueue_admin_assets( string $hook ): void {
        $buglens_pages = [
            'toplevel_page_buglens-board',
            'buglens_page_buglens-settings',
            'buglens_page_buglens-files',
            'buglens_page_buglens-terminal',
        ];

        if ( ! in_array( $hook, $buglens_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'buglens-admin',
            BUGLENS_URL . 'admin/css/buglens-admin.css',
            [],
            BUGLENS_VERSION
        );

        // Board page needs kanban JS.
        if ( $hook === 'toplevel_page_buglens-board' ) {
            wp_enqueue_script(
                'buglens-board',
                BUGLENS_URL . 'admin/js/buglens-board.js',
                [],
                BUGLENS_VERSION,
                true
            );
            wp_localize_script( 'buglens-board', 'buglensBoard', [
                'restUrl' => rest_url( 'buglens/v1/' ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'apiKey'  => get_option( 'buglens_api_key', '' ),
            ] );
        }

        // Terminal page needs xterm.js.
        if ( $hook === 'buglens_page_buglens-terminal' ) {
            wp_enqueue_style( 'xterm', BUGLENS_URL . 'vendor/xterm/xterm.css', [], '6.0.0' );
            wp_enqueue_script( 'xterm', BUGLENS_URL . 'vendor/xterm/xterm.min.js', [], '6.0.0', true );
            wp_enqueue_script( 'xterm-fit', BUGLENS_URL . 'vendor/xterm/xterm-addon-fit.min.js', [ 'xterm' ], '0.11.0', true );
            wp_enqueue_script(
                'buglens-terminal',
                BUGLENS_URL . 'admin/js/buglens-terminal.js',
                [ 'xterm', 'xterm-fit' ],
                BUGLENS_VERSION,
                true
            );
            wp_localize_script( 'buglens-terminal', 'buglensTerminal', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'buglens_terminal' ),
                'abspath' => ABSPATH,
                'home'    => getenv( 'HOME' ) ?: '/root',
            ] );
        }

        // Files page needs CodeMirror + files JS.
        if ( $hook === 'buglens_page_buglens-files' ) {
            $cm_settings = wp_enqueue_code_editor( [ 'type' => 'application/json' ] );
            wp_enqueue_script(
                'buglens-files',
                BUGLENS_URL . 'admin/js/buglens-files.js',
                [ 'wp-codemirror' ],
                BUGLENS_VERSION,
                true
            );
            wp_localize_script( 'buglens-files', 'buglensFiles', [
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'buglens_files' ),
                'cmSettings' => $cm_settings,
            ] );
        }

        // Settings page needs color picker + settings JS.
        if ( $hook === 'buglens_page_buglens-settings' ) {
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
            wp_enqueue_script(
                'buglens-settings',
                BUGLENS_URL . 'admin/js/buglens-settings.js',
                [ 'jquery', 'wp-color-picker' ],
                BUGLENS_VERSION,
                true
            );
            wp_localize_script( 'buglens-settings', 'buglensSettings', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'i18n'    => [
                    'copy'          => __( 'Copy', 'buglens' ),
                    'copied'        => __( 'Copied!', 'buglens' ),
                    'regenerate'    => __( 'Regenerate', 'buglens' ),
                    'regenerating'  => __( 'Regenerating...', 'buglens' ),
                    'done'          => __( 'Done!', 'buglens' ),
                    'confirmRegen'  => __( 'Are you sure? Any external integrations using the current key will stop working.', 'buglens' ),
                    'errorPrefix'   => __( 'Error:', 'buglens' ),
                    'unknownError'  => __( 'Unknown error', 'buglens' ),
                    'networkError'  => __( 'Network error. Please try again.', 'buglens' ),
                ],
            ] );
        }
    }

    // =========================================================================
    // AJAX: Regenerate API Key
    // =========================================================================

    /**
     * Handle API key regeneration via AJAX.
     */
    public static function ajax_regenerate_key(): void {
        check_ajax_referer( 'buglens_regenerate_key', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $new_key = wp_generate_password( 40, false );
        update_option( 'buglens_api_key', $new_key );
        wp_send_json_success( [ 'key' => $new_key ] );
    }
}
