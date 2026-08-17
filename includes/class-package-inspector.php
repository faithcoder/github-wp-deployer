<?php
/**
 * Safe extraction and inspection of downloaded packages.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts archives safely and detects themes/plugins without executing PHP.
 */
final class PackageInspector {

	/**
	 * Safely extract an archive into a destination directory.
	 *
	 * @param string $zip_path Archive path.
	 * @param string $dest_dir Destination directory.
	 * @return true|WP_Error
	 */
	public function extract_archive( $zip_path, $dest_dir ) {
		if ( ! file_exists( $zip_path ) ) {
			return new \WP_Error( 'archive_missing', __( 'The archive file could not be found.', 'github-wp-deployer' ) );
		}

		wp_mkdir_p( $dest_dir );

		if ( class_exists( 'ZipArchive' ) ) {
			return $this->extract_with_ziparchive( $zip_path, $dest_dir );
		}

		return $this->extract_with_pclzip( $zip_path, $dest_dir );
	}

	/**
	 * Extract using PHP's ZipArchive with strict entry validation.
	 *
	 * @param string $zip_path Archive path.
	 * @param string $dest_dir Destination directory.
	 * @return true|WP_Error
	 */
	private function extract_with_ziparchive( $zip_path, $dest_dir ) {
		$zip = new \ZipArchive();
		$res = $zip->open( $zip_path );

		if ( true !== $res ) {
			return new \WP_Error( 'archive_invalid', __( 'The downloaded archive is not a valid ZIP file.', 'github-wp-deployer' ) );
		}

		$count = $zip->count();

		for ( $i = 0; $i < $count; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( false === $name || ! $this->is_safe_entry( $name ) ) {
				$zip->close();

				return new \WP_Error( 'archive_unsafe', __( 'The archive contains unsafe paths and was rejected.', 'github-wp-deployer' ) );
			}
		}

		$ok = $zip->extractTo( $dest_dir );
		$zip->close();

		if ( false === $ok ) {
			return new \WP_Error( 'archive_extract_failed', __( 'The archive could not be extracted.', 'github-wp-deployer' ) );
		}

		return true;
	}

	/**
	 * Extract using WordPress' bundled PclZip.
	 *
	 * @param string $zip_path Archive path.
	 * @param string $dest_dir Destination directory.
	 * @return true|WP_Error
	 */
	private function extract_with_pclzip( $zip_path, $dest_dir ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$archive = new \PclZip( $zip_path );

		$content = $archive->listContent();

		if ( ! is_array( $content ) ) {
			return new \WP_Error( 'archive_invalid', __( 'The downloaded archive is not a valid ZIP file.', 'github-wp-deployer' ) );
		}

		foreach ( $content as $entry ) {
			if ( ! isset( $entry['filename'] ) || ! $this->is_safe_entry( $entry['filename'] ) ) {
				return new \WP_Error( 'archive_unsafe', __( 'The archive contains unsafe paths and was rejected.', 'github-wp-deployer' ) );
			}
		}

		$result = $archive->extract( PCLZIP_OPT_PATH, $dest_dir );

		if ( 0 === $result ) {
			return new \WP_Error( 'archive_extract_failed', __( 'The archive could not be extracted.', 'github-wp-deployer' ) );
		}

		return true;
	}

