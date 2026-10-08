<?php
/**
 * Main plugin bootstrap.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy;

use WebbaDeploy\Admin\Assets;
use WebbaDeploy\Admin\Menu;
use WebbaDeploy\GitHub\ConnectController;
use WebbaDeploy\GitHub\ManifestRegistrar;
use WebbaDeploy\Rest\DeployController;
use WebbaDeploy\WordPress\NativeUpdateIntegration;

/**
 * Registers WordPress hooks.
 */
class Plugin {

	/**
	 * Whether hooks are already registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		$github = new ConnectController();
		$github->register();

		$manifest = new ManifestRegistrar();
		$manifest->register();

		( new NativeUpdateIntegration() )->register();

		if ( is_admin() ) {
			$menu = new Menu();
			$menu->register();

			$assets = new Assets();
			$assets->register();
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new DeployController() )->register();
			}
		);

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			WEBBA_DEPLOY_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( WEBBA_DEPLOY_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
