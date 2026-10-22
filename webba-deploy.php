<?php
/**
 * Plugin Name:       Webba Deploy
 * Plugin URI:        https://github.com/giodc/webba-deploy
 * Update URI:        https://github.com/giodc/webba-deploy
 * Description:       Deploy plugins and themes from GitHub — public or private — with one-click installs and push-to-deploy.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Giovanni De Carlo
 * Author URI:        https://giodc.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webba-deploy
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WEBBA_DEPLOY_VERSION', '1.0.0' );
define( 'WEBBA_DEPLOY_PLUGIN_FILE', __FILE__ );
define( 'WEBBA_DEPLOY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBBA_DEPLOY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WEBBA_DEPLOY_TEXT_DOMAIN', 'webba-deploy' );

/**
 * Optional local config (copy webba-deploy-config-sample.php → webba-deploy-config.php).
 * Prefer wp-config.php constants in production.
 */
$webba_deploy_config = WEBBA_DEPLOY_PLUGIN_DIR . 'webba-deploy-config.php';

if ( is_readable( $webba_deploy_config ) ) {
	require_once $webba_deploy_config;
}

/**
 * PSR-4 autoloader for WebbaDeploy namespace.
 *
 * @param string $class Fully qualified class name.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WebbaDeploy\\';
		$base   = WEBBA_DEPLOY_PLUGIN_DIR . 'includes/';

		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Opt this plugin into WordPress auto-updates once, unless the site removes it later.
 */
function webba_deploy_enable_auto_update(): void {
	if ( get_option( 'webba_deploy_auto_update_default' ) ) {
		return;
	}

	update_option( 'webba_deploy_auto_update_default', 1, false );

	$plugin = 'webba-deploy/webba-deploy.php';
	$auto   = (array) get_site_option( 'auto_update_plugins', array() );

	if ( in_array( $plugin, $auto, true ) ) {
		return;
	}

	$auto[] = $plugin;

	update_site_option( 'auto_update_plugins', array_values( array_unique( $auto ) ) );
}

/**
 * Plugin activation.
 */
function webba_deploy_activate(): void {
	webba_deploy_enable_auto_update();

	if ( ! get_option( 'webba_deploy_site_secret' ) ) {
		update_option( 'webba_deploy_site_secret', wp_generate_password( 64, true, true ), false );
	}

	if ( false === get_option( 'webba_deploy_plugins' ) ) {
		add_option( 'webba_deploy_plugins', array(), '', false );
	}

	if ( false === get_option( 'webba_deploy_themes' ) ) {
		add_option( 'webba_deploy_themes', array(), '', false );
	}

	if ( false === get_option( 'webba_deploy_settings' ) ) {
		add_option(
			'webba_deploy_settings',
			array(
				'flush_cache' => true,
				'logging'     => true,
			),
			'',
			false
		);
	}
}

register_activation_hook( __FILE__, 'webba_deploy_activate' );

/**
 * Bootstrap plugin.
 */
function webba_deploy_init(): void {
	$plugin = new WebbaDeploy\Plugin();
	$plugin->register();
}

add_action( 'plugins_loaded', 'webba_deploy_init' );
add_action( 'admin_init', 'webba_deploy_enable_auto_update' );
