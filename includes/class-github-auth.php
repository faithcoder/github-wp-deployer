<?php
/**
 * GitHub OAuth web application flow.
 *
 * @package PushWP
 */

namespace PushWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles connecting and disconnecting a GitHub account.
 */
final class GitHubAuth {

	const STATE_TRANSIENT = 'pushwp_oauth_state';

	const ACTION_CONNECT  = 'pushwp_connect';
	const ACTION_CALLBACK = 'pushwp_oauth_callback';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * GitHub client.
	 *
	 * @var GitHubClient
	 */
	private $github;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings store.
	 * @param GitHubClient $github   GitHub client.
	 */
	public function __construct( Settings $settings, GitHubClient $github ) {
		$this->settings = $settings;
		$this->github   = $github;

		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( $this, 'handle_callback' ) );
	}

	/**
	 * GitHub OAuth client ID.
	 *
	 * @return string
	 */
	public function client_id() {
		if ( defined( 'PUSHWP_GITHUB_CLIENT_ID' ) && '' !== (string) PUSHWP_GITHUB_CLIENT_ID ) {
			return (string) PUSHWP_GITHUB_CLIENT_ID;
		}

		return $this->settings->get_client_id();
	}

	/**
	 * GitHub OAuth client secret.
	 *
	 * @return string
	 */
	public function client_secret() {
		if ( defined( 'PUSHWP_GITHUB_CLIENT_SECRET' ) && '' !== (string) PUSHWP_GITHUB_CLIENT_SECRET ) {
			return (string) PUSHWP_GITHUB_CLIENT_SECRET;
		}

		return $this->settings->get_client_secret();
	}

	/**
	 * Whether the client credentials were provided via wp-config constants.
	 *
	 * @return bool
	 */
	public function uses_constants() {
		return ( defined( 'PUSHWP_GITHUB_CLIENT_ID' ) && '' !== (string) PUSHWP_GITHUB_CLIENT_ID )
			|| ( defined( 'PUSHWP_GITHUB_CLIENT_SECRET' ) && '' !== (string) PUSHWP_GITHUB_CLIENT_SECRET );
	}

	/**
	 * Whether OAuth credentials are configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * The exact callback URL to register in the GitHub OAuth App.
	 *
	 * @return string
	 */
	public function callback_url() {
		return add_query_arg( 'action', self::ACTION_CALLBACK, admin_url( 'admin-post.php' ) );
	}

	/**
	 * OAuth scopes requested from GitHub.
	 *
	 * @return string
	 */
	public function scopes() {
		return apply_filters( 'pushwp_oauth_scopes', 'repo' );
	}

	/**
	 * Build the authorization URL (and store a fresh state value).
	 *
	 * @return string
	 */
	public function connect_url() {
		$state = wp_generate_password( 48, false, false );

		set_transient( $this->state_transient_key(), $state, 10 * MINUTE_IN_SECONDS );

		$args = array(
			'client_id'    => $this->client_id(),
			'redirect_uri' => $this->callback_url(),
			'scope'        => $this->scopes(),
			'state'        => $state,
			'allow_signup' => 'false',
		);

		return add_query_arg( $args, 'https://github.com/login/oauth/authorize' );
	}

	/**
	 * Admin-post handler: start the OAuth flow.
	 *
	 * @return void
	 */
	public function handle_connect() {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'install_themes' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect GitHub.', 'pushwp' ) );
		}

		check_admin_referer( 'pushwp_connect' );

		if ( ! $this->is_configured() ) {
			wp_safe_redirect( $this->settings_page_url( 'missing_config' ) );
			exit;
		}

		// The destination is the fixed GitHub OAuth endpoint built by connect_url().
		// wp_safe_redirect() rejects external hosts and would send the user back to wp-admin.
		wp_redirect( $this->connect_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Admin-post handler: OAuth callback.
	 *
	 * @return void
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'install_themes' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect GitHub.', 'pushwp' ) );
		}

		// OAuth callback parameters; no admin nonce applies here.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$state_key      = $this->state_transient_key();
		$expected_state = get_transient( $state_key );
		delete_transient( $state_key );

		if ( '' === $state || ! is_string( $expected_state ) || ! hash_equals( $expected_state, $state ) ) {
			wp_safe_redirect( $this->settings_page_url( 'oauth_state_failed' ) );
			exit;
		}

		if ( '' === $code ) {
			wp_safe_redirect( $this->settings_page_url( 'oauth_denied' ) );
			exit;
		}

		$token = $this->exchange_code( $code );

		if ( is_wp_error( $token ) ) {
			wp_safe_redirect( $this->settings_page_url( 'oauth_failed' ) );
			exit;
		}

		$this->settings->save_token( $token );

		// Resolve the username for display.
		$user = $this->github->get_user();
		if ( ! is_wp_error( $user ) && isset( $user['login'] ) ) {
			$this->settings->save_username( $user['login'] );
		}

		wp_safe_redirect( $this->settings_page_url( 'connected' ) );
		exit;
	}

	/**
	 * Exchange an authorization code for an access token.
	 *
	 * @param string $code Authorization code.
	 * @return string|WP_Error
	 */
	private function exchange_code( $code ) {
		$response = wp_remote_post(
			'https://github.com/login/oauth/access_token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'pushwp/' . PUSHWP_VERSION,
				),
				'body'    => array(
					'client_id'     => $this->client_id(),
					'client_secret' => $this->client_secret(),
					'code'          => $code,
					'redirect_uri'  => $this->callback_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return new \WP_Error( 'oauth_exchange_failed', __( 'Could not exchange the GitHub authorization code for an access token.', 'pushwp' ) );
		}

		return (string) $data['access_token'];
	}

	/**
	 * Disconnect GitHub by removing stored credentials.
	 *
	 * @return void
	 */
	public function disconnect() {
		$this->settings->delete_token();
		delete_option( Settings::OPTION_USERNAME );
	}

	/**
	 * OAuth state transient key scoped to the current administrator.
	 *
	 * @return string
	 */
	private function state_transient_key() {
		return self::STATE_TRANSIENT . '_' . get_current_user_id();
	}

	/**
	 * The plugin settings page URL.
	 *
	 * @param string $notice Optional notice key.
	 * @return string
	 */
	private function settings_page_url( $notice = '' ) {
		$url = add_query_arg( 'tab', 'connection', admin_url( 'admin.php?page=' . PUSHWP_SLUG ) );

		if ( '' !== $notice ) {
			$url = add_query_arg( 'pushwp_notice', rawurlencode( $notice ), $url );
		}

		return $url;
	}
}
