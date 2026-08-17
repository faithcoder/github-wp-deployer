<?php
/**
 * Non-interactive upgrader skin.
 *
 * @package GitHubWPDeployer
 */

namespace GitHubWPDeployer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';

/**
 * Silent skin that captures errors instead of rendering to the screen.
 */
final class UpgraderSkin extends \WP_Upgrader_Skin {

	/**
	 * Collected errors.
	 *
	 * @var array<int, string>
	 */
	public $errors = array();

	/**
	 * Render nothing.
	 *
	 * @return void
	 */
	public function header() {}

	/**
	 * Render nothing.
	 *
	 * @return void
	 */
	public function footer() {}

	/**
	 * Capture errors instead of printing.
	 *
	 * @param string|WP_Error|string[] $errors Errors.
	 * @return void
	 */
	public function error( $errors ) {
		if ( is_string( $errors ) ) {
			$this->errors[] = $errors;

			return;
		}

		if ( is_wp_error( $errors ) ) {
			$messages = $errors->get_error_messages();

			foreach ( $messages as $message ) {
				$this->errors[] = $message;
			}

			return;
		}

		foreach ( (array) $errors as $error ) {
			if ( is_string( $error ) ) {
				$this->errors[] = $error;
			}
		}
	}

	/**
	 * Suppress feedback.
	 *
	 * @param string $message Message.
	 * @param mixed  ...$args Optional formatting args.
	 * @return void
	 */
	public function feedback( $message, ...$args ) {}

	/**
	 * Whether any errors were captured.
	 *
	 * @return bool
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}
}
