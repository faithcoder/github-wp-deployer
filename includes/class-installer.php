<?php
/**
 * Installation and update orchestration.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer;

use GitHubWPDeployer\Utils\Slug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads, inspects, installs, and rolls back themes and plugins.
 */
final class Installer {

	const LOCK_TRANSIENT = 'gwp_deployer_lock';
	const LOCK_TTL       = 15 * MINUTE_IN_SECONDS;

	/**
	 * Repository manager.
	 *
	 * @var RepositoryManager
	 */
	private $repos;

	/**
	 * Package inspector.
	 *
	 * @var PackageInspector
	 */
	private $inspector;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * GitHub client.
	 *
	 * @var GitHubClient
	 */
	private $github;

	/**
	 * Temporary paths to clean up.
	 *
	 * @var array<int, string>
	 */
	private $cleanup = array();

	/**
	 * Constructor.
	 *
	 * @param RepositoryManager $repos     Repository manager.
	 * @param PackageInspector  $inspector Package inspector.
	 * @param Logger            $logger    Logger.
	 * @param GitHubClient      $github    GitHub client.
	 */
	public function __construct( RepositoryManager $repos, PackageInspector $inspector, Logger $logger, GitHubClient $github ) {
		$this->repos     = $repos;
		$this->inspector = $inspector;
		$this->logger    = $logger;
		$this->github    = $github;
	}

	/**
	 * Validate a repository: download and inspect without installing.
	 *
	 * @param array $repo Repository record.
	 * @return array|WP_Error Array with 'sha' and 'detected' keys.
	 */
	public function validate( array $repo ) {
		$this->cleanup = array();

		try {
			$sha = $this->github->resolve_ref( $repo['owner'], $repo['repo'], $repo['ref'] );

			if ( is_wp_error( $sha ) ) {
				return $sha;
			}

			$zip = $this->temp_file( 'zip' );
			$dir = $this->temp_dir();

			$result = $this->github->download_archive( $repo['owner'], $repo['repo'], $repo['ref'], $zip );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result = $this->inspector->extract_archive( $zip, $dir );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$root = $this->inspector->resolve_root( $dir, isset( $repo['subdirectory'] ) ? $repo['subdirectory'] : '' );

			if ( is_wp_error( $root ) ) {
				return $root;
			}

			$detected = $this->inspector->detect( $root, isset( $repo['type'] ) ? $repo['type'] : '' );

			if ( is_wp_error( $detected ) ) {
				return $detected;
			}

			return array(
				'sha'      => $sha,
				'detected' => $detected,
			);
		} finally {
			$this->cleanup_all();
		}
	}

	/**
	 * Install or update a managed repository.
	 *
	 * @param array $repo            Repository record.
	 * @param int   $user_id         Initiating user ID (0 for webhook/cron).
	 * @param bool  $force_overwrite Allow overwriting an unmanaged package.
	 * @return true|WP_Error
	 */
	public function deploy( array $repo, $user_id, $force_overwrite = false ) {
		$this->cleanup = array();

		if ( $this->is_locked() ) {
			return new \WP_Error( 'deploy_locked', __( 'Another deployment is already in progress. Please wait and try again.', 'github-wp-deployer' ) );
		}

		$this->lock();

		try {
			return $this->do_deploy( $repo, $user_id, $force_overwrite );
		} finally {
			$this->unlock();
			$this->cleanup_all();
		}
	}