	/**
	 * Validate a single archive entry path against traversal attacks.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public function is_safe_entry( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return false;
		}

		// Null bytes are never valid in ZIP entry names.
		if ( false !== strpos( $name, "\0" ) ) {
			return false;
		}

		// Windows backslash traversal.
		if ( false !== strpos( $name, '\\' ) ) {
			return false;
		}

		// Parent directory traversal.
		if ( false !== strpos( $name, '..' ) ) {
			return false;
		}

		// Absolute paths and Windows drive letters.
		if ( 0 === strpos( $name, '/' ) || preg_match( '/^[a-zA-Z]:/', $name ) ) {
			return false;
		}

		// Empty path segments.
		if ( false !== strpos( $name, '//' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the inspection root, handling GitHub's outer archive directory
	 * and an optional monorepo subdirectory.
	 *
	 * @param string $extract_dir  Directory the archive was extracted into.
	 * @param string $subdirectory Optional configured subdirectory.
	 * @return string|WP_Error
	 */
	public function resolve_root( $extract_dir, $subdirectory = '' ) {
		$subdirectory = trim( (string) $subdirectory, '/' );

		if ( '' !== $subdirectory ) {
			if ( ! $this->is_safe_entry( $subdirectory ) ) {
				return new \WP_Error( 'subdirectory_invalid', __( 'The subdirectory path is invalid.', 'github-wp-deployer' ) );
			}

			$root = trailingslashit( $extract_dir ) . $subdirectory;

			if ( ! is_dir( $root ) ) {
				return new \WP_Error( 'subdirectory_missing', __( 'The configured subdirectory was not found in the archive.', 'github-wp-deployer' ) );
			}

			return untrailingslashit( $root );
		}

		$entries = $this->scandir( $extract_dir );

		$dirs = array();
		foreach ( $entries as $entry ) {
			$full = trailingslashit( $extract_dir ) . $entry;

			if ( is_dir( $full ) && ! is_link( $full ) ) {
				$dirs[] = $full;
			}
		}

		// GitHub wraps archives in a single outer directory. Strip it.
		if ( 1 === count( $dirs ) && 1 === count( $entries ) ) {
			return untrailingslashit( $dirs[0] );
		}

		return untrailingslashit( $extract_dir );
	}

	/**
	 * Detect the package type, name, and version inside a directory.
	 *
	 * @param string $root         Inspection root directory.
	 * @param string $forced_type  Optional forced type: 'plugin', 'theme', or ''.
	 * @return array|WP_Error
	 */
	public function detect( $root, $forced_type = '' ) {
		$php_files   = $this->find_files( $root, '/\.php$/i' );
		$style_files = $this->find_files( $root, '/^style\.css$/i' );

		$plugins = array();
		foreach ( $php_files as $file ) {
			$headers = $this->parse_headers(
				$file,
				array(
					'name'        => 'Plugin Name',
					'version'     => 'Version',
					'description' => 'Description',
				)
			);

			if ( '' !== $headers['name'] ) {
				$plugins[] = array(
					'file'        => $this->relative_path( $root, $file ),
					'name'        => $headers['name'],
					'version'     => $headers['version'],
					'description' => $headers['description'],
				);
			}
		}

		$themes = array();
		foreach ( $style_files as $file ) {
			$headers = $this->parse_headers(
				$file,
				array(
					'name'        => 'Theme Name',
					'version'     => 'Version',
					'description' => 'Description',
				)
			);

			if ( '' !== $headers['name'] ) {
				$themes[] = array(
					'file'        => $this->relative_path( $root, $file ),
					'name'        => $headers['name'],
					'version'     => $headers['version'],
					'description' => $headers['description'],
				);
			}
		}

		$plugin_count = count( $plugins );
		$theme_count  = count( $themes );

		if ( 'plugin' === $forced_type ) {
			if ( 0 === $plugin_count ) {
				return new \WP_Error( 'no_plugin', __( 'No valid plugin was found in the repository.', 'github-wp-deployer' ) );
			}
			if ( $plugin_count > 1 ) {
				return new \WP_Error( 'ambiguous_plugin', __( 'Multiple plugins were found. Select the correct subdirectory to disambiguate.', 'github-wp-deployer' ) );
			}

			return $this->result_for_plugin( $plugins[0] );
		}

		if ( 'theme' === $forced_type ) {
			if ( 0 === $theme_count ) {
				return new \WP_Error( 'no_theme', __( 'No valid theme was found in the repository.', 'github-wp-deployer' ) );
			}
			if ( $theme_count > 1 ) {
				return new \WP_Error( 'ambiguous_theme', __( 'Multiple themes were found. Select the correct subdirectory to disambiguate.', 'github-wp-deployer' ) );
			}

			return $this->result_for_theme( $themes[0] );
		}

		if ( 0 === $plugin_count && 0 === $theme_count ) {
			return new \WP_Error( 'no_package', __( 'The repository does not contain a recognizable WordPress plugin or theme.', 'github-wp-deployer' ) );
		}

		if ( $plugin_count > 0 && $theme_count > 0 ) {
			return new \WP_Error( 'ambiguous_package', __( 'The repository contains both a plugin and a theme. Choose a deployment type or subdirectory.', 'github-wp-deployer' ) );
		}

		if ( $plugin_count > 1 ) {
			return new \WP_Error( 'ambiguous_plugin', __( 'Multiple plugins were found. Select the correct subdirectory to disambiguate.', 'github-wp-deployer' ) );
		}

		if ( $theme_count > 1 ) {
			return new \WP_Error( 'ambiguous_theme', __( 'Multiple themes were found. Select the correct subdirectory to disambiguate.', 'github-wp-deployer' ) );
		}

		if ( 1 === $plugin_count ) {
			return $this->result_for_plugin( $plugins[0] );
		}

		return $this->result_for_theme( $themes[0] );
	}

