<?php
/**
 * Admin interface.
 *
 * @package PushWP
 */

namespace PushWP;

use PushWP\Utils\Slug;
use PushWP\Utils\Url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the settings screen and processes admin actions.
 */
final class AdminUI {

	const ACTION_NONCE  = 'pushwp_action';
	const VALIDATION_TX = 'pushwp_last_validation';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * GitHub auth.
	 *
	 * @var GitHubAuth
	 */
	private $auth;

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
	 * Update checker.
	 *
	 * @var UpdateChecker
	 */
	private $checker;

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
	 * @param GitHubAuth        $auth      GitHub auth.
	 * @param RepositoryManager $repos     Repository manager.
	 * @param GitHubClient      $github    GitHub client.
	 * @param UpdateChecker     $checker   Update checker.
	 * @param Installer         $installer Installer.
	 * @param Logger            $logger    Logger.
	 */
	public function __construct( Settings $settings, GitHubAuth $auth, RepositoryManager $repos, GitHubClient $github, UpdateChecker $checker, Installer $installer, Logger $logger ) {
		$this->settings  = $settings;
		$this->auth      = $auth;
		$this->repos     = $repos;
		$this->github    = $github;
		$this->checker   = $checker;
		$this->installer = $installer;
		$this->logger    = $logger;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Whether the current user can manage deployments.
	 *
	 * @return bool
	 */
	private function can_manage() {
		return current_user_can( 'install_plugins' ) && current_user_can( 'install_themes' );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'tools.php',
			__( 'PushWP', 'pushwp' ),
			__( 'PushWP', 'pushwp' ),
			'install_plugins',
			PUSHWP_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets on our page.
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_' . PUSHWP_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'pushwp_admin', plugin_dir_url( PUSHWP_PLUGIN_FILE ) . 'assets/css/admin.css', array(), PUSHWP_VERSION );
		wp_enqueue_script( 'pushwp_admin', plugin_dir_url( PUSHWP_PLUGIN_FILE ) . 'assets/js/admin.js', array(), PUSHWP_VERSION, true );
		wp_localize_script(
			'pushwp_admin',
			'pushWPAdmin',
			array(
				'labels'  => array(
					'validate_repo' => __( 'Validating repository…', 'pushwp' ),
					'install'       => __( 'Installing package…', 'pushwp' ),
					'check_update'  => __( 'Checking GitHub…', 'pushwp' ),
					'deploy_now'    => __( 'Deploying latest code…', 'pushwp' ),
					'toggle_auto'   => __( 'Updating automatic deployment…', 'pushwp' ),
					'remove_repo'   => __( 'Removing repository…', 'pushwp' ),
					'clear_logs'    => __( 'Clearing log…', 'pushwp' ),
					'save_settings' => __( 'Saving settings…', 'pushwp' ),
				),
				'working' => __( 'Working…', 'pushwp' ),
				'failed'  => __( 'The request failed. Please try again.', 'pushwp' ),
			)
		);
	}

	/**
	 * Process admin form actions.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['pushwp_action'] ) ) {
			return;
		}

		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage deployments.', 'pushwp' ) );
		}

		check_admin_referer( self::ACTION_NONCE );

		$action = sanitize_key( wp_unslash( $_POST['pushwp_action'] ) );

		switch ( $action ) {
			case 'disconnect':
				$this->auth->disconnect();
				wp_safe_redirect( $this->page_url( 'disconnected', '', 'connection' ) );
				exit;

			case 'validate_repo':
				$this->action_validate();
				break;

			case 'install':
				$this->action_install();
				break;

			case 'deploy_now':
				$this->action_deploy_now();
				break;

			case 'check_update':
				$this->action_check_update();
				break;

			case 'toggle_auto':
				$this->action_toggle_auto();
				break;

			case 'remove_repo':
				$this->action_remove();
				break;

			case 'clear_logs':
				$this->logger->clear();
				wp_safe_redirect( $this->page_url( 'logs_cleared', '', 'logs' ) );
				exit;

			case 'save_oauth':
				$this->action_save_oauth();
				break;

			case 'save_settings':
				$this->action_save_settings();
				break;
		}
	}

	/**
	 * Build a repository record from posted form fields.
	 *
	 * @return array|WP_Error
	 */
	private function repo_from_input() {
		// Nonce verified in handle_actions() before dispatch.
		$url_input = isset( $_POST['pushwp_url'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$branch    = isset( $_POST['pushwp_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_branch'] ) ) : 'main'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ref_type  = isset( $_POST['pushwp_ref_type'] ) ? sanitize_key( wp_unslash( $_POST['pushwp_ref_type'] ) ) : 'branch'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type      = isset( $_POST['pushwp_type'] ) ? sanitize_key( wp_unslash( $_POST['pushwp_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$slug      = isset( $_POST['pushwp_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$subdir    = isset( $_POST['pushwp_subdirectory'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_subdirectory'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $ref_type, array( 'branch', 'tag', 'release' ), true ) ) {
			$ref_type = 'branch';
		}

		if ( ! in_array( $type, array( '', 'plugin', 'theme' ), true ) ) {
			$type = '';
		}

		$parsed = Url::parse( $url_input );

		if ( false === $parsed ) {
			return new \WP_Error( 'invalid_url', __( 'Enter a valid GitHub repository URL, such as https://github.com/owner/repository or owner/repository.', 'pushwp' ) );
		}

		if ( 'release' === $ref_type ) {
			$branch = 'latest';
		} elseif ( ! \PushWP\Utils\Ref::validate( $branch ) ) {
			return new \WP_Error( 'invalid_ref', __( 'The branch or tag name is invalid.', 'pushwp' ) );
		}

		$slug = Slug::sanitize( $slug );

		if ( '' !== $slug && ! Slug::validate( $slug ) ) {
			return new \WP_Error( 'invalid_slug', __( 'The destination slug is invalid.', 'pushwp' ) );
		}

		if ( PUSHWP_SLUG === $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'This plugin cannot manage itself.', 'pushwp' ) );
		}

		$subdir = trim( $subdir, '/' );

		if ( '' !== $subdir && ( false !== strpos( $subdir, '..' ) || false !== strpos( $subdir, '\\' ) || false !== strpos( $subdir, "\0" ) ) ) {
			return new \WP_Error( 'invalid_subdirectory', __( 'The subdirectory path is invalid.', 'pushwp' ) );
		}

		return array(
			'url'          => $url_input,
			'owner'        => $parsed['owner'],
			'repo'         => $parsed['repo'],
			'ref'          => $branch,
			'ref_type'     => $ref_type,
			'type'         => $type,
			'slug'         => $slug,
			'subdirectory' => $subdir,
			'main_file'    => '',
		);
	}

	/**
	 * Handle the "validate repository" action.
	 *
	 * @return void
	 */
	private function action_validate() {
		$record = $this->repo_from_input();

		if ( is_wp_error( $record ) ) {
			wp_safe_redirect( $this->page_url( 'error', $record->get_error_message() ) );
			exit;
		}

		$validation_record = $record;
		$record['id']      = 'validate';

		$result = $this->installer->validate( $record );

		if ( is_wp_error( $result ) ) {
			set_transient(
				$this->validation_transient_key(),
				array(
					'error'  => $result->get_error_message(),
					'record' => $validation_record,
				),
				15 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( $this->page_url( 'error', $result->get_error_message() ) );
			exit;
		}

		set_transient(
			$this->validation_transient_key(),
			array(
				'sha'      => $result['sha'],
				'detected' => $result['detected'],
				'record'   => $validation_record,
			),
			15 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $this->page_url( 'validated' ) );
		exit;
	}

	/**
	 * Handle the "install" action.
	 *
	 * @return void
	 */
	private function action_install() {
		$record = $this->repo_from_input();

		if ( is_wp_error( $record ) ) {
			wp_safe_redirect( $this->page_url( 'error', $record->get_error_message() ) );
			exit;
		}

		$validation = get_transient( $this->validation_transient_key() );
		if ( ! is_array( $validation ) || isset( $validation['error'] ) || ! isset( $validation['record'] ) || $validation['record'] !== $record ) {
			wp_safe_redirect( $this->page_url( 'error', __( 'Repository details changed or have not been validated. Validate this configuration again before installing.', 'pushwp' ) ) );
			exit;
		}

		$force_overwrite = isset( $_POST['pushwp_confirm_overwrite'] ) && '1' === sanitize_key( wp_unslash( $_POST['pushwp_confirm_overwrite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$saved = $this->repos->add( $record );

		$result = $this->installer->deploy( $saved, get_current_user_id(), $force_overwrite );

		if ( is_wp_error( $result ) ) {
			// A failed first installation must not leave a phantom managed record.
			$this->repos->remove( $saved['id'] );
			wp_safe_redirect( $this->page_url( 'error', $result->get_error_message() ) );
			exit;
		}

		delete_transient( $this->validation_transient_key() );

		wp_safe_redirect( $this->page_url( 'installed' ) );
		exit;
	}

	/**
	 * Handle "deploy now" for an existing record.
	 *
	 * @return void
	 */
	private function action_deploy_now() {
		$id = isset( $_POST['pushwp_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$repo = $this->repos->get( $id );

		if ( null === $repo ) {
			wp_safe_redirect( $this->page_url( 'error', __( 'Repository not found.', 'pushwp' ) ) );
			exit;
		}

		$force_overwrite = isset( $_POST['pushwp_confirm_overwrite'] ) && '1' === sanitize_key( wp_unslash( $_POST['pushwp_confirm_overwrite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = $this->installer->deploy( $repo, get_current_user_id(), $force_overwrite );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( $this->page_url( 'error', $result->get_error_message() ) );
			exit;
		}

		wp_safe_redirect( $this->page_url( 'deployed' ) );
		exit;
	}

	/**
	 * Handle "check for update" for an existing record.
	 *
	 * @return void
	 */
	private function action_check_update() {
		$id = isset( $_POST['pushwp_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$repo = $this->repos->get( $id );

		if ( null === $repo ) {
			wp_safe_redirect( $this->page_url( 'error', __( 'Repository not found.', 'pushwp' ) ) );
			exit;
		}

		$result = $this->checker->check_update( $repo );
		if ( ! empty( $result['error'] ) ) {
			wp_safe_redirect( $this->page_url( 'error', $result['error'] ) );
			exit;
		}

		$notice = ! empty( $result['has_update'] ) ? 'update_found' : 'up_to_date';

		wp_safe_redirect( $this->page_url( $notice ) );
		exit;
	}

	/**
	 * Toggle automatic deployment.
	 *
	 * @return void
	 */
	private function action_toggle_auto() {
		$id = isset( $_POST['pushwp_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$repo = $this->repos->get( $id );

		if ( null === $repo ) {
			wp_safe_redirect( $this->page_url( 'error', __( 'Repository not found.', 'pushwp' ) ) );
			exit;
		}

		$this->repos->update( $id, array( 'auto_deploy' => empty( $repo['auto_deploy'] ) ? true : false ) );

		wp_safe_redirect( $this->page_url( 'updated' ) );
		exit;
	}

	/**
	 * Remove a repository from the manager.
	 *
	 * @return void
	 */
	private function action_remove() {
		$id = isset( $_POST['pushwp_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->repos->remove( $id );

		wp_safe_redirect( $this->page_url( 'removed' ) );
		exit;
	}

	/**
	 * Save general settings.
	 *
	 * @return void
	 */
	private function action_save_oauth() {
		// Nonce verified in handle_actions() before dispatch.
		$client_id     = isset( $_POST['pushwp_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$client_secret = isset( $_POST['pushwp_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['pushwp_client_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->settings->save_client_id( $client_id );

		if ( '' !== $client_secret ) {
			$this->settings->save_client_secret( $client_secret );
		}

		if ( $this->auth->is_configured() ) {
			// Saving valid credentials should continue directly into GitHub authorization.
			wp_redirect( $this->auth->connect_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		wp_safe_redirect( $this->page_url( 'missing_config', '', 'connection' ) );
		exit;
	}

	/**
	 * Save general settings.
	 *
	 * @return void
	 */
	private function action_save_settings() {
		$limit = isset( $_POST['pushwp_log_limit'] ) ? absint( wp_unslash( $_POST['pushwp_log_limit'] ) ) : 100; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->settings->set_log_limit( $limit );
		$this->settings->set_delete_on_uninstall( isset( $_POST['pushwp_delete_on_uninstall'] ) && '1' === sanitize_key( wp_unslash( $_POST['pushwp_delete_on_uninstall'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( $this->page_url( 'updated', '', 'settings' ) );
		exit;
	}

	/**
	 * The plugin settings page URL.
	 *
	 * @param string $notice  Notice key.
	 * @param string $message Optional message.
	 * @param string $tab     Optional settings tab.
	 * @return string
	 */
	private function page_url( $notice = '', $message = '', $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . PUSHWP_SLUG );

		if ( '' !== $tab ) {
			$url = add_query_arg( 'tab', sanitize_key( $tab ), $url );
		}

		if ( '' !== $notice ) {
			$url = add_query_arg( 'pushwp_notice', rawurlencode( $notice ), $url );
		}

		if ( '' !== $message ) {
			$url = add_query_arg( 'pushwp_message', rawurlencode( $message ), $url );
		}

		return $url;
	}

	/**
	 * Current user's temporary repository-validation state key.
	 *
	 * @return string
	 */
	private function validation_transient_key() {
		return self::VALIDATION_TX . '_' . get_current_user_id();
	}

	/**
	 * Render admin notices from query params.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! isset( $_GET['page'] ) || PUSHWP_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! isset( $_GET['pushwp_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['pushwp_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'connected'          => __( 'Connected to GitHub successfully.', 'pushwp' ),
			'disconnected'       => __( 'Disconnected from GitHub.', 'pushwp' ),
			'missing_config'     => __( 'GitHub OAuth credentials are not configured. Define PUSHWP_GITHUB_CLIENT_ID and PUSHWP_GITHUB_CLIENT_SECRET in wp-config.php.', 'pushwp' ),
			'oauth_state_failed' => __( 'OAuth state verification failed. Please try connecting again.', 'pushwp' ),
			'oauth_denied'       => __( 'GitHub authorization was not completed.', 'pushwp' ),
			'oauth_failed'       => __( 'Could not obtain a GitHub access token.', 'pushwp' ),
			'validated'          => __( 'Repository validated. Review the detected package below.', 'pushwp' ),
			'installed'          => __( 'Package installed successfully.', 'pushwp' ),
			'deployed'           => __( 'Package deployed successfully.', 'pushwp' ),
			'updated'            => __( 'Settings updated.', 'pushwp' ),
			'removed'            => __( 'Repository removed from the manager. The installed files were left in place.', 'pushwp' ),
			'logs_cleared'       => __( 'Deployment log cleared.', 'pushwp' ),
			'up_to_date'         => __( 'No update available: the deployed commit is current.', 'pushwp' ),
			'update_found'       => __( 'An update is available.', 'pushwp' ),
		);

		if ( 'error' === $notice ) {
			$message = isset( $_GET['pushwp_message'] ) ? sanitize_text_field( wp_unslash( $_GET['pushwp_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );

			return;
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage deployments.', 'pushwp' ) );
		}

		echo '<div class="wrap pushwp">';
		echo '<div class="pushwp-header">';
		echo '<div><h1>' . esc_html__( 'PushWP', 'pushwp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Install, track, and deploy WordPress packages directly from GitHub.', 'pushwp' ) . '</p></div>';
		echo '<span class="pushwp-header__mark dashicons dashicons-cloud-upload" aria-hidden="true"></span>';
		echo '</div>';

		$tab = $this->current_tab();
		$this->render_tabs( $tab );
		echo '<main class="pushwp-panel">';

		switch ( $tab ) {
			case 'connection':
				$this->render_connection();
				$this->render_oauth();
				break;

			case 'logs':
				$this->render_logs();
				break;

			case 'settings':
				$this->render_settings();
				break;

			case 'repositories':
			default:
				$this->render_validation_result();
				$this->render_add_form();
				$this->render_repos_table();
				break;
		}
		echo '</main>';

		echo '</div>';
	}

	/**
	 * Get the selected settings tab.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'repositories'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'repositories', 'connection', 'logs', 'settings' );

		return in_array( $tab, $allowed, true ) ? $tab : 'repositories';
	}

	/**
	 * Render the settings page tab navigation.
	 *
	 * @param string $current Current tab key.
	 * @return void
	 */
	private function render_tabs( $current ) {
		$tabs = array(
			'repositories' => __( 'Repositories', 'pushwp' ),
			'connection'   => __( 'GitHub Connection', 'pushwp' ),
			'logs'         => __( 'Deployment Log', 'pushwp' ),
			'settings'     => __( 'Settings', 'pushwp' ),
		);

		echo '<nav class="nav-tab-wrapper pushwp-tabs" aria-label="' . esc_attr__( 'PushWP sections', 'pushwp' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url   = add_query_arg( 'tab', $key, admin_url( 'admin.php?page=' . PUSHWP_SLUG ) );
			$class = 'nav-tab' . ( $current === $key ? ' nav-tab-active' : '' );
			printf( '<a class="%1$s" href="%2$s">%3$s</a>', esc_attr( $class ), esc_url( $url ), esc_html( $label ) );
		}
		echo '</nav>';
	}

	/**
	 * Render the GitHub connection panel.
	 *
	 * @return void
	 */
	private function render_connection() {
		echo '<h2>' . esc_html__( 'GitHub Connection', 'pushwp' ) . '</h2>';

		if ( $this->settings->is_connected() ) {
			$username = $this->settings->get_username();

			printf(
				'<p>%s <strong>%s</strong></p>',
				esc_html__( 'Connected as:', 'pushwp' ),
				esc_html( '' !== $username ? $username : __( 'GitHub user', 'pushwp' ) )
			);
		} else {
			echo '<p>' . esc_html__( 'Not connected to GitHub.', 'pushwp' ) . '</p>';
		}

		if ( ! $this->auth->is_configured() ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'GitHub OAuth is not configured. Add your credentials in the GitHub OAuth App section below, or define them in wp-config.php:', 'pushwp' );
			echo '<br><code>define( \'PUSHWP_GITHUB_CLIENT_ID\', \'...\' );</code>';
			echo '<br><code>define( \'PUSHWP_GITHUB_CLIENT_SECRET\', \'...\' );</code>';
			echo '</p></div>';
		} else {
			printf(
				'<p>%s <code>%s</code></p>',
				esc_html__( 'OAuth callback URL:', 'pushwp' ),
				esc_html( $this->auth->callback_url() )
			);
		}

		echo '<div class="pushwp-connect-row">';
		if ( ! $this->settings->is_connected() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			wp_nonce_field( 'pushwp_connect' );
			echo '<input type="hidden" name="action" value="' . esc_attr( GitHubAuth::ACTION_CONNECT ) . '">';

			if ( $this->auth->is_configured() ) {
				echo '<button type="submit" class="button button-primary pushwp-authorize">';
				echo '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>';
				echo '<span>' . esc_html__( 'Authorize with GitHub', 'pushwp' ) . '</span>';
				echo '</button> ';
			} else {
				echo '<button type="button" class="button button-primary" disabled>' . esc_html__( 'Authorize with GitHub', 'pushwp' ) . '</button>';
				echo ' <span class="description">' . esc_html__( 'Add your OAuth App credentials below to enable this button.', 'pushwp' ) . '</span>';
			}

			echo '</form>';
		}

		if ( $this->settings->is_connected() ) {
			echo '<form method="post" style="display:inline;">';
			wp_nonce_field( self::ACTION_NONCE );
			echo '<input type="hidden" name="pushwp_action" value="disconnect">';
			submit_button( __( 'Disconnect GitHub', 'pushwp' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';

		if ( ! $this->settings->token_is_encrypted() && $this->settings->is_connected() ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'libsodium is unavailable, so the GitHub token is stored without encryption. Install the PHP sodium extension for encrypted storage.', 'pushwp' );
			echo '</p></div>';
		}

		echo '<p class="description">' . esc_html__( 'Connecting requests the "repo" scope so private repositories can be read. Private repositories must be accessible to the connected GitHub account.', 'pushwp' ) . '</p>';
	}

	/**
	 * Render the GitHub OAuth App credential fields.
	 *
	 * @return void
	 */
	private function render_oauth() {
		echo '<h2>' . esc_html__( 'GitHub OAuth App', 'pushwp' ) . '</h2>';

		echo '<ol class="pushwp-steps">';
		printf(
			'<li>%s <a href="%s" target="_blank" rel="noopener">%s</a> %s</li>',
			esc_html__( 'Go to', 'pushwp' ),
			esc_url( 'https://github.com/settings/developers' ),
			esc_html__( 'GitHub → Settings → Developer settings → OAuth Apps', 'pushwp' ),
			esc_html__( 'and click "New OAuth App".', 'pushwp' )
		);
		printf(
			'<li>%s <code>%s</code></li>',
			esc_html__( 'Set the Authorization callback URL to:', 'pushwp' ),
			esc_html( $this->auth->callback_url() )
		);
		echo '<li>' . esc_html__( 'Click "Register application", then copy the Client ID.', 'pushwp' ) . '</li>';
		echo '<li>' . esc_html__( 'Click "Generate a new client secret" and copy the Client Secret.', 'pushwp' ) . '</li>';
		echo '</ol>';

		if ( $this->auth->uses_constants() ) {
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html__( 'Credentials defined with PUSHWP_GITHUB_CLIENT_ID and PUSHWP_GITHUB_CLIENT_SECRET in wp-config.php take precedence over these fields.', 'pushwp' );
			echo '</p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Paste the Client ID and Client Secret below. You can also define them in wp-config.php, which takes precedence.', 'pushwp' ) . '</p>';
		}

		echo '<form method="post">';
		wp_nonce_field( self::ACTION_NONCE );
		echo '<input type="hidden" name="pushwp_action" value="save_oauth">';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pushwp_client_id">' . esc_html__( 'Client ID', 'pushwp' ) . '</label></th><td>';
		echo '<input type="text" name="pushwp_client_id" id="pushwp_client_id" class="regular-text" value="' . esc_attr( $this->auth->client_id() ) . '">';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pushwp_client_secret">' . esc_html__( 'Client Secret', 'pushwp' ) . '</label></th><td>';
		echo '<input type="password" name="pushwp_client_secret" id="pushwp_client_secret" class="regular-text" autocomplete="off" placeholder="' . esc_attr__( 'Leave blank to keep the current secret', 'pushwp' ) . '">';
		echo '<p class="description">' . esc_html__( 'The stored secret is never displayed.', 'pushwp' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save OAuth Credentials', 'pushwp' ) );
		echo '</form>';
	}

	/**
	 * Render the last validation result.
	 *
	 * @return void
	 */
	private function render_validation_result() {
		$validation = get_transient( $this->validation_transient_key() );

		if ( ! is_array( $validation ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Validation Result', 'pushwp' ) . '</h3>';

		if ( isset( $validation['error'] ) ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $validation['error'] ) );

			return;
		}

		$detected = isset( $validation['detected'] ) ? $validation['detected'] : array();

		echo '<table class="widefat striped" style="max-width:640px;">';
		echo '<tr><th>' . esc_html__( 'Type', 'pushwp' ) . '</th><td>' . esc_html( ucfirst( isset( $detected['type'] ) ? $detected['type'] : '' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Name', 'pushwp' ) . '</th><td>' . esc_html( isset( $detected['name'] ) ? $detected['name'] : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Version', 'pushwp' ) . '</th><td>' . esc_html( isset( $detected['version'] ) ? $detected['version'] : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Main file', 'pushwp' ) . '</th><td>' . esc_html( isset( $detected['main_file'] ) ? $detected['main_file'] : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Commit SHA', 'pushwp' ) . '</th><td><code>' . esc_html( isset( $validation['sha'] ) ? $validation['sha'] : '' ) . '</code></td></tr>';
		echo '</table>';
	}

	/**
	 * Render the add-repository form.
	 *
	 * @return void
	 */
	private function render_add_form() {
		$validation  = get_transient( $this->validation_transient_key() );
		$record      = is_array( $validation ) && isset( $validation['record'] ) && is_array( $validation['record'] ) ? $validation['record'] : array();
		$url         = isset( $record['url'] ) ? $record['url'] : '';
		$branch      = isset( $record['ref'] ) ? $record['ref'] : 'main';
		$ref_type    = isset( $record['ref_type'] ) ? $record['ref_type'] : 'branch';
		$type        = isset( $record['type'] ) ? $record['type'] : '';
		$slug        = isset( $record['slug'] ) ? $record['slug'] : '';
		$subdir      = isset( $record['subdirectory'] ) ? $record['subdirectory'] : '';
		$can_install = is_array( $validation ) && ! isset( $validation['error'] ) && isset( $validation['detected'], $validation['sha'], $validation['record'] );

		echo '<h2>' . esc_html__( 'Add Repository', 'pushwp' ) . '</h2>';

		echo '<form method="post">';
		wp_nonce_field( self::ACTION_NONCE );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pushwp_url">' . esc_html__( 'Repository URL', 'pushwp' ) . '</label></th><td>';
		echo '<input type="text" name="pushwp_url" id="pushwp_url" class="regular-text" value="' . esc_attr( $url ) . '" placeholder="https://github.com/owner/repository" required>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pushwp_branch">' . esc_html__( 'Branch or tag', 'pushwp' ) . '</label></th><td>';
		echo '<input type="text" name="pushwp_branch" id="pushwp_branch" class="regular-text" value="' . esc_attr( $branch ) . '">';
		echo ' <select name="pushwp_ref_type">';
		echo '<option value="branch" ' . selected( $ref_type, 'branch', false ) . '>' . esc_html__( 'Branch', 'pushwp' ) . '</option>';
		echo '<option value="tag" ' . selected( $ref_type, 'tag', false ) . '>' . esc_html__( 'Tag', 'pushwp' ) . '</option>';
		echo '<option value="release" ' . selected( $ref_type, 'release', false ) . '>' . esc_html__( 'Latest stable release', 'pushwp' ) . '</option>';
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pushwp_type">' . esc_html__( 'Deployment type', 'pushwp' ) . '</label></th><td>';
		echo '<select name="pushwp_type" id="pushwp_type">';
		echo '<option value="" ' . selected( $type, '', false ) . '>' . esc_html__( 'Auto-detect', 'pushwp' ) . '</option>';
		echo '<option value="plugin" ' . selected( $type, 'plugin', false ) . '>' . esc_html__( 'Plugin', 'pushwp' ) . '</option>';
		echo '<option value="theme" ' . selected( $type, 'theme', false ) . '>' . esc_html__( 'Theme', 'pushwp' ) . '</option>';
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pushwp_slug">' . esc_html__( 'Destination slug', 'pushwp' ) . '</label></th><td>';
		echo '<input type="text" name="pushwp_slug" id="pushwp_slug" class="regular-text" value="' . esc_attr( $slug ) . '" placeholder="' . esc_attr__( 'Optional; derived from package name when blank', 'pushwp' ) . '">';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pushwp_subdirectory">' . esc_html__( 'Subdirectory', 'pushwp' ) . '</label></th><td>';
		echo '<input type="text" name="pushwp_subdirectory" id="pushwp_subdirectory" class="regular-text" value="' . esc_attr( $subdir ) . '" placeholder="' . esc_attr__( 'Optional; for monorepos', 'pushwp' ) . '">';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p><label><input type="checkbox" name="pushwp_confirm_overwrite" value="1"> ' . esc_html__( 'Overwrite an existing unmanaged theme or plugin at the destination.', 'pushwp' ) . '</label></p>';

		echo '<button type="submit" name="pushwp_action" value="validate_repo" class="button button-secondary">';
		echo esc_html__( 'Validate Repository', 'pushwp' );
		echo '</button> ';
		if ( $can_install ) {
			echo '<button type="submit" name="pushwp_action" value="install" class="button button-primary">';
			echo esc_html__( 'Install', 'pushwp' );
			echo '</button>';
		}

		echo '</form>';
	}

	/**
	 * Render the managed repositories table.
	 *
	 * @return void
	 */
	private function render_repos_table() {
		$repos = $this->repos->get_all();

		echo '<h2>' . esc_html__( 'Managed Repositories', 'pushwp' ) . '</h2>';

		if ( empty( $repos ) ) {
			echo '<p>' . esc_html__( 'No repositories are managed yet.', 'pushwp' ) . '</p>';

			return;
		}

		echo '<div class="pushwp-table-wrap">';
		echo '<table class="widefat striped pushwp-table">';
		echo '<thead><tr>';

		$headings = array(
			__( 'Repository', 'pushwp' ),
			__( 'Type', 'pushwp' ),
			__( 'Ref', 'pushwp' ),
			__( 'Installed', 'pushwp' ),
			__( 'Remote', 'pushwp' ),
			__( 'SHA', 'pushwp' ),
			__( 'Status', 'pushwp' ),
		);

		foreach ( $headings as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Actions', 'pushwp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $repos as $repo ) {
			$this->render_repo_row( $repo );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Render a single repository row.
	 *
	 * @param array $repo Repository record.
	 * @return void
	 */
	private function render_repo_row( array $repo ) {
		$id       = isset( $repo['id'] ) ? $repo['id'] : '';
		$owner    = isset( $repo['owner'] ) ? $repo['owner'] : '';
		$name     = isset( $repo['repo'] ) ? $repo['repo'] : '';
		$ref      = isset( $repo['ref'] ) ? $repo['ref'] : '';
		$ref_type = isset( $repo['ref_type'] ) ? $repo['ref_type'] : 'branch';
		$type     = isset( $repo['type'] ) ? $repo['type'] : '';
		$slug     = isset( $repo['slug'] ) ? $repo['slug'] : '';
		$status   = isset( $repo['status'] ) ? $repo['status'] : '';

		echo '<tr>';

		echo '<td>';
		printf( '<a href="%s" target="_blank" rel="noopener">%s/%s</a>', esc_url( 'https://github.com/' . $owner . '/' . $name ), esc_html( $owner ), esc_html( $name ) );

		if ( '' !== $slug ) {
			printf( '<br><small>%s</small>', esc_html( $slug ) );
		}

		if ( ! empty( $repo['auto_deploy'] ) ) {
			echo '<br><span class="dashicons dashicons-update" aria-hidden="true"></span> ' . esc_html__( 'auto', 'pushwp' );
		}
		echo '</td>';

		echo '<td>' . esc_html( 'theme' === $type ? __( 'Theme', 'pushwp' ) : __( 'Plugin', 'pushwp' ) ) . '</td>';

		echo '<td>' . esc_html( $ref );
		if ( 'release' === $ref_type ) {
			echo '<br><small>' . esc_html__( 'latest release', 'pushwp' ) . '</small>';
		} elseif ( 'tag' === $ref_type ) {
			echo '<br><small>' . esc_html__( 'tag', 'pushwp' ) . '</small>';
		}
		echo '</td>';

		echo '<td>' . esc_html( isset( $repo['installed_version'] ) ? $repo['installed_version'] : '—' ) . '</td>';
		echo '<td>' . esc_html( isset( $repo['remote_sha'] ) && '' !== $repo['remote_sha'] ? substr( $repo['remote_sha'], 0, 7 ) : '—' ) . '</td>';
		echo '<td><code>' . esc_html( isset( $repo['deployed_sha'] ) ? substr( $repo['deployed_sha'], 0, 7 ) : '—' ) . '</code></td>';

		echo '<td><span class="pushwp-status pushwp-status--' . esc_attr( sanitize_html_class( $status ) ) . '">';
		echo esc_html( $this->status_label( $status ) );
		echo '</span></td>';

		echo '<td class="pushwp-actions">';

		echo '<form method="post" style="display:inline;">';
		wp_nonce_field( self::ACTION_NONCE );
		echo '<input type="hidden" name="pushwp_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="pushwp_action" value="check_update">';
		echo '<button class="button button-small">' . esc_html__( 'Check', 'pushwp' ) . '</button>';
		echo '</form> ';

		echo '<form method="post" style="display:inline;">';
		wp_nonce_field( self::ACTION_NONCE );
		echo '<input type="hidden" name="pushwp_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="pushwp_action" value="deploy_now">';
		echo '<button class="button button-small">' . esc_html__( 'Deploy now', 'pushwp' ) . '</button>';
		echo '</form> ';

		echo '<form method="post" style="display:inline;">';
		wp_nonce_field( self::ACTION_NONCE );
		echo '<input type="hidden" name="pushwp_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="pushwp_action" value="toggle_auto">';
		$auto_label = ! empty( $repo['auto_deploy'] ) ? __( 'Disable auto', 'pushwp' ) : __( 'Enable auto', 'pushwp' );
		echo '<button class="button button-small">' . esc_html( $auto_label ) . '</button>';
		echo '</form> ';

		echo '<form method="post" class="pushwp-confirm" data-confirm="' . esc_attr__( 'Remove this repository from the manager? Installed files will not be deleted.', 'pushwp' ) . '" style="display:inline;">';
		wp_nonce_field( self::ACTION_NONCE );
		echo '<input type="hidden" name="pushwp_id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="pushwp_action" value="remove_repo">';
		echo '<button class="button button-small button-link-delete">' . esc_html__( 'Remove', 'pushwp' ) . '</button>';
		echo '</form>';
		echo '<div class="pushwp-action-status"></div>';

		echo '<br>';

		if ( ! empty( $repo['auto_deploy'] ) ) {
			printf(
				'<small>%s <code>%s</code></small>',
				esc_html__( 'Webhook URL:', 'pushwp' ),
				esc_html( $this->webhook_url( $repo ) )
			);

			if ( ! empty( $repo['secret_shown'] ) ) {
				echo '<br><small>' . esc_html__( 'Webhook secret was shown once when configured.', 'pushwp' ) . '</small>';
			}
		}

		echo '</td></tr>';

		if ( ! empty( $repo['auto_deploy'] ) && empty( $repo['secret_shown'] ) ) {
			echo '<tr class="pushwp-secret"><td colspan="8">';
			printf(
				'<strong>%s</strong> <code>%s</code> <em>%s</em>',
				esc_html__( 'Webhook secret (shown once):', 'pushwp' ),
				esc_html( isset( $repo['webhook_secret'] ) ? $repo['webhook_secret'] : '' ),
				esc_html__( 'Copy this now; it will not be shown again.', 'pushwp' )
			);
			echo '</td></tr>';
			$this->repos->update( $id, array( 'secret_shown' => true ) );
		}
	}

	/**
	 * Get the webhook URL for a repo.
	 *
	 * @param array $repo Repository record.
	 * @return string
	 */
	private function webhook_url( array $repo ) {
		$id = isset( $repo['id'] ) ? rawurlencode( $repo['id'] ) : '';

		return rest_url( Webhook::ROUTE_NAMESPACE . '/webhook/' . $id );
	}

	/**
	 * Human-readable status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'new'              => __( 'New', 'pushwp' ),
			'up_to_date'       => __( 'Up to date', 'pushwp' ),
			'update_available' => __( 'Update available', 'pushwp' ),
			'error'            => __( 'Error', 'pushwp' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Render the deployment log.
	 *
	 * @return void
	 */
	private function render_logs() {
		$logs = $this->logger->get_entries();

		echo '<h2>' . esc_html__( 'Deployment Log', 'pushwp' ) . '</h2>';

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No deployments logged yet.', 'pushwp' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';

		$headings = array(
			__( 'Time', 'pushwp' ),
			__( 'Repository', 'pushwp' ),
			__( 'Ref', 'pushwp' ),
			__( 'SHA', 'pushwp' ),
			__( 'Operation', 'pushwp' ),
			__( 'Result', 'pushwp' ),
			__( 'Initiator', 'pushwp' ),
			__( 'Message', 'pushwp' ),
		);

		foreach ( $headings as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $logs as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['repository'] ) ? $entry['repository'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['ref'] ) ? $entry['ref'] : '' ) . '</td>';
			echo '<td><code>' . esc_html( isset( $entry['sha'] ) ? substr( $entry['sha'], 0, 7 ) : '—' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $entry['operation'] ) ? $entry['operation'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['result'] ) ? $entry['result'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['initiator'] ) ? $entry['initiator'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['message'] ) ? $entry['message'] : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:8px;">';
		wp_nonce_field( self::ACTION_NONCE );
		echo '<input type="hidden" name="pushwp_action" value="clear_logs">';
		submit_button( __( 'Clear Log', 'pushwp' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render the settings form.
	 *
	 * @return void
	 */
	private function render_settings() {
		echo '<h2>' . esc_html__( 'Settings', 'pushwp' ) . '</h2>';

		echo '<form method="post">';
		wp_nonce_field( self::ACTION_NONCE );
		echo '<input type="hidden" name="pushwp_action" value="save_settings">';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pushwp_log_limit">' . esc_html__( 'Log entries to keep', 'pushwp' ) . '</label></th><td>';
		echo '<input type="number" min="1" name="pushwp_log_limit" id="pushwp_log_limit" value="' . esc_attr( (string) $this->settings->get_log_limit() ) . '">';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Uninstall behavior', 'pushwp' ) . '</th><td>';
		echo '<label><input type="checkbox" name="pushwp_delete_on_uninstall" value="1" ' . checked( $this->settings->is_delete_on_uninstall(), true, false ) . '> ';
		echo esc_html__( 'Delete all plugin data (tokens, repositories, logs, scheduled events) when the plugin is deleted.', 'pushwp' );
		echo '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save Settings', 'pushwp' ) );
		echo '</form>';
	}
}