	/**
	 * Perform the deployment once the lock is held.
	 *
	 * @param array $repo            Repository record.
	 * @param int   $user_id         User ID.
	 * @param bool  $force_overwrite Force overwrite.
	 * @return true|WP_Error
	 */
	private function do_deploy( array $repo, $user_id, $force_overwrite ) {
		$type = isset( $repo['type'] ) ? $repo['type'] : '';
		$slug = isset( $repo['slug'] ) ? $repo['slug'] : '';

		if ( 'plugin' !== $type && 'theme' !== $type ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid deployment type.', 'github-wp-deployer' ) );
		}

		if ( ! Slug::validate( $slug ) ) {
			return new \WP_Error( 'invalid_slug', __( 'The destination slug is invalid.', 'github-wp-deployer' ) );
		}

		if ( GWPD_SLUG === $slug ) {
			return new \WP_Error( 'self_replace', __( 'This plugin cannot replace itself in version 1.', 'github-wp-deployer' ) );
		}

		$sha = $this->github->resolve_ref( $repo['owner'], $repo['repo'], $repo['ref'] );

		if ( is_wp_error( $sha ) ) {
			$this->log( $repo, '', 'deploy', 'failure', $user_id, 'resolve_ref' );

			return $sha;
		}

		$zip = $this->temp_file( 'zip' );
		$dir = $this->temp_dir();

		$result = $this->github->download_archive( $repo['owner'], $repo['repo'], $repo['ref'], $zip );

		if ( is_wp_error( $result ) ) {
			$this->log( $repo, $sha, 'deploy', 'failure', $user_id, 'download' );

			return $result;
		}

		$result = $this->inspector->extract_archive( $zip, $dir );

		if ( is_wp_error( $result ) ) {
			$this->log( $repo, $sha, 'deploy', 'failure', $user_id, 'extract' );

			return $result;
		}

		$root = $this->inspector->resolve_root( $dir, isset( $repo['subdirectory'] ) ? $repo['subdirectory'] : '' );

		if ( is_wp_error( $root ) ) {
			$this->log( $repo, $sha, 'deploy', 'failure', $user_id, 'resolve_root' );

			return $root;
		}

		$detected = $this->inspector->detect( $root, $type );

		if ( is_wp_error( $detected ) ) {
			$this->log( $repo, $sha, 'deploy', 'failure', $user_id, 'inspect' );

			return $detected;
		}

		// The slug for an update must match the managed destination.
		if ( isset( $repo['slug'] ) && '' !== $repo['slug'] ) {
			$slug = $repo['slug'];
		} else {
			$slug = Slug::from_name( $detected['name'] );

			if ( '' === $slug ) {
				return new \WP_Error( 'slug_derive_failed', __( 'Could not derive a destination slug from the package name.', 'github-wp-deployer' ) );
			}
		}

		$main_file = isset( $repo['main_file'] ) && '' !== $repo['main_file'] ? $repo['main_file'] : $detected['main_file'];

		$destination = $this->destination_path( $type, $slug );

		$installed = $this->deploy_package( $repo, $detected, $root, $slug, $main_file, $destination, $force_overwrite );

		if ( is_wp_error( $installed ) ) {
			$this->log( $repo, $sha, 'deploy', 'failure', $user_id, $installed->get_error_message() );

			return $installed;
		}

		$version          = isset( $detected['version'] ) && '' !== $detected['version'] ? $detected['version'] : '';
		$previous_version = isset( $repo['installed_version'] ) ? $repo['installed_version'] : '';

		$this->repos->update(
			$repo['id'],
			array(
				'type'      => $type,
				'slug'      => $slug,
				'main_file' => $main_file,
			)
		);

		$this->repos->mark_deployed( $repo['id'], $sha, $version, $user_id );

		$this->clear_caches( $destination );

		// Warn when the commit changed but the package version did not increase.
		if ( '' !== $previous_version && '' !== $version && version_compare( $version, $previous_version, '<=' ) && isset( $repo['deployed_sha'] ) && ! hash_equals( (string) $repo['deployed_sha'], (string) $sha ) ) {
			$this->log( $repo, $sha, 'version-warning', 'failure', $user_id, sprintf( /* translators: 1: previous version, 2: new version. */ __( 'Remote commit changed but the package version stayed at %1$s (was %2$s).', 'github-wp-deployer' ), $version, $previous_version ) );
		}

		$this->log( $repo, $sha, 'deploy', 'success', $user_id, $detected['name'] );

		/**
		 * Fires after a successful deployment.
		 *
		 * @param string $type    'plugin' or 'theme'.
		 * @param string $slug    Destination slug.
		 * @param string $version Deployed version.
		 * @param string $sha     Deployed commit SHA.
		 */
		do_action( 'gwp_deployer_deployed', $type, $slug, $version, $sha );

		return true;
	}

