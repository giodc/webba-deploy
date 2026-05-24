<?php
/**
 * Enqueue admin styles and scripts.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

/**
 * Loads assets on plugin admin pages only.
 */
class Assets {

	private const SCREENS = array(
		'toplevel_page_webba-deploy',
		'webba-deploy_page_webba-deploy-add-plugin',
		'webba-deploy_page_webba-deploy-add-theme',
		'webba-deploy_page_webba-deploy-settings',
		'webba-deploy_page_webba-deploy-github',
	);

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::SCREENS, true ) ) {
			return;
		}

		wp_enqueue_style(
			'webba-deploy-admin',
			WEBBA_DEPLOY_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WEBBA_DEPLOY_VERSION
		);

		wp_enqueue_script(
			'webba-deploy-admin',
			WEBBA_DEPLOY_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			WEBBA_DEPLOY_VERSION,
			true
		);
	}
}
