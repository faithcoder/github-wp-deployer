<?php
/**
 * Plugin bootstrap.
 *
 * @package PushWP
 */

namespace PushWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service instances.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Activate: schedule the update-check cron.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once PUSHWP_PLUGIN_DIR . 'includes/class-update-checker.php';
		UpdateChecker::schedule();
	}

	/**
	 * Deactivate: remove the update-check cron.
	 *
	 * @return void
	 */
	public static function deactivate() {
		UpdateChecker::unschedule();
		wp_clear_scheduled_hook( UpdateChecker::CRON_DEPLOY );
	}

	/**
	 * Load the service graph and register hooks.
	 *
	 * @return void
	 */
	private function boot() {
		$this->services['settings']  = new Settings();
		$this->services['logger']    = new Logger( $this->services['settings'] );
		$this->services['github']    = new GitHubClient( $this->services['settings'] );
		$this->services['auth']      = new GitHubAuth( $this->services['settings'], $this->services['github'] );
		$this->services['repos']     = new RepositoryManager( $this->services['settings'] );
		$this->services['inspector'] = new PackageInspector();
		$this->services['installer'] = new Installer( $this->services['repos'], $this->services['inspector'], $this->services['logger'], $this->services['github'] );
		$this->services['checker']   = new UpdateChecker( $this->services['settings'], $this->services['repos'], $this->services['github'], $this->services['installer'], $this->services['logger'] );
		$this->services['webhook']   = new Webhook( $this->services['repos'], $this->services['logger'] );
		$this->services['admin']     = new AdminUI( $this->services['settings'], $this->services['auth'], $this->services['repos'], $this->services['github'], $this->services['checker'], $this->services['installer'], $this->services['logger'] );
	}

	/**
	 * Get a registered service.
	 *
	 * @param string $name Service key.
	 * @return object|null
	 */
	public function service( $name ) {
		return isset( $this->services[ $name ] ) ? $this->services[ $name ] : null;
	}
}
