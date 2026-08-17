<?php
/**
 * Uninstall handler.
 *
 * @package GitHubWPDeployer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only delete data when the administrator explicitly enabled it.
if ( '1' === get_option( 'gwp_deployer_delete_on_uninstall', '' ) ) {
	// Remove scheduled events.
	wp_clear_scheduled_hook( 'gwp_deployer_check_updates' );
	wp_clear_scheduled_hook( 'gwp_deployer_deploy' );

	// Remove options.
	$options = array(
		'gwp_deployer_token',
		'gwp_deployer_username',
		'gwp_deployer_repos',
		'gwp_deployer_logs',
		'gwp_deployer_delete_on_uninstall',
		'gwp_deployer_log_limit',
		'gwp_deployer_webhook_deliveries',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove transient state.
	delete_transient( 'gwp_deployer_oauth_state' );
	delete_transient( 'gwp_deployer_lock' );
	delete_transient( 'gwp_deployer_last_validation' );
}
