<?php
/**
 * Uninstall Webba Deploy — removes plugin data when opted in.
 *
 * @package WebbaDeploy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$webba_deploy_delete_data = get_option( 'webba_deploy_delete_on_uninstall', false );

if ( ! $webba_deploy_delete_data ) {
	return;
}

$webba_deploy_options = array(
	'webba_deploy_github_app_credentials',
	'webba_deploy_github',
	'webba_deploy_site_secret',
	'webba_deploy_plugins',
	'webba_deploy_themes',
	'webba_deploy_settings',
	'webba_deploy_logs',
	'webba_deploy_delete_on_uninstall',
);

foreach ( $webba_deploy_options as $webba_deploy_option ) {
	delete_option( $webba_deploy_option );
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_webba_deploy_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_webba_deploy_' ) . '%'
	)
);
