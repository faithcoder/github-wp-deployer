<?php
/**
 * Plugin Name:       GitHub Theme & Plugin Deployer
 * Plugin URI:        https://github.com/faithcoder/github-wp-deployer
 * Description:       Securely install and update WordPress themes and plugins from GitHub, without FTP or SSH.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            faithcoder
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       github-wp-deployer
 *
 * @package GitHubWPDeployer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GWPD_VERSION', '1.0.0' );
define( 'GWPD_PLUGIN_FILE', __FILE__ );
define( 'GWPD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GWPD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'GWPD_SLUG', 'github-wp-deployer' );
define( 'GWPD_TEXT_DOMAIN', 'github-wp-deployer' );

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'GitHubWPDeployer\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );

		$map = array(
			'Plugin'                  => 'class-plugin.php',
			'Settings'                => 'class-settings.php',
			'Encryption'              => 'class-encryption.php',
			'Logger'                  => 'class-logger.php',
			'GitHubClient'            => 'class-github-client.php',
			'GitHubAuth'              => 'class-github-auth.php',
			'RepositoryManager'       => 'class-repository-manager.php',
			'PackageInspector'        => 'class-package-inspector.php',
			'Installer'               => 'class-installer.php',
			'UpdateChecker'           => 'class-update-checker.php',
			'Webhook'                 => 'class-webhook.php',
			'AdminUI'                 => 'class-admin-ui.php',
			'UpgraderSkin'            => 'class-upgrader-skin.php',
			'Utils\\Url'              => 'utils/class-url.php',
			'Utils\\Slug'             => 'utils/class-slug.php',
			'Utils\\Ref'              => 'utils/class-ref.php',
			'Utils\\WebhookSignature' => 'utils/class-webhook-signature.php',
			'Utils\\ReplayGuard'      => 'utils/class-replay-guard.php',
		);

		if ( ! isset( $map[ $relative ] ) ) {
			return;
		}

		$path = GWPD_PLUGIN_DIR . 'includes/' . $map[ $relative ];

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once GWPD_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'GitHubWPDeployer\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GitHubWPDeployer\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'GitHubWPDeployer\\Plugin', 'instance' ) );
