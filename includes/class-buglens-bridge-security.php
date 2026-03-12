<?php
/**
 * BugLens Bridge Security — API key, IP whitelist, token, and path validation.
 *
 * Provides the security layer for Bridge REST API endpoints, including:
 * - API key validation (timing-safe, same pattern as existing REST API)
 * - IP whitelist with proxy header support (Cloudflare, etc.)
 * - Time-limited token generation and validation
 * - Path restriction and directory traversal prevention
 * - Read-only mode enforcement
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BugLens_Bridge_Security {

	/**
	 * Option name for bridge settings.
	 */
	private const SETTINGS_OPTION = 'buglens_bridge_settings';

	/**
	 * Option name for active bridge tokens.
	 */
	private const TOKENS_OPTION = 'buglens_bridge_tokens';

	/**
	 * Default bridge settings.
	 */
	private const DEFAULTS = [
		'bridge_enabled'    => false,
		'ip_whitelist'      => '',
		'token_enabled'     => false,
		'token_lifetime'    => 3600,
		'path_restrictions' => false,
		'allowed_paths'     => '',
		'blocked_paths'     => 'wp-config.php,.htaccess,.htpasswd',
		'read_only'         => false,
	];

	/**
	 * Get bridge settings merged with defaults.
	 *
	 * @return array<string, mixed> Bridge settings.
	 */
	public static function get_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return wp_parse_args( $stored, self::DEFAULTS );
	}

	/**
	 * Check if the Bridge API is enabled.
	 *
	 * @return bool True if bridge is enabled.
	 */
	public static function is_enabled(): bool {
		$settings = self::get_settings();
		return ! empty( $settings['bridge_enabled'] );
	}

	/**
	 * Validate API key from request header or query param (timing-safe).
	 *
	 * Mirrors the existing pattern from BugLens_REST_API::check_read_permission().
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function validate_api_key( WP_REST_Request $request ) {
		$key = $request->get_header( 'X-BugLens-Key' )
			?? $request->get_param( 'buglens_key' );

		$stored_key = get_option( 'buglens_api_key', '' );

		if ( ! is_string( $key ) || $key === '' ) {
			return new WP_Error(
				'buglens_missing_api_key',
				__( 'API key is required. Provide it via X-BugLens-Key header or buglens_key parameter.', 'buglens' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! is_string( $stored_key ) || $stored_key === '' ) {
			return new WP_Error(
				'buglens_no_stored_key',
				__( 'No API key configured. Generate one in BugLens Settings.', 'buglens' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! hash_equals( $stored_key, $key ) ) {
			return new WP_Error(
				'buglens_invalid_api_key',
				__( 'Invalid API key.', 'buglens' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Validate client IP against the whitelist (if configured).
	 *
	 * Supports Cloudflare CF-Connecting-IP, X-Forwarded-For, and X-Real-IP headers.
	 *
	 * @return true|WP_Error True if IP is allowed or no whitelist set, WP_Error if blocked.
	 */
	public static function validate_ip() {
		$settings  = self::get_settings();
		$whitelist = trim( $settings['ip_whitelist'] ?? '' );

		if ( $whitelist === '' ) {
			return true;
		}

		$allowed_ips = array_filter( array_map( 'trim', explode( ',', $whitelist ) ) );
		if ( empty( $allowed_ips ) ) {
			return true;
		}

		$client_ip = self::get_client_ip();

		foreach ( $allowed_ips as $allowed_ip ) {
			if ( $client_ip === $allowed_ip ) {
				return true;
			}
		}

		return new WP_Error(
			'buglens_ip_blocked',
			__( 'Your IP address is not in the Bridge whitelist.', 'buglens' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Validate a time-limited token from the request header.
	 *
	 * Checks the X-BugLens-Token header against stored tokens and cleans expired ones.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error True if token is valid or tokens not enabled, WP_Error on failure.
	 */
	public static function validate_token( WP_REST_Request $request ) {
		$settings = self::get_settings();

		if ( empty( $settings['token_enabled'] ) ) {
			return true;
		}

		$token = $request->get_header( 'X-BugLens-Token' );
		if ( ! is_string( $token ) || $token === '' ) {
			return new WP_Error(
				'buglens_missing_token',
				__( 'A Bridge token is required. Generate one via the Bridge admin page.', 'buglens' ),
				[ 'status' => 401 ]
			);
		}

		$tokens = get_option( self::TOKENS_OPTION, [] );
		if ( ! is_array( $tokens ) ) {
			$tokens = [];
		}

		// Clean expired tokens.
		$now     = time();
		$changed = false;
		foreach ( $tokens as $hash => $expires ) {
			if ( $expires < $now ) {
				unset( $tokens[ $hash ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::TOKENS_OPTION, $tokens, false );
		}

		// Check the provided token (timing-safe).
		$token_hash = hash( 'sha256', $token );
		if ( isset( $tokens[ $token_hash ] ) && $tokens[ $token_hash ] >= $now ) {
			return true;
		}

		return new WP_Error(
			'buglens_invalid_token',
			__( 'Invalid or expired Bridge token.', 'buglens' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Generate a time-limited Bridge token.
	 *
	 * Creates a random token, stores its SHA-256 hash with an expiry timestamp,
	 * and returns the plaintext token (shown once).
	 *
	 * @return array{ token: string, expires: int } The plaintext token and expiry timestamp.
	 */
	public static function generate_token(): array {
		$settings = self::get_settings();
		$lifetime = intval( $settings['token_lifetime'] ?? 3600 );
		$expires  = time() + $lifetime;

		$token      = wp_generate_password( 48, false );
		$token_hash = hash( 'sha256', $token );

		$tokens = get_option( self::TOKENS_OPTION, [] );
		if ( ! is_array( $tokens ) ) {
			$tokens = [];
		}

		// Clean expired tokens before adding new one.
		$now = time();
		foreach ( $tokens as $hash => $exp ) {
			if ( $exp < $now ) {
				unset( $tokens[ $hash ] );
			}
		}

		$tokens[ $token_hash ] = $expires;
		update_option( self::TOKENS_OPTION, $tokens, false );

		return [
			'token'   => $token,
			'expires' => $expires,
		];
	}

	/**
	 * Validate and resolve a relative file path.
	 *
	 * Checks for directory traversal, blocked paths (fnmatch), allowed paths
	 * (prefix match), and read-only mode enforcement.
	 *
	 * @param string $relative_path The path relative to ABSPATH.
	 * @param string $mode          Access mode: 'read' or 'write'.
	 * @return string|WP_Error Absolute path on success, WP_Error on failure.
	 */
	public static function validate_path( string $relative_path, string $mode = 'read' ) {
		$settings = self::get_settings();

		// Block directory traversal.
		if ( strpos( $relative_path, '..' ) !== false ) {
			return new WP_Error(
				'buglens_path_traversal',
				__( 'Directory traversal is not allowed.', 'buglens' ),
				[ 'status' => 403 ]
			);
		}

		// Normalize the path.
		$relative_path = ltrim( $relative_path, '/' );
		$absolute_path = ABSPATH . $relative_path;

		// Resolve to real path for existing files, otherwise validate parent.
		$real_path = realpath( $absolute_path );
		if ( $real_path !== false ) {
			// Ensure the resolved path is within ABSPATH.
			$real_abspath = realpath( ABSPATH );
			if ( $real_abspath === false || ! str_starts_with( $real_path, $real_abspath ) ) {
				return new WP_Error(
					'buglens_path_outside_root',
					__( 'Path resolves outside the WordPress root.', 'buglens' ),
					[ 'status' => 403 ]
				);
			}
			$absolute_path = $real_path;
		} else {
			// For new files (write mode), validate the parent directory exists and is within ABSPATH.
			$parent = realpath( dirname( $absolute_path ) );
			if ( $parent === false ) {
				return new WP_Error(
					'buglens_path_not_found',
					__( 'Parent directory does not exist.', 'buglens' ),
					[ 'status' => 404 ]
				);
			}
			$real_abspath = realpath( ABSPATH );
			if ( $real_abspath === false || ! str_starts_with( $parent, $real_abspath ) ) {
				return new WP_Error(
					'buglens_path_outside_root',
					__( 'Path resolves outside the WordPress root.', 'buglens' ),
					[ 'status' => 403 ]
				);
			}
			$absolute_path = $parent . '/' . basename( $absolute_path );
		}

		// Check blocked paths (always enforced, uses fnmatch).
		$blocked_str = trim( $settings['blocked_paths'] ?? 'wp-config.php,.htaccess,.htpasswd' );
		if ( $blocked_str !== '' ) {
			$blocked_patterns = array_filter( array_map( 'trim', explode( ',', $blocked_str ) ) );
			foreach ( $blocked_patterns as $pattern ) {
				if ( fnmatch( $pattern, $relative_path, FNM_PATHNAME ) ) {
					return new WP_Error(
						'buglens_path_blocked',
						/* translators: %s: blocked file pattern */
						sprintf( __( 'Access to this path is blocked by security policy: %s', 'buglens' ), $pattern ),
						[ 'status' => 403 ]
					);
				}
				// Also check against just the filename.
				if ( fnmatch( $pattern, basename( $relative_path ) ) ) {
					return new WP_Error(
						'buglens_path_blocked',
						/* translators: %s: blocked file pattern */
						sprintf( __( 'Access to this path is blocked by security policy: %s', 'buglens' ), $pattern ),
						[ 'status' => 403 ]
					);
				}
			}
		}

		// Check allowed paths (prefix match, only when path_restrictions is enabled).
		if ( ! empty( $settings['path_restrictions'] ) ) {
			$allowed_str = trim( $settings['allowed_paths'] ?? '' );
			if ( $allowed_str !== '' ) {
				$allowed_prefixes = array_filter( array_map( 'trim', explode( ',', $allowed_str ) ) );
				$path_allowed     = false;
				foreach ( $allowed_prefixes as $prefix ) {
					$prefix = ltrim( $prefix, '/' );
					if ( str_starts_with( $relative_path, $prefix ) ) {
						$path_allowed = true;
						break;
					}
				}
				if ( ! $path_allowed ) {
					return new WP_Error(
						'buglens_path_not_allowed',
						__( 'This path is not within the allowed paths list.', 'buglens' ),
						[ 'status' => 403 ]
					);
				}
			}
		}

		// Check read-only mode for write operations.
		if ( $mode === 'write' && ! empty( $settings['read_only'] ) ) {
			return new WP_Error(
				'buglens_read_only',
				__( 'Bridge is in read-only mode. Write operations are disabled.', 'buglens' ),
				[ 'status' => 403 ]
			);
		}

		return $absolute_path;
	}

	/**
	 * Orchestrate all permission checks for a Bridge request.
	 *
	 * Checks in order: enabled -> api_key -> ip -> token -> read_only.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param string          $mode    Access mode: 'read' or 'write'.
	 * @return true|WP_Error True if all checks pass, WP_Error on first failure.
	 */
	public static function check_permission( WP_REST_Request $request, string $mode = 'read' ) {
		// 1. Check if Bridge is enabled.
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'buglens_bridge_disabled',
				__( 'The Bridge API is not enabled. Enable it in BugLens > Bridge settings.', 'buglens' ),
				[ 'status' => 403 ]
			);
		}

		// 2. Validate API key.
		$key_result = self::validate_api_key( $request );
		if ( is_wp_error( $key_result ) ) {
			return $key_result;
		}

		// 3. Validate IP whitelist.
		$ip_result = self::validate_ip();
		if ( is_wp_error( $ip_result ) ) {
			return $ip_result;
		}

		// 4. Validate token (if tokens are enabled).
		$token_result = self::validate_token( $request );
		if ( is_wp_error( $token_result ) ) {
			return $token_result;
		}

		// 5. Check read-only mode for write operations.
		if ( $mode === 'write' ) {
			$settings = self::get_settings();
			if ( ! empty( $settings['read_only'] ) ) {
				return new WP_Error(
					'buglens_read_only',
					__( 'Bridge is in read-only mode. Write operations are disabled.', 'buglens' ),
					[ 'status' => 403 ]
				);
			}
		}

		return true;
	}

	/**
	 * Get the client's IP address, accounting for proxy headers.
	 *
	 * Checks headers in priority order:
	 * 1. CF-Connecting-IP (Cloudflare)
	 * 2. X-Forwarded-For (first IP in chain)
	 * 3. X-Real-IP (nginx proxy)
	 * 4. REMOTE_ADDR (direct connection)
	 *
	 * @return string The client IP address.
	 */
	private static function get_client_ip(): string {
		// Cloudflare.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		// X-Forwarded-For (take the first IP in the chain).
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}

		// X-Real-IP.
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}

		// Direct connection.
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}
}
