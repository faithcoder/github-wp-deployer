<?php
/**
 * Scheduled update detection and background deployment.
 *
 * @package PushWP
 */

namespace PushWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Twice-daily update checks plus webhook-triggered deployments.
 */
final class UpdateChecker {

	const CRON_CHECK  = 'pushwp_check_updates';
	const CRON_DEPLOY = 'pushwp_deploy';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Repository manager.
	 *
	 * @var RepositoryManager
	 */
	private $repos;

	/**
	 * GitHub client.
	 *
	 * @var GitHubClient
	 */
	private $github;

	/**
	 * Installer.
	 *
	 * @var Installer
	 */
	private $installer;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings store.
	 * @param RepositoryManager $repos     Repository manager.
	 * @param GitHubClient      $github    GitHub client.
	 * @param Installer         $installer Installer.
	 * @param Logger            $logger    Logger.
	 */
	public function __construct( Settings $settings, RepositoryManager $repos, GitHubClient $github, Installer $installer, Logger $logger ) {
		$this->settings  = $settings;
		$this->repos     = $repos;
		$this->github    = $github;
		$this->installer = $installer;
		$this->logger    = $logger;

		add_action( self::CRON_CHECK, array( $this, 'run_checks' ) );
		add_action( self::CRON_DEPLOY, array( $this, 'background_deploy' ), 10, 2 );
	}

	/**
	 * Schedule the update-check cron.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_CHECK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_CHECK );
		}
	}

	/**
	 * Remove the update-check cron.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_CHECK );
	}

	/**
	 * Check all managed repositories for updates.
	 *
	 * @return void
	 */
	public function run_checks() {
		foreach ( $this->repos->get_all() as $repo ) {
			$result = $this->check_update( $repo );

			if ( ! empty( $result['has_update'] ) && ! empty( $repo['auto_deploy'] ) ) {
				wp_schedule_single_event( time() + 1, self::CRON_DEPLOY, array( $repo['id'], '' ) );
			}
		}
	}

	/**
	 * Check a single repository for an update.
	 *
	 * @param array $repo Repository record.
	 * @return array{has_update:bool,remote_sha:string,error:string}
	 */
	public function check_update( array $repo ) {
		$result = array(
			'has_update' => false,
			'remote_sha' => '',
			'error'      => '',
		);

		$owner = isset( $repo['owner'] ) ? $repo['owner'] : '';
		$name  = isset( $repo['repo'] ) ? $repo['repo'] : '';

		if ( '' === $owner || '' === $name || empty( $repo['ref'] ) ) {
			return $result;
		}

		$ref      = $repo['ref'];
		$ref_type = isset( $repo['ref_type'] ) ? $repo['ref_type'] : 'branch';

		$sha = null;

		if ( 'release' === $ref_type ) {
			$release = $this->github->get_latest_release( $owner, $name, ! empty( $repo['include_prerelease'] ) );

			if ( is_wp_error( $release ) ) {
				$result['error'] = $release->get_error_message();
				$this->repos->update(
					$repo['id'],
					array(
						'last_checked' => time(),
						'status'       => 'error',
					)
				);

				return $result;
			}

			if ( empty( $release['tag_name'] ) ) {
				$result['error'] = __( 'GitHub did not return a valid release tag.', 'pushwp' );
				$this->repos->update(
					$repo['id'],
					array(
						'last_checked' => time(),
						'status'       => 'error',
					)
				);

				return $result;
			}

			$ref = (string) $release['tag_name'];
		}

		$sha = $this->github->resolve_ref( $owner, $name, $ref );

		if ( is_wp_error( $sha ) ) {
			$result['error'] = $sha->get_error_message();
			$this->repos->update(
				$repo['id'],
				array(
					'last_checked' => time(),
					'status'       => 'error',
				)
			);

			return $result;
		}

		$result['remote_sha'] = $sha;

		$deployed_sha = isset( $repo['deployed_sha'] ) ? $repo['deployed_sha'] : '';

		$has_update = ( '' !== $deployed_sha && ! hash_equals( (string) $deployed_sha, (string) $sha ) );

		$result['has_update'] = $has_update;

		$this->repos->update(
			$repo['id'],
			array(
				'last_checked' => time(),
				'remote_sha'   => $sha,
				'status'       => $has_update ? 'update_available' : 'up_to_date',
			)
		);

		return $result;
	}

	/**
	 * Background deployment triggered by webhook or cron.
	 *
	 * @param string $repo_id  Repository ID.
	 * @param string $delivery Webhook delivery ID (may be empty).
	 * @return void
	 */
	public function background_deploy( $repo_id, $delivery = '' ) {
		$repo = $this->repos->get( (string) $repo_id );

		if ( null === $repo ) {
			return;
		}

		$initiator = '' !== $delivery ? 'webhook:' . $delivery : 'cron';

		$result = $this->installer->deploy( $repo, 0, false );

		if ( is_wp_error( $result ) ) {
			$this->logger->add(
				isset( $repo['owner'], $repo['repo'] ) ? $repo['owner'] . '/' . $repo['repo'] : '',
				isset( $repo['ref'] ) ? $repo['ref'] : '',
				'',
				'webhook-deploy',
				'failure',
				$initiator,
				$result->get_error_message()
			);
		}
	}
}
