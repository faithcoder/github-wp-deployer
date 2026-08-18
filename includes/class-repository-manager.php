<?php
/**
 * Managed repository registry.
 *
 * @package PushWP
 */

namespace PushWP;

use PushWP\Utils\WebhookSignature;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the repositories this plugin manages.
 */
final class RepositoryManager {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Add a new managed repository.
	 *
	 * @param array $data Repository record data.
	 * @return array Record with generated fields.
	 */
	public function add( array $data ) {
		$data['id']             = $this->generate_id();
		$data['webhook_secret'] = WebhookSignature::generate_secret();
		$data['secret_shown']   = false;
		$data['created']        = time();
		$data['updated']        = time();
		$data['status']         = 'new';

		$repos   = $this->settings->get_repos();
		$repos[] = $data;

		$this->settings->save_repos( $repos );

		return $data;
	}

	/**
	 * Update an existing record.
	 *
	 * @param string $id   Record ID.
	 * @param array  $data Fields to merge.
	 * @return array|false Updated record or false.
	 */
	public function update( $id, array $data ) {
		$repos = $this->settings->get_repos();

		foreach ( $repos as $index => $record ) {
			if ( isset( $record['id'] ) && hash_equals( (string) $record['id'], (string) $id ) ) {
				$data['updated'] = time();
				$repos[ $index ] = array_merge( $record, $data );
				$this->settings->save_repos( $repos );

				return $repos[ $index ];
			}
		}

		return false;
	}

	/**
	 * Get a record by ID.
	 *
	 * @param string $id Record ID.
	 * @return array|null
	 */
	public function get( $id ) {
		foreach ( $this->settings->get_repos() as $record ) {
			if ( isset( $record['id'] ) && hash_equals( (string) $record['id'], (string) $id ) ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Get all records.
	 *
	 * @return array<int, array>
	 */
	public function get_all() {
		return $this->settings->get_repos();
	}

	/**
	 * Find a managed repository by destination slug and type.
	 *
	 * @param string $slug Destination slug.
	 * @param string $type Package type.
	 * @return array|null
	 */
	public function find_by_slug_type( $slug, $type ) {
		foreach ( $this->settings->get_repos() as $record ) {
			if ( isset( $record['slug'], $record['type'] ) && $record['slug'] === $slug && $record['type'] === $type ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Remove a record (does not touch installed files).
	 *
	 * @param string $id Record ID.
	 * @return bool
	 */
	public function remove( $id ) {
		$repos = $this->settings->get_repos();

		foreach ( $repos as $index => $record ) {
			if ( isset( $record['id'] ) && hash_equals( (string) $record['id'], (string) $id ) ) {
				unset( $repos[ $index ] );
				$this->settings->save_repos( array_values( $repos ) );

				return true;
			}
		}

		return false;
	}

	/**
	 * Record a successful deployment.
	 *
	 * @param string $id      Record ID.
	 * @param string $sha     Deployed commit SHA.
	 * @param string $version Deployed version.
	 * @param int    $user_id Initiating user ID.
	 * @return array|false
	 */
	public function mark_deployed( $id, $sha, $version, $user_id ) {
		return $this->update(
			$id,
			array(
				'deployed_sha'      => $sha,
				'installed_version' => $version,
				'last_user'         => $user_id,
				'last_deployed'     => time(),
				'status'            => 'up_to_date',
			)
		);
	}

	/**
	 * Generate a unique repository ID.
	 *
	 * @return string
	 */
	private function generate_id() {
		return wp_generate_password( 16, false, false );
	}
}
