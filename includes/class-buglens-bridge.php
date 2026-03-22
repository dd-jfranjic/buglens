<?php
/**
 * BugLens Bridge — Filesystem REST API for AI agents and external tools.
 *
 * Provides 12 filesystem endpoints (read, write, create, delete, rename,
 * list, search, info, diff, bulk-read, tree, wp-cli) plus a token generator.
 * All endpoints are POST to avoid query-string length limits.
 *
 * Security is delegated to BugLens_Bridge_Security (API key, IP whitelist,
 * token validation, path restrictions, read-only mode).
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BugLens_Bridge {

	/**
	 * REST API namespace (shared with BugLens_REST_API).
	 */
	const NAMESPACE = 'buglens/v1';

	/**
	 * Binary file extensions — return metadata only, never raw content.
	 *
	 * @var string[]
	 */
	private const BINARY_EXTENSIONS = [
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
		'woff', 'woff2', 'ttf',
		'zip', 'gz',
		'pdf', 'mp4',
	];

	/**
	 * Maximum text file size for reading (5 MB).
	 */
	private const MAX_READ_SIZE = 5 * 1024 * 1024;

	/**
	 * Maximum file size for bulk-read (2 MB per file).
	 */
	private const MAX_BULK_FILE_SIZE = 2 * 1024 * 1024;

	/**
	 * Maximum files in a bulk-read request.
	 */
	private const MAX_BULK_FILES = 20;

	/**
	 * Maximum search results.
	 */
	private const MAX_SEARCH_RESULTS = 500;

	/**
	 * Maximum directory tree depth.
	 */
	private const MAX_TREE_DEPTH = 10;

	/**
	 * Directories to skip during search and tree traversal.
	 *
	 * @var string[]
	 */
	private const SKIP_DIRS = [ 'node_modules', 'vendor', '.git' ];

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register all Bridge REST routes.
	 */
	public static function register(): void {

		$routes = [
			// Read endpoints.
			'/fs/read'      => [ 'callback' => 'read_file',       'mode' => 'read' ],
			'/fs/list'      => [ 'callback' => 'list_directory',   'mode' => 'read' ],
			'/fs/search'    => [ 'callback' => 'search_files',     'mode' => 'read' ],
			'/fs/info'      => [ 'callback' => 'file_info',        'mode' => 'read' ],
			'/fs/diff'      => [ 'callback' => 'diff_file',        'mode' => 'read' ],
			'/fs/bulk-read' => [ 'callback' => 'bulk_read',        'mode' => 'read' ],
			'/fs/tree'      => [ 'callback' => 'directory_tree',   'mode' => 'read' ],
			// Write endpoints.
			'/fs/write'     => [ 'callback' => 'write_file',       'mode' => 'write' ],
			'/fs/create'    => [ 'callback' => 'create_file',      'mode' => 'write' ],
			'/fs/delete'    => [ 'callback' => 'delete_file',      'mode' => 'write' ],
			'/fs/rename'    => [ 'callback' => 'rename_file',      'mode' => 'write' ],
			'/fs/wp-cli'    => [ 'callback' => 'wp_cli',           'mode' => 'write' ],
		];

		foreach ( $routes as $route => $config ) {
			$mode = $config['mode'];
			register_rest_route(
				self::NAMESPACE,
				$route,
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, $config['callback'] ],
					'permission_callback' => function ( WP_REST_Request $request ) use ( $mode ) {
						return BugLens_Bridge_Security::check_permission( $request, $mode );
					},
				]
			);
		}

		// Token endpoint — only needs API key + IP, not a token.
		register_rest_route(
			self::NAMESPACE,
			'/fs/token',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'generate_token' ],
				'permission_callback' => function ( WP_REST_Request $request ) {
					// Check enabled.
					if ( ! BugLens_Bridge_Security::is_enabled() ) {
						return new WP_Error(
							'buglens_bridge_disabled',
							__( 'The Bridge API is not enabled.', 'buglens' ),
							[ 'status' => 403 ]
						);
					}
					// Validate API key.
					$key_result = BugLens_Bridge_Security::validate_api_key( $request );
					if ( is_wp_error( $key_result ) ) {
						return $key_result;
					}
					// Validate IP.
					$ip_result = BugLens_Bridge_Security::validate_ip();
					if ( is_wp_error( $ip_result ) ) {
						return $ip_result;
					}
					return true;
				},
			]
		);
	}

	// -------------------------------------------------------------------------
	// 1. read_file
	// -------------------------------------------------------------------------

	/**
	 * Read a file's contents with optional offset/limit (line-based).
	 *
	 * Binary files return metadata only. Text files capped at 5 MB.
	 *
	 * @param WP_REST_Request $request Contains: path (required), offset, limit.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function read_file( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || $path === '' ) {
			return self::error( 'The "path" parameter is required.', 400 );
		}

		$abs = BugLens_Bridge_Security::validate_path( $path, 'read' );
		if ( is_wp_error( $abs ) ) {
			return self::error_response( $abs );
		}

		if ( ! file_exists( $abs ) || ! is_file( $abs ) ) {
			return self::error( 'File not found.', 404 );
		}

		$size = filesize( $abs );
		$ext  = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );

		// Binary files — metadata only.
		if ( in_array( $ext, self::BINARY_EXTENSIONS, true ) ) {
			return new WP_REST_Response( [
				'path'    => $path,
				'size'    => $size,
				'binary'  => true,
				'type'    => $ext,
				'message' => 'Binary file — content not returned.',
			], 200 );
		}

		if ( $size > self::MAX_READ_SIZE ) {
			return self::error( 'File exceeds 5 MB limit for text reads.', 413 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$content     = file_get_contents( $abs );
		$lines       = explode( "\n", $content );
		$total_lines = count( $lines );

		$offset = max( 0, intval( $request->get_param( 'offset' ) ?? 0 ) );
		$limit  = intval( $request->get_param( 'limit' ) ?? 0 );

		if ( $offset > 0 || $limit > 0 ) {
			$lines   = array_slice( $lines, $offset, $limit > 0 ? $limit : null );
			$content = implode( "\n", $lines );
		}

		return new WP_REST_Response( [
			'path'        => $path,
			'content'     => $content,
			'size'        => $size,
			'total_lines' => $total_lines,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 2. write_file
	// -------------------------------------------------------------------------

	/**
	 * Write content to an existing file.
	 *
	 * @param WP_REST_Request $request Contains: path (required), content (required).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function write_file( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || $path === '' ) {
			return self::error( 'The "path" parameter is required.', 400 );
		}

		$content = $request->get_param( 'content' );
		if ( $content === null ) {
			return self::error( 'The "content" parameter is required.', 400 );
		}

		$abs = BugLens_Bridge_Security::validate_path( $path, 'write' );
		if ( is_wp_error( $abs ) ) {
			return self::error_response( $abs );
		}

		if ( ! file_exists( $abs ) || ! is_file( $abs ) ) {
			return self::error( 'File does not exist. Use /fs/create for new files.', 404 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $abs, $content );
		if ( $bytes === false ) {
			return self::error( 'Failed to write file.', 500 );
		}

		return new WP_REST_Response( [
			'path'  => $path,
			'bytes' => $bytes,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 3. create_file
	// -------------------------------------------------------------------------

	/**
	 * Create a new file or directory.
	 *
	 * @param WP_REST_Request $request Contains: path (required), content (default ''), directory (bool).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_file( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || $path === '' ) {
			return self::error( 'The "path" parameter is required.', 400 );
		}

		$abs = BugLens_Bridge_Security::validate_path( $path, 'write' );
		if ( is_wp_error( $abs ) ) {
			return self::error_response( $abs );
		}

		if ( file_exists( $abs ) ) {
			return self::error( 'Path already exists.', 409 );
		}

		$is_directory = ! empty( $request->get_param( 'directory' ) );

		if ( $is_directory ) {
			$created = wp_mkdir_p( $abs );
			if ( ! $created ) {
				return self::error( 'Failed to create directory.', 500 );
			}
			return new WP_REST_Response( [
				'path'      => $path,
				'directory' => true,
				'created'   => true,
			], 201 );
		}

		// Ensure parent directory exists.
		$parent = dirname( $abs );
		if ( ! is_dir( $parent ) ) {
			wp_mkdir_p( $parent );
		}

		$content = $request->get_param( 'content' ) ?? '';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $abs, $content );
		if ( $bytes === false ) {
			return self::error( 'Failed to create file.', 500 );
		}

		return new WP_REST_Response( [
			'path'    => $path,
			'bytes'   => $bytes,
			'created' => true,
		], 201 );
	}

	// -------------------------------------------------------------------------
	// 4. delete_file
	// -------------------------------------------------------------------------

	/**
	 * Delete a file or empty directory.
	 *
	 * Directories must be empty (safety measure).
	 *
	 * @param WP_REST_Request $request Contains: path (required).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_file( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || $path === '' ) {
			return self::error( 'The "path" parameter is required.', 400 );
		}

		$abs = BugLens_Bridge_Security::validate_path( $path, 'write' );
		if ( is_wp_error( $abs ) ) {
			return self::error_response( $abs );
		}

		if ( ! file_exists( $abs ) ) {
			return self::error( 'Path not found.', 404 );
		}

		if ( is_dir( $abs ) ) {
			$contents = scandir( $abs );
			// scandir returns at least ['.', '..'].
			if ( count( $contents ) > 2 ) {
				return self::error( 'Directory is not empty. Remove its contents first.', 409 );
			}
			$removed = rmdir( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			if ( ! $removed ) {
				return self::error( 'Failed to delete directory.', 500 );
			}
		} else {
			wp_delete_file( $abs );
			if ( file_exists( $abs ) ) {
				return self::error( 'Failed to delete file.', 500 );
			}
		}

		return new WP_REST_Response( [
			'path'    => $path,
			'deleted' => true,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 5. rename_file
	// -------------------------------------------------------------------------

	/**
	 * Rename / move a file or directory.
	 *
	 * @param WP_REST_Request $request Contains: from (required), to (required).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rename_file( WP_REST_Request $request ) {
		$from = $request->get_param( 'from' );
		$to   = $request->get_param( 'to' );

		if ( ! is_string( $from ) || $from === '' ) {
			return self::error( 'The "from" parameter is required.', 400 );
		}
		if ( ! is_string( $to ) || $to === '' ) {
			return self::error( 'The "to" parameter is required.', 400 );
		}

		$abs_from = BugLens_Bridge_Security::validate_path( $from, 'write' );
		if ( is_wp_error( $abs_from ) ) {
			return self::error_response( $abs_from );
		}

		$abs_to = BugLens_Bridge_Security::validate_path( $to, 'write' );
		if ( is_wp_error( $abs_to ) ) {
			return self::error_response( $abs_to );
		}

		if ( ! file_exists( $abs_from ) ) {
			return self::error( 'Source path does not exist.', 404 );
		}

		if ( file_exists( $abs_to ) ) {
			return self::error( 'Destination path already exists.', 409 );
		}

		// Ensure destination parent directory exists.
		$dest_parent = dirname( $abs_to );
		if ( ! is_dir( $dest_parent ) ) {
			wp_mkdir_p( $dest_parent );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		$result = rename( $abs_from, $abs_to );
		if ( ! $result ) {
			return self::error( 'Failed to rename/move.', 500 );
		}

		return new WP_REST_Response( [
			'from' => $from,
			'to'   => $to,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 6. list_directory
	// -------------------------------------------------------------------------

	/**
	 * List directory contents, sorted dirs-first then alphabetically.
	 *
	 * @param WP_REST_Request $request Contains: path (default '' = ABSPATH).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_directory( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' ) ?? '';

		if ( $path === '' ) {
			$abs = rtrim( ABSPATH, '/' );
		} else {
			$abs = BugLens_Bridge_Security::validate_path( $path, 'read' );
			if ( is_wp_error( $abs ) ) {
				return self::error_response( $abs );
			}
		}

		if ( ! is_dir( $abs ) ) {
			return self::error( 'Directory not found.', 404 );
		}

		$entries = scandir( $abs );
		if ( $entries === false ) {
			return self::error( 'Failed to read directory.', 500 );
		}

		$abspath_prefix = rtrim( ABSPATH, '/' );
		$items          = [];

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$full   = $abs . '/' . $entry;
			$is_dir = is_dir( $full );
			$ext    = $is_dir ? '' : strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );

			// Build relative path to ABSPATH.
			$rel = ltrim( str_replace( $abspath_prefix, '', $full ), '/' );

			$items[] = [
				'name'     => $entry,
				'path'     => $rel,
				'is_dir'   => $is_dir,
				'size'     => $is_dir ? null : filesize( $full ),
				'modified' => filemtime( $full ),
				'type'     => $is_dir ? 'directory' : ( $ext ?: 'file' ),
			];
		}

		// Sort: directories first, then alphabetical.
		usort( $items, function ( array $a, array $b ): int {
			if ( $a['is_dir'] !== $b['is_dir'] ) {
				return $b['is_dir'] <=> $a['is_dir'];
			}
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return new WP_REST_Response( [
			'path'  => $path ?: '/',
			'items' => $items,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 7. search_files
	// -------------------------------------------------------------------------

	/**
	 * Search file contents for a pattern (string or regex).
	 *
	 * Skips node_modules, vendor, .git directories. Skips files > 2 MB.
	 *
	 * @param WP_REST_Request $request Contains: pattern (required), path, glob, regex, max_results, context.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function search_files( WP_REST_Request $request ) {
		$pattern = $request->get_param( 'pattern' );
		if ( ! is_string( $pattern ) || $pattern === '' ) {
			return self::error( 'The "pattern" parameter is required.', 400 );
		}

		$search_path = $request->get_param( 'path' ) ?? '';
		if ( $search_path !== '' ) {
			$abs_root = BugLens_Bridge_Security::validate_path( $search_path, 'read' );
			if ( is_wp_error( $abs_root ) ) {
				return self::error_response( $abs_root );
			}
		} else {
			$abs_root = rtrim( ABSPATH, '/' );
		}

		if ( ! is_dir( $abs_root ) ) {
			return self::error( 'Search path is not a directory.', 400 );
		}

		$glob_param  = $request->get_param( 'glob' ) ?? '*.php,*.css,*.js,*.html,*.txt,*.json,*.md';
		$is_regex    = ! empty( $request->get_param( 'regex' ) );
		$max_results = min( intval( $request->get_param( 'max_results' ) ?? 100 ), self::MAX_SEARCH_RESULTS );
		$context     = max( 0, intval( $request->get_param( 'context' ) ?? 0 ) );

		// Parse glob patterns into extensions for fast matching.
		$glob_parts  = array_filter( array_map( 'trim', explode( ',', $glob_param ) ) );
		$allowed_ext = [];
		foreach ( $glob_parts as $gp ) {
			// Extract extension from patterns like *.php.
			if ( preg_match( '/^\*\.(\w+)$/', $gp, $m ) ) {
				$allowed_ext[] = strtolower( $m[1] );
			}
		}

		$abspath_prefix = rtrim( ABSPATH, '/' );
		$matches        = [];

		try {
			$dir_iterator = new RecursiveDirectoryIterator(
				$abs_root,
				RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
			);

			$filter_iterator = new RecursiveCallbackFilterIterator(
				$dir_iterator,
				function ( SplFileInfo $current, string $key, RecursiveDirectoryIterator $iterator ) {
					if ( $current->isDir() ) {
						return ! in_array( $current->getFilename(), self::SKIP_DIRS, true );
					}
					return true;
				}
			);

			$iterator = new RecursiveIteratorIterator( $filter_iterator, RecursiveIteratorIterator::LEAVES_ONLY );

			foreach ( $iterator as $file ) {
				if ( count( $matches ) >= $max_results ) {
					break;
				}

				if ( ! $file->isFile() || ! $file->isReadable() ) {
					continue;
				}

				// Extension filter.
				$ext = strtolower( $file->getExtension() );
				if ( ! empty( $allowed_ext ) && ! in_array( $ext, $allowed_ext, true ) ) {
					continue;
				}

				// Skip files > 2 MB.
				if ( $file->getSize() > self::MAX_BULK_FILE_SIZE ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				$content = file_get_contents( $file->getPathname() );
				if ( $content === false ) {
					continue;
				}

				$lines    = explode( "\n", $content );
				$rel_path = ltrim( str_replace( $abspath_prefix, '', $file->getPathname() ), '/' );

				foreach ( $lines as $line_num => $line_text ) {
					if ( count( $matches ) >= $max_results ) {
						break 2;
					}

					$found = false;
					if ( $is_regex ) {
						// Suppress errors from invalid regex.
						$found = @preg_match( $pattern, $line_text ) === 1;
					} else {
						$found = stripos( $line_text, $pattern ) !== false;
					}

					if ( $found ) {
						$match = [
							'file' => $rel_path,
							'line' => $line_num + 1,
							'text' => $line_text,
						];

						if ( $context > 0 ) {
							$start = max( 0, $line_num - $context );
							$end   = min( count( $lines ) - 1, $line_num + $context );

							$match['context_before'] = array_slice( $lines, $start, $line_num - $start );
							$match['context_after']  = array_slice( $lines, $line_num + 1, $end - $line_num );
						}

						$matches[] = $match;
					}
				}
			}
		} catch ( \Exception $e ) {
			return self::error( 'Search failed: ' . $e->getMessage(), 500 );
		}

		return new WP_REST_Response( [
			'pattern'     => $pattern,
			'total'       => count( $matches ),
			'max_results' => $max_results,
			'matches'     => $matches,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 8. file_info
	// -------------------------------------------------------------------------

	/**
	 * Get detailed information about a file or directory.
	 *
	 * @param WP_REST_Request $request Contains: path (required).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function file_info( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || $path === '' ) {
			return self::error( 'The "path" parameter is required.', 400 );
		}

		$abs = BugLens_Bridge_Security::validate_path( $path, 'read' );
		if ( is_wp_error( $abs ) ) {
			return self::error_response( $abs );
		}

		$exists = file_exists( $abs );

		if ( ! $exists ) {
			return new WP_REST_Response( [
				'path'   => $path,
				'exists' => false,
			], 200 );
		}

		$is_dir  = is_dir( $abs );
		$is_file = is_file( $abs );
		$size    = $is_file ? filesize( $abs ) : 0;

		$info = [
			'path'        => $path,
			'exists'      => true,
			'is_dir'      => $is_dir,
			'is_file'     => $is_file,
			'size'        => $size,
			'modified'    => filemtime( $abs ),
			'permissions' => substr( sprintf( '%o', fileperms( $abs ) ), -4 ),
			'owner'       => function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( fileowner( $abs ) )['name'] ?? (string) fileowner( $abs ) ) : (string) fileowner( $abs ),
			'readable'    => is_readable( $abs ),
			'writable'    => is_writable( $abs ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking writability for info endpoint, not performing file operations.
		];

		if ( $is_file ) {
			$info['extension'] = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
			$info['mime_type'] = function_exists( 'mime_content_type' ) ? mime_content_type( $abs ) : '';

			// Line count for text files under 5 MB.
			if ( $size > 0 && $size <= self::MAX_READ_SIZE ) {
				$ext = $info['extension'];
				if ( ! in_array( $ext, self::BINARY_EXTENSIONS, true ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
					$content      = file_get_contents( $abs );
					$info['lines'] = $content !== false ? substr_count( $content, "\n" ) + 1 : null;
				}
			}
		}

		return new WP_REST_Response( $info, 200 );
	}

	// -------------------------------------------------------------------------
	// 9. diff_file
	// -------------------------------------------------------------------------

	/**
	 * Simple line-by-line diff between provided content and the file on disk.
	 *
	 * @param WP_REST_Request $request Contains: path (required), content (required).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function diff_file( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || $path === '' ) {
			return self::error( 'The "path" parameter is required.', 400 );
		}

		$content = $request->get_param( 'content' );
		if ( $content === null ) {
			return self::error( 'The "content" parameter is required.', 400 );
		}

		$abs = BugLens_Bridge_Security::validate_path( $path, 'read' );
		if ( is_wp_error( $abs ) ) {
			return self::error_response( $abs );
		}

		if ( ! file_exists( $abs ) || ! is_file( $abs ) ) {
			return self::error( 'File not found.', 404 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$existing     = file_get_contents( $abs );
		$old_lines    = explode( "\n", $existing );
		$new_lines    = explode( "\n", $content );
		$max_lines    = max( count( $old_lines ), count( $new_lines ) );
		$changes      = [];

		for ( $i = 0; $i < $max_lines; $i++ ) {
			$old = $old_lines[ $i ] ?? null;
			$new = $new_lines[ $i ] ?? null;

			if ( $old !== $new ) {
				$changes[] = [
					'line' => $i + 1,
					'old'  => $old,
					'new'  => $new,
				];
			}
		}

		return new WP_REST_Response( [
			'path'          => $path,
			'has_changes'   => ! empty( $changes ),
			'total_changes' => count( $changes ),
			'changes'       => $changes,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 10. bulk_read
	// -------------------------------------------------------------------------

	/**
	 * Read multiple files in one request (max 20, max 2 MB each).
	 *
	 * @param WP_REST_Request $request Contains: paths (array, required).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bulk_read( WP_REST_Request $request ) {
		$paths = $request->get_param( 'paths' );
		if ( ! is_array( $paths ) || empty( $paths ) ) {
			return self::error( 'The "paths" parameter must be a non-empty array.', 400 );
		}

		if ( count( $paths ) > self::MAX_BULK_FILES ) {
			return self::error( 'Maximum ' . self::MAX_BULK_FILES . ' files per request.', 400 );
		}

		$files = [];

		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || $path === '' ) {
				$files[] = [ 'path' => $path, 'error' => 'Invalid path.' ];
				continue;
			}

			$abs = BugLens_Bridge_Security::validate_path( $path, 'read' );
			if ( is_wp_error( $abs ) ) {
				$files[] = [ 'path' => $path, 'error' => $abs->get_error_message() ];
				continue;
			}

			if ( ! file_exists( $abs ) || ! is_file( $abs ) ) {
				$files[] = [ 'path' => $path, 'error' => 'File not found.' ];
				continue;
			}

			$size = filesize( $abs );
			if ( $size > self::MAX_BULK_FILE_SIZE ) {
				$files[] = [ 'path' => $path, 'error' => 'File exceeds 2 MB limit.' ];
				continue;
			}

			$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::BINARY_EXTENSIONS, true ) ) {
				$files[] = [ 'path' => $path, 'error' => 'Binary file — content not returned.' ];
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$content = file_get_contents( $abs );
			if ( $content === false ) {
				$files[] = [ 'path' => $path, 'error' => 'Failed to read file.' ];
				continue;
			}

			$files[] = [
				'path'    => $path,
				'content' => $content,
				'size'    => $size,
			];
		}

		return new WP_REST_Response( [ 'files' => $files ], 200 );
	}

	// -------------------------------------------------------------------------
	// 11. directory_tree
	// -------------------------------------------------------------------------

	/**
	 * Build a recursive directory tree.
	 *
	 * @param WP_REST_Request $request Contains: path, depth (default 3, max 10), pattern.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function directory_tree( WP_REST_Request $request ) {
		$path  = $request->get_param( 'path' ) ?? '';
		$depth = min( intval( $request->get_param( 'depth' ) ?? 3 ), self::MAX_TREE_DEPTH );
		$depth = max( 1, $depth );

		$file_pattern = $request->get_param( 'pattern' ) ?? '';

		if ( $path !== '' ) {
			$abs = BugLens_Bridge_Security::validate_path( $path, 'read' );
			if ( is_wp_error( $abs ) ) {
				return self::error_response( $abs );
			}
		} else {
			$abs = rtrim( ABSPATH, '/' );
		}

		if ( ! is_dir( $abs ) ) {
			return self::error( 'Directory not found.', 404 );
		}

		$tree = self::build_tree( $abs, $depth, 0, $file_pattern );

		return new WP_REST_Response( [
			'path'  => $path ?: '/',
			'depth' => $depth,
			'tree'  => $tree,
		], 200 );
	}

	/**
	 * Recursively build a directory tree.
	 *
	 * @param string $dir           Absolute directory path.
	 * @param int    $max_depth     Maximum recursion depth.
	 * @param int    $current_depth Current recursion depth.
	 * @param string $pattern       Optional file extension filter (e.g. '*.php').
	 * @return array<int, array<string, mixed>> Tree nodes.
	 */
	private static function build_tree( string $dir, int $max_depth, int $current_depth, string $pattern = '' ): array {
		$entries = scandir( $dir );
		if ( $entries === false ) {
			return [];
		}

		$abspath_prefix = rtrim( ABSPATH, '/' );
		$nodes          = [];
		$dirs_list      = [];
		$files_list     = [];

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}

			$full   = $dir . '/' . $entry;
			$is_dir = is_dir( $full );
			$rel    = ltrim( str_replace( $abspath_prefix, '', $full ), '/' );

			if ( $is_dir ) {
				// Skip common large/irrelevant directories at any depth.
				if ( in_array( $entry, self::SKIP_DIRS, true ) ) {
					continue;
				}

				$node = [
					'name'     => $entry,
					'path'     => $rel,
					'is_dir'   => true,
					'children' => [],
				];

				if ( $current_depth < $max_depth - 1 ) {
					$node['children'] = self::build_tree( $full, $max_depth, $current_depth + 1, $pattern );
				}

				$dirs_list[] = $node;
			} else {
				// Apply pattern filter on files if specified.
				if ( $pattern !== '' ) {
					$patterns = array_filter( array_map( 'trim', explode( ',', $pattern ) ) );
					$match    = false;
					foreach ( $patterns as $p ) {
						if ( fnmatch( $p, $entry ) ) {
							$match = true;
							break;
						}
					}
					if ( ! $match ) {
						continue;
					}
				}

				$files_list[] = [
					'name'   => $entry,
					'path'   => $rel,
					'is_dir' => false,
					'size'   => filesize( $full ),
				];
			}
		}

		// Sort dirs and files alphabetically.
		usort( $dirs_list, function ( array $a, array $b ): int {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		usort( $files_list, function ( array $a, array $b ): int {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return array_merge( $dirs_list, $files_list );
	}

	// -------------------------------------------------------------------------
	// 12. wp_cli
	// -------------------------------------------------------------------------

	/**
	 * Execute a WP-CLI command.
	 *
	 * Returns 501 if proc_open is unavailable or WP-CLI binary not found.
	 *
	 * @param WP_REST_Request $request Contains: command (required).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function wp_cli( WP_REST_Request $request ) {
		$command = $request->get_param( 'command' );
		if ( ! is_string( $command ) || $command === '' ) {
			return self::error( 'The "command" parameter is required.', 400 );
		}

		if ( ! function_exists( 'proc_open' ) ) {
			return self::error( 'WP-CLI is not available: proc_open is disabled.', 501 );
		}

		// Find wp-cli binary.
		$wp_bin    = null;
		$candidates = [
			'/usr/local/bin/wp',
			'/usr/bin/wp',
			ABSPATH . 'wp-cli.phar',
		];

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) && is_executable( $candidate ) ) {
				$wp_bin = $candidate;
				break;
			}
			if ( file_exists( $candidate ) && str_ends_with( $candidate, '.phar' ) ) {
				$wp_bin = 'php ' . escapeshellarg( $candidate );
				break;
			}
		}

		if ( $wp_bin === null ) {
			return self::error( 'WP-CLI binary not found.', 501 );
		}

		$full_command = $wp_bin . ' ' . $command . ' --path=' . escapeshellarg( ABSPATH );

		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- WP-CLI execution requires proc_open. Admin-only with API key auth.
		$process = proc_open( $full_command, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return self::error( 'Failed to execute WP-CLI command.', 500 );
		}

		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$stdout = '';
		$stderr = '';
		$start  = time();

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		while ( true ) {
			$read   = [ $pipes[1], $pipes[2] ];
			$write  = null;
			$except = null;

			if ( time() - $start > 30 ) {
				proc_terminate( $process );
				fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				proc_close( $process );
				return self::error( 'WP-CLI command timed out (30s).', 504 );
			}

			$changed = @stream_select( $read, $write, $except, 1 );
			if ( $changed === false ) {
				break;
			}

			foreach ( $read as $pipe ) {
				$data = fread( $pipe, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( $data !== false && $data !== '' ) {
					if ( $pipe === $pipes[1] ) {
						$stdout .= $data;
					} else {
						$stderr .= $data;
					}
				}
			}

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}
		}

		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$exit_code = proc_close( $process );

		$output = trim( $stdout );
		if ( $stderr !== '' ) {
			$output .= ( $output !== '' ? "\n" : '' ) . trim( $stderr );
		}

		return new WP_REST_Response( [
			'command'   => $command,
			'output'    => $output,
			'exit_code' => $exit_code,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// 13. generate_token
	// -------------------------------------------------------------------------

	/**
	 * Generate a time-limited Bridge token.
	 *
	 * Permission callback only requires API key + IP (not an existing token).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public static function generate_token( WP_REST_Request $request ): WP_REST_Response {
		$result   = BugLens_Bridge_Security::generate_token();
		$settings = BugLens_Bridge_Security::get_settings();

		return new WP_REST_Response( [
			'token'      => $result['token'],
			'expires_in' => intval( $settings['token_lifetime'] ?? 3600 ),
		], 200 );
	}

	// -------------------------------------------------------------------------
	// Error helpers
	// -------------------------------------------------------------------------

	/**
	 * Create an error response with a message and HTTP status code.
	 *
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	private static function error( string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response( [ 'error' => $message ], $status );
	}

	/**
	 * Convert a WP_Error into a REST response, preserving the HTTP status.
	 *
	 * @param WP_Error $error The WP_Error instance.
	 * @return WP_REST_Response
	 */
	private static function error_response( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		return new WP_REST_Response( [ 'error' => $error->get_error_message() ], $status );
	}
}
