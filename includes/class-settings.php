<?php
/**
 * Settings persistence via the WordPress Options API.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central store for tokens, managed repositories, and logs.
 */
final class Settings {

	const OPTION_TOKEN               = 'gwp_deployer_token';
	const OPTION_USERNAME            = 'gwp_deployer_username';
	const OPTION_REPOS               = 'gwp_deployer_repos';
	const OPTION_LOGS                = 'gwp_deployer_logs';
	const OPTION_DELETE_ON_UNINSTALL = 'gwp_deployer_delete_on_uninstall';
	const OPTION_LOG_LIMIT           = 'gwp_deployer_log_limit';
	const OPTION_DELIVERIES          = 'gwp_deployer_webhook_deliveries';
	const OPTION_CLIENT_ID           = 'gwp_deployer_client_id';
	const OPTION_CLIENT_SECRET       = 'gwp_deployer_client_secret';

	const DEFAULT_LOG_LIMIT = 100;

	/**
	 * Store the GitHub access token, encrypted when possible.
	 *
	 * @param string $token Raw access token.
	 * @return bool True when encrypted, false when stored in fallback form.
	 */
	public function save_token( $token ) {
		return $this->store_secret( self::OPTION_TOKEN, $token );
	}

	/**
	 * Retrieve the decrypted GitHub access token.
	 *
	 * @return string Empty string when none is stored.
	 */
	public function get_token() {
		return $this->read_secret( self::OPTION_TOKEN );
	}

	/**
	 * Store a secret option, encrypted when libsodium is available.
	 *
	 * @param string $key   Option key.
	 * @param string $value Secret value.
	 * @return bool True when encrypted, false when stored in fallback form.
	 */
	private function store_secret( $key, $value ) {
		$encrypted = Encryption::encrypt( $value );

		if ( false !== $encrypted ) {
			update_option( $key, $encrypted, false );

			return true;
		}

		// Fallback: no libsodium. Store plainly with an explicit marker.
		update_option( $key, 'plain:' . base64_encode( $value ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding the fallback secret for safe storage.

		return false;
	}

	/**
	 * Read and decrypt a secret option.
	 *
	 * @param string $key Option key.
	 * @return string Empty string when none is stored or decryption fails.
	 */
	private function read_secret( $key ) {
		$stored = get_option( $key, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, Encryption::PREFIX ) ) {
			$plaintext = Encryption::decrypt( $stored );

			return ( false === $plaintext ) ? '' : $plaintext;
		}

		if ( 0 === strpos( $stored, 'plain:' ) ) {
			$decoded = base64_decode( substr( $stored, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the stored fallback secret.

			return ( false === $decoded ) ? '' : $decoded;
		}

		return '';
	}

	/**
	 * Whether the stored token was saved with encryption.
	 *
	 * @return bool
	 */
	public function token_is_encrypted() {
		$stored = get_option( self::OPTION_TOKEN, '' );

		return is_string( $stored ) && 0 === strpos( $stored, Encryption::PREFIX );
	}

	/**
	 * Remove the stored token.
	 *
	 * @return void
	 */
	public function delete_token() {
		delete_option( self::OPTION_TOKEN );
	}

	/**
	 * Save the authenticated GitHub username.
	 *
	 * @param string $username Username.
	 * @return void
	 */
	public function save_username( $username ) {
		update_option( self::OPTION_USERNAME, sanitize_text_field( $username ), false );
	}

	/**
	 * Get the authenticated GitHub username.
	 *
	 * @return string
	 */
	public function get_username() {
		$username = get_option( self::OPTION_USERNAME, '' );

		return is_string( $username ) ? $username : '';
	}

	/**
	 * Get the stored GitHub OAuth client ID.
	 *
	 * @return string
	 */
	public function get_client_id() {
		$client_id = get_option( self::OPTION_CLIENT_ID, '' );

		return is_string( $client_id ) ? $client_id : '';
	}

	/**
	 * Save the GitHub OAuth client ID.
	 *
	 * @param string $client_id Client ID.
	 * @return void
	 */
	public function save_client_id( $client_id ) {
		update_option( self::OPTION_CLIENT_ID, sanitize_text_field( $client_id ), false );
	}

	/**
	 * Get the decrypted GitHub OAuth client secret.
	 *
	 * @return string Empty string when none is stored.
	 */
	public function get_client_secret() {
		return $this->read_secret( self::OPTION_CLIENT_SECRET );
	}

	/**
	 * Save the GitHub OAuth client secret, encrypted when possible.
	 *
	 * @param string $client_secret Client secret.
	 * @return void
	 */
	public function save_client_secret( $client_secret ) {
		$this->store_secret( self::OPTION_CLIENT_SECRET, $client_secret );
	}

	/**
	 * Whether a token is currently stored.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->get_token();
	}

	/**
	 * Get all managed repositories.
	 *
	 * @return array<int, array>
	 */
	public function get_repos() {
		$repos = get_option( self::OPTION_REPOS, array() );

		return is_array( $repos ) ? array_values( $repos ) : array();
	}

	/**
	 * Persist the managed repository list.
	 *
	 * @param array<int, array> $repos Repository records.
	 * @return void
	 */
	public function save_repos( array $repos ) {
		update_option( self::OPTION_REPOS, array_values( $repos ), false );
	}

	/**
	 * Get the deployment log entries (newest first).
	 *
	 * @return array<int, array>
	 */
	public function get_logs() {
		$logs = get_option( self::OPTION_LOGS, array() );

		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Persist the deployment log.
	 *
	 * @param array<int, array> $logs Log entries.
	 * @return void
	 */
	public function save_logs( array $logs ) {
		update_option( self::OPTION_LOGS, $logs, false );
	}

	/**
	 * Maximum number of retained log entries.
	 *
	 * @return int
	 */
	public function get_log_limit() {
		$limit = get_option( self::OPTION_LOG_LIMIT, self::DEFAULT_LOG_LIMIT );

		return max( 1, (int) $limit );
	}

	/**
	 * Persist the log limit.
	 *
	 * @param int $limit Positive integer.
	 * @return void
	 */
	public function set_log_limit( $limit ) {
		update_option( self::OPTION_LOG_LIMIT, max( 1, (int) $limit ), false );
	}

	/**
	 * Whether data should be deleted on uninstall.
	 *
	 * @return bool
	 */
	public function is_delete_on_uninstall() {
		return (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, false );
	}

	/**
	 * Persist the delete-on-uninstall flag.
	 *
	 * @param bool $enabled Flag.
	 * @return void
	 */
	public function set_delete_on_uninstall( $enabled ) {
		update_option( self::OPTION_DELETE_ON_UNINSTALL, (bool) $enabled, false );
	}

	/**
	 * Delete every option stored by the plugin.
	 *
	 * @return void
	 */
	public function delete_all() {
		delete_option( self::OPTION_TOKEN );
		delete_option( self::OPTION_USERNAME );
		delete_option( self::OPTION_REPOS );
		delete_option( self::OPTION_LOGS );
		delete_option( self::OPTION_DELETE_ON_UNINSTALL );
		delete_option( self::OPTION_LOG_LIMIT );
		delete_option( self::OPTION_DELIVERIES );
		delete_option( self::OPTION_CLIENT_ID );
		delete_option( self::OPTION_CLIENT_SECRET );
		delete_transient( GitHubAuth::STATE_TRANSIENT );
		delete_transient( Installer::LOCK_TRANSIENT );
	}
}
