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
use WebbaDeploy\WordPress\SelfUpdate;

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

		$this->register_plugin_check_excludes();

		$github = new ConnectController();
		$github->register();

		$manifest = new ManifestRegistrar();
		$manifest->register();

		( new NativeUpdateIntegration() )->register();
		( new SelfUpdate() )->register();

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
	}

	/**
	 * Exclude local-only paths when Plugin Check scans a git working tree.
	 *
	 * Release zips never include these (see .distignore). Filters are a no-op
	 * once the plugin is installed from wordpress.org (no .git directory).
	 */
	private function register_plugin_check_excludes(): void {
		if ( ! is_dir( WEBBA_DEPLOY_PLUGIN_DIR . '.git' ) ) {
			return;
		}

		add_filter(
			'wp_plugin_check_ignore_directories',
			static function ( array $directories ): array {
				return array_values(
					array_unique(
						array_merge(
							$directories,
							array(
								'build',
								'tests',
								'scripts',
								'.ai',
								'.commandcode',
								'.github',
								'.idea',
								'.vscode',
								'assets/wordpress-org',
							)
						)
					)
				);
			}
		);

		add_filter(
			'wp_plugin_check_ignore_files',
			static function ( array $files ): array {
				return array_values(
					array_unique(
						array_merge(
							$files,
							array(
								'.distignore',
								'.gitignore',
								'.gitattributes',
								'build-release.sh',
								'composer.json',
								'composer.lock',
								'phpcs.xml.dist',
								'phpunit.xml.dist',
								'PLAN.md',
							)
						)
					)
				);
			}
		);
	}
}
