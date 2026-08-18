<?php
/**
 * Uninstall handler.
 *
 * @package PushWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only delete data when the administrator explicitly enabled it.
if ( '1' === get_option( 'pushwp_delete_on_uninstall', '' ) ) {
	// Remove scheduled events.
	wp_clear_scheduled_hook( 'pushwp_check_updates' );
	wp_clear_scheduled_hook( 'pushwp_deploy' );

	// Remove options.
	$pushwp_options = array(
		'pushwp_token',
		'pushwp_username',
		'pushwp_repos',
		'pushwp_logs',
		'pushwp_delete_on_uninstall',
		'pushwp_log_limit',
		'pushwp_webhook_deliveries',
		'pushwp_client_id',
		'pushwp_client_secret',
	);

	foreach ( $pushwp_options as $pushwp_option ) {
		delete_option( $pushwp_option );
	}

	// Remove transient state.
	delete_transient( 'pushwp_oauth_state' );
	delete_transient( 'pushwp_lock' );
	delete_transient( 'pushwp_last_validation' );
}