	/**
	 * Build a plugin detection result.
	 *
	 * @param array $plugin Plugin data.
	 * @return array
	 */
	private function result_for_plugin( array $plugin ) {
		return array(
			'type'        => 'plugin',
			'name'        => $plugin['name'],
			'version'     => $plugin['version'],
			'description' => $plugin['description'],
			'main_file'   => $plugin['file'],
		);
	}

	/**
	 * Build a theme detection result.
	 *
	 * @param array $theme Theme data.
	 * @return array
	 */
	private function result_for_theme( array $theme ) {
		return array(
			'type'        => 'theme',
			'name'        => $theme['name'],
			'version'     => $theme['version'],
			'description' => $theme['description'],
			'main_file'   => $theme['file'],
		);
	}

	/**
	 * Parse file headers without executing the file (mirrors get_file_data).
	 *
	 * @param string               $file    File path.
	 * @param array<string,string> $headers Header label map, e.g. name => "Plugin Name".
	 * @return array<string,string>
	 */
	public function parse_headers( $file, $headers ) {
		$result = array();

		$content = '';

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Safe, bounded header read kept dependency-free so it can be unit tested without WordPress.
		$fp = @fopen( $file, 'r' );
		if ( $fp ) {
			$content = (string) fread( $fp, 8 * 1024 );
			fclose( $fp );
		}
		// phpcs:enable

		foreach ( $headers as $field => $label ) {
			$result[ $field ] = '';

			if ( '' === $content ) {
				continue;
			}

			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $content, $match ) && isset( $match[1] ) ) {
				$result[ $field ] = $this->cleanup_header( $match[1] );
			}
		}

		return $result;
	}

	/**
	 * Clean a parsed header value.
	 *
	 * @param string $str Raw value.
	 * @return string
	 */
	private function cleanup_header( $str ) {
		return trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $str ) );
	}

	/**
	 * Recursively find files matching a filename regex.
	 *
	 * @param string $dir   Directory.
	 * @param string $regex Filename regex.
	 * @return array<int,string>
	 */
	public function find_files( $dir, $regex ) {
		$found = array();

		$dir = untrailingslashit( $dir );

		if ( ! is_dir( $dir ) ) {
			return $found;
		}

		foreach ( $this->scandir( $dir ) as $entry ) {
			$full = $dir . '/' . $entry;

			if ( is_link( $full ) ) {
				continue;
			}

			if ( is_dir( $full ) ) {
				$found = array_merge( $found, $this->find_files( $full, $regex ) );
			} elseif ( is_file( $full ) && preg_match( $regex, $entry ) ) {
				$found[] = $full;
			}
		}

		return $found;
	}

	/**
	 * List directory entries (excluding dot entries).
	 *
	 * @param string $dir Directory.
	 * @return array<int,string>
	 */
	public function scandir( $dir ) {
		$entries = scandir( $dir );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_values( array_diff( $entries, array( '.', '..' ) ) );
	}

	/**
	 * Compute a forward-slash relative path.
	 *
	 * @param string $root Root directory.
	 * @param string $file Absolute file path.
	 * @return string
	 */
	public function relative_path( $root, $file ) {
		$root = untrailingslashit( $root );
		$file = str_replace( '\\', '/', $file );

		if ( 0 === strpos( $file, $root . '/' ) ) {
			return substr( $file, strlen( $root ) + 1 );
		}

		return basename( $file );
	}
}
