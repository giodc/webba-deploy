<?php
/**
 * Admin menu registration.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

use WebbaDeploy\Security\Capability;

/**
 * Registers top-level admin menu and subpages.
 */
class Menu {

	public const SLUG_DASHBOARD        = 'webba-deploy';
	public const SLUG_ADD_PLUGIN       = 'webba-deploy-add-plugin';
	public const SLUG_ADD_THEME        = 'webba-deploy-add-theme';
	public const SLUG_GITHUB           = 'webba-deploy-github';
	public const SLUG_SETTINGS         = 'webba-deploy-settings';
	public const SLUG_SETTINGS_GENERAL = 'webba-deploy-general';

	/**
	 * GitHub settings admin screen URL.
	 *
	 * @param array<string, string> $query_args Optional query arguments.
	 */
	public static function github_settings_url( array $query_args = array() ): string {
		$url = add_query_arg( 'page', self::SLUG_GITHUB, admin_url( 'admin.php' ) );

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		return $url;
	}

	/**
	 * Register admin menu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_post_webba_deploy_action', array( $this, 'handle_actions' ) );
	}

	/**
	 * Admin menu icon: the Webba Deploy mark as a base64-encoded SVG.
	 */
	private function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad">'
			. '<defs><mask id="cut"><rect width="20" height="20" fill="#fff"/><g fill="#000">'
			. '<circle cx="2.9" cy="8.5" r="0.72"/><circle cx="2.9" cy="11.5" r="0.72"/><circle cx="5.1" cy="9.1" r="0.72"/>'
			. '<path d="M2.9 8.5v3M5.1 9.1L2.9 11.5" stroke="#000" stroke-width="1.05" stroke-linecap="round"/>'
			. '<circle cx="14.5" cy="7.35" r="0.3"/><circle cx="16.4" cy="7.35" r="0.3"/><circle cx="18.3" cy="7.35" r="0.3"/>'
			. '<path d="M13.3 8.3h6.2" stroke="#000" stroke-width="0.65"/>'
			. '<path d="M16.4 12.4v-2M15.6 11.3l0.8-1 0.8 1" stroke="#000" stroke-width="1.05" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</g></mask></defs>'
			. '<g mask="url(#cut)">'
			. '<rect x="1.2" y="7.2" width="5.6" height="5.6" rx="1.4" transform="rotate(45 4 10)"/>'
			. '<path d="M6.8 13.2c1.9 0 3.5-.9 4.5-2.7" fill="none" stroke="#a7aaad" stroke-width="1.6" stroke-linecap="round"/>'
			. '<path d="M10.7 8.8L13.1 10l-2.1 1.7z"/>'
			. '<rect x="13.2" y="6.4" width="6.4" height="7.2" rx="1.3"/>'
			. '</g></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Add menu pages.
	 */
	public function add_menus(): void {
		$dashboard = new DashboardPage();

		// add_menu_page() already registers a submenu with the same slug and callback.
		// Do not call add_submenu_page() again for the dashboard — that registers a second hook and renders twice.
		add_menu_page(
			__( 'Dashboard', 'webba-deploy' ),
			__( 'Webba Deploy', 'webba-deploy' ),
			'manage_options',
			self::SLUG_DASHBOARD,
			array( $dashboard, 'render' ),
			$this->menu_icon(),
			80
		);

		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Add Plugin', 'webba-deploy' ),
			__( 'Add Plugin', 'webba-deploy' ),
			'manage_options',
			self::SLUG_ADD_PLUGIN,
			static function (): void {
				$page = new AddPackagePage( 'plugin' );
				$page->render();
			}
		);

		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Add Theme', 'webba-deploy' ),
			__( 'Add Theme', 'webba-deploy' ),
			'manage_options',
			self::SLUG_ADD_THEME,
			static function (): void {
				$page = new AddPackagePage( 'theme' );
				$page->render();
			}
		);

		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Push-to-deploy', 'webba-deploy' ),
			__( 'Push-to-deploy', 'webba-deploy' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( new SettingsPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'GitHub', 'webba-deploy' ),
			__( 'GitHub', 'webba-deploy' ),
			'manage_options',
			self::SLUG_GITHUB,
			array( new GitHubSettingsPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Settings', 'webba-deploy' ),
			__( 'Settings', 'webba-deploy' ),
			'manage_options',
			self::SLUG_SETTINGS_GENERAL,
			array( new GeneralSettingsPage(), 'render' )
		);

		global $submenu;

		if ( isset( $submenu[ self::SLUG_DASHBOARD ][0][0] ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Renaming the auto-generated submenu label.
			$submenu[ self::SLUG_DASHBOARD ][0][0] = __( 'Dashboard', 'webba-deploy' );
		}
	}

	/**
	 * Handle POST actions (deploy, delete).
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['webba_deploy_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['webba_deploy_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'deploy':
				$this->handle_deploy();
				break;
			case 'delete':
				$this->handle_delete();
				break;
			case 'add_package':
				$this->handle_add_package();
				break;
			case 'rotate_secret':
				$this->handle_rotate_secret();
				break;
			case 'github_disconnect':
				$this->handle_github_disconnect();
				break;
			case 'github_reset_app':
				$this->handle_github_reset_app();
				break;
			case 'check_updates':
				$this->handle_check_updates();
				break;
			case 'save_settings':
				$this->handle_save_settings();
				break;
		}
	}

	/**
	 * Save general plugin settings.
	 */
	private function handle_save_settings(): void {
		check_admin_referer( 'webba_deploy_save_settings' );
		Capability::require_manage_settings();

		update_option(
			'webba_deploy_settings',
			array(
				'logging'     => ! empty( $_POST['logging'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'flush_cache' => ! empty( $_POST['flush_cache'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			),
			false
		);

		update_option(
			'webba_deploy_delete_on_uninstall',
			! empty( $_POST['delete_on_uninstall'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			false
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG_SETTINGS_GENERAL,
					'notice' => 'settings_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Refresh GitHub commit comparison and WordPress update transients.
	 */
	private function handle_check_updates(): void {
		check_admin_referer( 'webba_deploy_check_updates' );
		Capability::require_manage_settings();

		( new \WebbaDeploy\Core\UpdateRefreshService() )->refresh_all();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG_DASHBOARD,
					'notice' => 'updates_checked',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Remove manifest-stored App credentials (wp-config overrides unchanged).
	 */
	private function handle_github_reset_app(): void {
		check_admin_referer( 'webba_deploy_github_reset_app' );
		Capability::require_manage_settings();

		$config = new \WebbaDeploy\GitHub\AppConfig();

		if ( $config->uses_wp_config() ) {
			wp_safe_redirect(
				self::github_settings_url(
					array(
						'notice'  => 'error',
						'message' => __( 'App credentials are defined in wp-config.php and cannot be removed here.', 'webba-deploy' ),
					)
				)
			);
			exit;
		}

		$github = new \WebbaDeploy\GitHub\ConnectController();
		$github->disconnect();
		$github->reset_app_credentials();

		wp_safe_redirect(
			self::github_settings_url(
				array(
					'notice' => 'reset',
				)
			)
		);
		exit;
	}

	/**
	 * Disconnect GitHub App installation.
	 */
	private function handle_github_disconnect(): void {
		check_admin_referer( 'webba_deploy_github_disconnect' );
		Capability::require_manage_settings();

		( new \WebbaDeploy\GitHub\ConnectController() )->disconnect();

		wp_safe_redirect(
			self::github_settings_url(
				array(
					'notice'  => 'disconnected',
					'message' => __( 'GitHub App disconnected.', 'webba-deploy' ),
				)
			)
		);
		exit;
	}

	/**
	 * Rotate push-to-deploy site secret.
	 */
	private function handle_rotate_secret(): void {
		check_admin_referer( 'webba_deploy_rotate_secret' );
		Capability::require_manage_settings();

		( new \WebbaDeploy\Security\SiteSecret() )->rotate();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG_SETTINGS,
					'notice'       => 'rotated',
					'show_secret'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Install or update action from the dashboard.
	 */
	private function handle_deploy(): void {
		check_admin_referer( 'webba_deploy_deploy' );

		$type = isset( $_POST['package_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['package_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = isset( $_POST['package_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['package_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $type || '' === $id ) {
			wp_safe_redirect( $this->redirect_url( 'error', __( 'Invalid package.', 'webba-deploy' ) ) );
			exit;
		}

		$repo    = new \WebbaDeploy\Core\PackageRepository();
		$package = $repo->find( $type, $id );
		$notice  = 'installed';

		if ( null !== $package && \WebbaDeploy\Core\PackageRepository::is_installed_on_disk( $type, (string) ( $package['slug'] ?? '' ) ) ) {
			$notice = 'updated';
		}

		$service = new \WebbaDeploy\Core\DeployService();
		$result  = $service->deploy( $type, $id );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( $this->redirect_url( 'error', $result->get_error_message() ) );
			exit;
		}

		wp_safe_redirect( $this->redirect_url( $notice, '' ) );
		exit;
	}

	/**
	 * Delete package action.
	 */
	private function handle_delete(): void {
		check_admin_referer( 'webba_deploy_delete' );

		$type = isset( $_POST['package_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['package_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = isset( $_POST['package_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['package_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		Capability::require_manage_settings();

		$repo = new \WebbaDeploy\Core\PackageRepository();

		if ( $repo->delete( $type, $id ) ) {
			wp_safe_redirect( $this->redirect_url( 'deleted', __( 'Package removed.', 'webba-deploy' ) ) );
			exit;
		}

		wp_safe_redirect( $this->redirect_url( 'error', __( 'Could not remove package.', 'webba-deploy' ) ) );
		exit;
	}

	/**
	 * Add package from wizard.
	 */
	private function handle_add_package(): void {
		$type         = isset( $_POST['package_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['package_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce_action = 'webba_deploy_add_' . $type;

		check_admin_referer( $nonce_action );

		Capability::require_deploy( $type );

		$mode = isset( $_POST['visibility'] ) ? sanitize_key( wp_unslash( (string) $_POST['visibility'] ) ) : 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $mode, array( 'public', 'private' ), true ) ) {
			$mode = 'public';
		}

		if ( 'private' === $mode ) {
			$connection = new \WebbaDeploy\GitHub\ConnectionStore();

			if ( ! $connection->is_connected() ) {
				wp_safe_redirect( $this->add_redirect_url( $type, 'error', __( 'Connect the GitHub App before adding a private package.', 'webba-deploy' ) ) );
				exit;
			}

			$repo_full = isset( $_POST['repo_full'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['repo_full'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$parts     = explode( '/', $repo_full, 2 );

			if ( count( $parts ) < 2 || '' === $parts[0] || '' === $parts[1] ) {
				wp_safe_redirect( $this->add_redirect_url( $type, 'error', __( 'Select a repository from the list.', 'webba-deploy' ) ) );
				exit;
			}

			$parsed = array(
				'owner' => $parts[0],
				'repo'  => $parts[1],
			);
		} else {
			$repo_url = isset( $_POST['repo_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['repo_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$parsed   = \WebbaDeploy\Core\PackageRepository::parse_github_url( $repo_url );

			if ( null === $parsed ) {
				wp_safe_redirect( $this->add_redirect_url( $type, 'error', __( 'Invalid GitHub repository URL.', 'webba-deploy' ) ) );
				exit;
			}
		}

		// Trust GitHub's actual visibility, not the submitted radio value.
		$visibility = ( new \WebbaDeploy\GitHub\GitHubAppAuth() )->resolve_visibility(
			$parsed['owner'],
			$parsed['repo'],
			'private' === $mode ? 'private' : 'public'
		);

		if ( is_wp_error( $visibility ) ) {
			wp_safe_redirect( $this->add_redirect_url( $type, 'error', $visibility->get_error_message() ) );
			exit;
		}

		if ( 'private' === $visibility ) {
			$config     = new \WebbaDeploy\GitHub\AppConfig();
			$connection = new \WebbaDeploy\GitHub\ConnectionStore();

			if ( ! $config->is_configured() || ! $connection->is_connected() ) {
				wp_safe_redirect( $this->add_redirect_url( $type, 'error', __( 'This repository is private. Connect the GitHub App (Webba Deploy → GitHub) and grant it access to add it.', 'webba-deploy' ) ) );
				exit;
			}
		}

		$slug_raw    = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$slug        = '' !== $slug_raw ? $slug_raw : sanitize_title( $parsed['repo'] );
		$branch      = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['branch'] ) ) : 'main'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$install_now = ! empty( $_POST['install_now'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'private' === $visibility && '' === trim( $branch ) ) {
			$branch = ( new \WebbaDeploy\GitHub\RepositoryLister() )->get_default_branch( $parsed['owner'], $parsed['repo'] );
		}

		$repo = new \WebbaDeploy\Core\PackageRepository();

		if ( $repo->find_by_slug( $type, $slug ) ) {
			wp_safe_redirect( $this->add_redirect_url( $type, 'error', __( 'A package with this slug already exists.', 'webba-deploy' ) ) );
			exit;
		}

		$package = $repo->create(
			$type,
			array(
				'owner'      => $parsed['owner'],
				'repo'       => $parsed['repo'],
				'slug'       => $slug,
				'branch'     => $branch,
				'visibility' => $visibility,
			)
		);

		if ( null === $package ) {
			wp_safe_redirect( $this->add_redirect_url( $type, 'error', __( 'Could not save package.', 'webba-deploy' ) ) );
			exit;
		}

		if ( $install_now ) {
			$service = new \WebbaDeploy\Core\DeployService();
			$result  = $service->deploy( $type, $package['id'] );

			if ( is_wp_error( $result ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'    => self::SLUG_DASHBOARD,
							'notice'  => 'error',
							'message' => rawurlencode( $result->get_error_message() ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => self::SLUG_DASHBOARD,
						'notice' => 'installed',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG_DASHBOARD,
					'notice' => 'created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Dashboard redirect with notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $message Message.
	 */
	private function redirect_url( string $notice, string $message ): string {
		return add_query_arg(
			array(
				'page'    => self::SLUG_DASHBOARD,
				'notice'  => $notice,
				'message' => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add package page redirect.
	 *
	 * @param string $type Package type.
	 * @param string $notice Notice key.
	 * @param string $message Message.
	 */
	private function add_redirect_url( string $type, string $notice, string $message ): string {
		$page = \WebbaDeploy\Core\PackageRepository::TYPE_THEME === $type ? self::SLUG_ADD_THEME : self::SLUG_ADD_PLUGIN;

		return add_query_arg(
			array(
				'page'    => $page,
				'notice'  => $notice,
				'message' => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);
	}
}
