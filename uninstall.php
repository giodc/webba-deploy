<?php
/**
 * Uninstall Webba Deploy — removes plugin data when opted in.
 *
 * @package WebbaDeploy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$delete_data = get_option( 'webba_deploy_delete_on_uninstall', false );

if ( ! $delete_data ) {
	return;
}

$options = array(
	'webba_deploy_github_app_credentials',
	'webba_deploy_github',
	'webba_deploy_site_secret',
	'webba_deploy_plugins',
	'webba_deploy_themes',
	'webba_deploy_settings',
	'webba_deploy_logs',
	'webba_deploy_delete_on_uninstall',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
