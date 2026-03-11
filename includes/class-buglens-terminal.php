<?php
/**
 * BugLens Terminal — server-side command execution via proc_open.
 *
 * Per-command execution with CWD tracking across AJAX requests.
 * Sessions are stored as JSON files in the uploads directory.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BugLens_Terminal {

    /**
     * Return (and create if needed) the terminal sessions directory.
     */
    private static function sessions_dir(): string {
        $dir = wp_upload_dir()['basedir'] . '/buglens/.terminal-sessions';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );

            // Protect directory from web access.
            $htaccess = $dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                file_put_contents( $htaccess, "Deny from all\n" );
            }

            // Add index.php for extra protection against directory listing.
            $index = $dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                file_put_contents( $index, "<?php\n// Silence is golden.\n" );
            }
        }
        return $dir;
    }

    /**
     * AJAX handler: execute a command in the terminal session.
     */
    public static function ajax_execute(): void {
        check_ajax_referer( 'buglens_terminal', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $session_id = sanitize_key( $_POST['session_id'] ?? '' );
        $command    = wp_unslash( $_POST['command'] ?? '' );

        if ( ! $session_id || $command === '' ) {
            wp_send_json_error( __( 'Missing required parameters.', 'buglens' ) );
        }

        $session_file = self::sessions_dir() . '/' . $session_id . '.json';
        $session_data = file_exists( $session_file )
            ? json_decode( file_get_contents( $session_file ), true ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
            : [ 'cwd' => ABSPATH, 'env' => [], 'created' => time() ];

        if ( ! is_array( $session_data ) ) {
            $session_data = [ 'cwd' => ABSPATH, 'env' => [], 'created' => time() ];
        }

        // 15-minute session timeout.
        $last = $session_data['last_activity'] ?? $session_data['created'] ?? 0;
        if ( time() - $last > 900 ) {
            if ( file_exists( $session_file ) ) {
                wp_delete_file( $session_file );
            }
            $session_data = [ 'cwd' => ABSPATH, 'env' => [], 'created' => time() ];
        }

        $cwd = $session_data['cwd'];
        if ( ! is_dir( $cwd ) ) {
            $cwd                  = ABSPATH;
            $session_data['cwd'] = $cwd;
        }

        $trimmed = trim( $command );

        // Handle `cd` as a built-in (proc_open spawns a subshell, cd wouldn't persist).
        if ( preg_match( '/^cd\s+(.+)$/', $trimmed, $m ) || $trimmed === 'cd' ) {
            $target = $trimmed === 'cd'
                ? ( getenv( 'HOME' ) ?: '/root' )
                : trim( $m[1] );

            // Strip surrounding quotes.
            $target = trim( $target, '"\'' );

            if ( $target === '~' ) {
                $target = getenv( 'HOME' ) ?: '/root';
            } elseif ( str_starts_with( $target, '~/' ) ) {
                $target = ( getenv( 'HOME' ) ?: '/root' ) . substr( $target, 1 );
            } elseif ( $target === '-' ) {
                $target = $session_data['prev_cwd'] ?? $cwd;
            } elseif ( ! str_starts_with( $target, '/' ) ) {
                $target = $cwd . '/' . $target;
            }

            $real = realpath( $target );
            if ( $real && is_dir( $real ) ) {
                $session_data['prev_cwd']      = $cwd;
                $session_data['cwd']           = $real;
                $session_data['last_activity'] = time();
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                file_put_contents( $session_file, wp_json_encode( $session_data ) );
                wp_send_json_success( [ 'output' => '', 'cwd' => $real, 'exit_code' => 0 ] );
            } else {
                wp_send_json_success( [
                    'output'    => 'bash: cd: ' . esc_html( $m[1] ?? '' ) . ": " . __( 'No such file or directory', 'buglens' ) . "\n",
                    'cwd'       => $cwd,
                    'exit_code' => 1,
                ] );
            }
            return;
        }

        // Execute via proc_open.
        $descriptors = [
            0 => [ 'pipe', 'r' ],  // stdin
            1 => [ 'pipe', 'w' ],  // stdout
            2 => [ 'pipe', 'w' ],  // stderr
        ];

        $env     = null; // Inherit current environment.
        $process = proc_open( $command, $descriptors, $pipes, $cwd, $env );

        if ( ! is_resource( $process ) ) {
            wp_send_json_error( __( 'Failed to execute command.', 'buglens' ) );
        }

        fclose( $pipes[0] ); // Close stdin.

        // Read with timeout protection.
        stream_set_timeout( $pipes[1], 30 );
        stream_set_timeout( $pipes[2], 30 );

        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );

        fclose( $pipes[1] );
        fclose( $pipes[2] );

        $exit_code = proc_close( $process );

        // Combine output: stdout first, then stderr.
        $output = $stdout;
        if ( $stderr !== '' && $stderr !== false ) {
            $output .= $stderr;
        }

        // Limit output size to prevent huge AJAX responses (1 MB max).
        $max_output = 1024 * 1024;
        if ( strlen( $output ) > $max_output ) {
            $output = substr( $output, 0, $max_output )
                . "\n\n--- "
                . __( 'Output truncated (exceeded 1 MB)', 'buglens' )
                . " ---\n";
        }

        $session_data['last_activity'] = time();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $session_file, wp_json_encode( $session_data ) );

        wp_send_json_success( [
            'output'    => $output,
            'cwd'       => $cwd,
            'exit_code' => $exit_code,
        ] );
    }

    /**
     * AJAX handler: start a new terminal session.
     */
    public static function ajax_start_session(): void {
        check_ajax_referer( 'buglens_terminal', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $session_id   = wp_generate_password( 16, false );
        $session_file = self::sessions_dir() . '/' . $session_id . '.json';
        $session_data = [
            'cwd'           => ABSPATH,
            'env'           => [],
            'created'       => time(),
            'last_activity' => time(),
        ];

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $session_file, wp_json_encode( $session_data ) );

        wp_send_json_success( [
            'session_id' => $session_id,
            'cwd'        => ABSPATH,
            'user'       => php_uname( 'n' ) ? get_current_user() : 'www-data',
            'hostname'   => gethostname() ?: 'localhost',
        ] );
    }

    /**
     * AJAX handler: end (destroy) a terminal session.
     */
    public static function ajax_end_session(): void {
        check_ajax_referer( 'buglens_terminal', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to perform this action.', 'buglens' ), 403 );
        }

        $session_id = sanitize_key( $_POST['session_id'] ?? '' );
        if ( $session_id ) {
            $file = self::sessions_dir() . '/' . $session_id . '.json';
            if ( file_exists( $file ) ) {
                wp_delete_file( $file );
            }
        }
        wp_send_json_success();
    }
}
