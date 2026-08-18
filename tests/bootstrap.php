<?php
/**
 * Lightweight test bootstrap. Defines only the WordPress primitives required
 * by the pure-logic classes under test.
 *
 * @package PushWP
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'PUSHWP_VERSION', '1.0.0' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		if ( is_dir( $dir ) ) {
			return true;
		}

		return @mkdir( $dir, 0755, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

require_once __DIR__ . '/../includes/utils/class-url.php';
require_once __DIR__ . '/../includes/utils/class-slug.php';
require_once __DIR__ . '/../includes/utils/class-ref.php';
require_once __DIR__ . '/../includes/utils/class-webhook-signature.php';
require_once __DIR__ . '/../includes/utils/class-replay-guard.php';
require_once __DIR__ . '/../includes/class-package-inspector.php';