	/**
	 * Copy the validated package into place via the core upgrader.
	 *
	 * @param array  $repo            Repository record.
	 * @param array  $detected        Detection result.
	 * @param string $root            Package source directory.
	 * @param string $slug            Destination slug.
	 * @param string $main_file       Plugin main file (relative).
	 * @param string $destination     Destination directory.
	 * @param bool   $force_overwrite Overwrite unmanaged flag.
	 * @return true|WP_Error
	 */
	private function deploy_package( array $repo, array $detected, $root, $slug, $main_file, $destination, $force_overwrite ) {
		$type = $detected['type'];

		$exists        = is_dir( $destination );
		$managed       = $this->repos->find_by_slug_type( $slug, $type );
		$managed_by_us = $managed && isset( $repo['id'], $managed['id'] ) && hash_equals( (string) $managed['id'], (string) $repo['id'] );

		$backup = null;

		if ( $exists && ! $managed_by_us ) {
			if ( $managed ) {
				return new \WP_Error( 'managed_elsewhere', __( 'This destination is managed by a different repository entry and cannot be overwritten.', 'github-wp-deployer' ) );
			}

			if ( ! $force_overwrite ) {
				return new \WP_Error( 'destination_exists', __( 'This destination already exists and is not managed by this plugin. Enable overwrite to replace it.', 'github-wp-deployer' ) );
			}
		}

		// Build a package archive with the desired top-level slug.
		$package_zip = $this->build_package( $root, $slug );

		if ( is_wp_error( $package_zip ) ) {
			return $package_zip;
		}

		$this->init_upgrader_deps();

		// Rollback backup for existing destinations.
		if ( $exists ) {
			$backup = $this->temp_dir( 'backup' );

			$result = $this->copy_dir_recursive( $destination, $backup );

			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'backup_failed', __( 'Could not create a rollback copy of the existing package.', 'github-wp-deployer' ) );
			}
		}

		$skin   = new UpgraderSkin();
		$result = null;

		if ( 'plugin' === $type ) {
			$upgrader = new \Plugin_Upgrader( $skin );

			if ( $exists && $managed_by_us && '' !== $main_file ) {
				$result = $upgrader->upgrade( $slug . '/' . $main_file, array( 'package' => $package_zip ) );
			} else {
				// Fresh install, or confirmed overwrite of an unmanaged package.
				if ( $exists ) {
					$this->remove_dir_recursive( $destination );
				}
				$result = $upgrader->install( $package_zip );
			}
		} else {
			$upgrader = new \Theme_Upgrader( $skin );

			if ( $exists && $managed_by_us ) {
				$result = $upgrader->upgrade( $slug, array( 'package' => $package_zip ) );
			} else {
				if ( $exists ) {
					$this->remove_dir_recursive( $destination );
				}
				$result = $upgrader->install( $package_zip );
			}
		}

		if ( is_wp_error( $result ) || $skin->has_errors() || ! is_dir( $destination ) ) {
			$this->rollback( $destination, $backup, $exists );

			$message = $skin->has_errors() ? implode( ' ', $skin->errors ) : __( 'The package could not be installed.', 'github-wp-deployer' );

			return new \WP_Error( 'install_failed', $message );
		}

		return true;
	}

	/**
	 * Restore the previous package after a failed deployment.
	 *
	 * @param string      $destination Destination directory.
	 * @param string|null $backup      Backup directory, or null.
	 * @param bool        $existed     Whether a package existed before.
	 * @return void
	 */
	private function rollback( $destination, $backup, $existed ) {
		if ( $existed && $backup && is_dir( $backup ) ) {
			$this->remove_dir_recursive( $destination );
			$this->copy_dir_recursive( $backup, $destination );
		} elseif ( ! $existed ) {
			$this->remove_dir_recursive( $destination );
		}
	}

	/**
	 * Compute the filesystem destination for a package.
	 *
	 * @param string $type Package type.
	 * @param string $slug Destination slug.
	 * @return string
	 */
	public function destination_path( $type, $slug ) {
		if ( 'plugin' === $type ) {
			return trailingslashit( WP_PLUGIN_DIR ) . $slug;
		}

		return trailingslashit( get_theme_root() ) . $slug;
	}

	/**
	 * Build a ZIP archive whose top-level directory is the slug.
	 *
	 * @param string $source Source directory.
	 * @param string $slug   Destination slug.
	 * @return string|WP_Error Path to the created ZIP.
	 */
	public function build_package( $source, $slug ) {
		$staging = $this->temp_dir( 'staging' );

		$slug_dir = trailingslashit( $staging ) . $slug;

		$result = $this->copy_dir_recursive( $source, $slug_dir );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$zip_path = $this->temp_file( 'package' );

		$created = $this->create_zip( $slug_dir, $zip_path );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return $zip_path;
	}

	/**
	 * Create a ZIP from a directory.
	 *
	 * @param string $source  Directory to zip.
	 * @param string $dest    Destination zip path.
	 * @return true|WP_Error
	 */
	private function create_zip( $source, $dest ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();

			if ( true !== $zip->open( $dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'zip_create_failed', __( 'Could not create the package archive.', 'github-wp-deployer' ) );
			}

			$base   = untrailingslashit( $source );
			$length = strlen( $base ) + 1;

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				$local = substr( $file->getPathname(), $length );

				if ( $file->isDir() ) {
					$zip->addEmptyDir( $local );
				} else {
					$zip->addFile( $file->getPathname(), $local );
				}
			}

			$zip->close();

			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$archive = new \PclZip( $dest );

		$result = $archive->create( $source, PCLZIP_OPT_REMOVE_PATH, trailingslashit( $source ) );

		if ( 0 === $result ) {
			return new \WP_Error( 'zip_create_failed', __( 'Could not create the package archive.', 'github-wp-deployer' ) );
		}

		return true;
	}

	/**
	 * Ensure upgrader dependencies and the filesystem are loaded.
	 *
	 * @return void
	 */
	private function init_upgrader_deps() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! isset( $GLOBALS['wp_filesystem'] ) ) {
			WP_Filesystem();
		}
	}

	/**
	 * Recursively copy a directory using the WP filesystem.
	 *
	 * @param string $from Source.
	 * @param string $to   Destination.
	 * @return true|WP_Error
	 */
	private function copy_dir_recursive( $from, $to ) {
		$this->init_upgrader_deps();

		wp_mkdir_p( $to );

		$result = \copy_dir( $from, $to );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_dir_recursive( $dir ) {
		$this->init_upgrader_deps();

		if ( isset( $GLOBALS['wp_filesystem'] ) ) {
			$GLOBALS['wp_filesystem']->delete( $dir, true );
		}
	}

	/**
	 * Clear update, plugin, theme, and opcode caches for a directory.
	 *
	 * @param string $dir Directory that was deployed.
	 * @return void
	 */
	public function clear_caches( $dir ) {
		wp_clean_plugins_cache();
		wp_clean_themes_cache();

		if ( function_exists( 'wp_opcache_invalidate' ) ) {
			foreach ( $this->inspector->find_files( $dir, '/\.php$/i' ) as $file ) {
				wp_opcache_invalidate( $file, true );
			}
		}
	}

	/**
	 * Acquire the deployment lock.
	 *
	 * @return void
	 */
	private function lock() {
		set_transient( self::LOCK_TRANSIENT, wp_generate_password( 16, false, false ), self::LOCK_TTL );
	}

	/**
	 * Release the deployment lock.
	 *
	 * @return void
	 */
	private function unlock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Whether the deployment lock is held.
	 *
	 * @return bool
	 */
	public function is_locked() {
		return false !== get_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Create a temporary file path and register it for cleanup.
	 *
	 * @param string $suffix Label.
	 * @return string
	 */
	private function temp_file( $suffix ) {
		$file = tempnam( sys_get_temp_dir(), 'gwp_deployer_' . $suffix . '_' );

		if ( false !== $file ) {
			$this->cleanup[] = $file;
		}

		return (string) $file;
	}

	/**
	 * Create a temporary directory and register it for cleanup.
	 *
	 * @param string $suffix Label.
	 * @return string
	 */
	private function temp_dir( $suffix = 'extract' ) {
		$dir = sys_get_temp_dir() . '/gwp_deployer_' . $suffix . '_' . wp_generate_password( 12, false, false );

		wp_mkdir_p( $dir );

		$this->cleanup[] = $dir;

		return $dir;
	}

	/**
	 * Remove all registered temporary files and directories.
	 *
	 * @return void
	 */
	private function cleanup_all() {
		foreach ( $this->cleanup as $path ) {
			if ( is_dir( $path ) ) {
				$this->remove_dir_recursive( $path );
			} elseif ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		$this->cleanup = array();
	}

	/**
	 * Write a log entry for the given repo.
	 *
	 * @param array  $repo      Repository record.
	 * @param string $sha       Commit SHA.
	 * @param string $operation Operation.
	 * @param string $result    Result.
	 * @param int    $user_id   User ID.
	 * @param string $message   Message.
	 * @return void
	 */
	private function log( array $repo, $sha, $operation, $result, $user_id, $message ) {
		$repository = isset( $repo['owner'], $repo['repo'] ) ? $repo['owner'] . '/' . $repo['repo'] : '';
		$ref        = isset( $repo['ref'] ) ? $repo['ref'] : '';

		$this->logger->add( $repository, $ref, $sha, $operation, $result, (string) $user_id, $message );
	}
}
