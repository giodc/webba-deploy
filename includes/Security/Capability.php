<?php
/**
 * Capability checks for admin and deploy actions.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Security;

/**
 * Centralized capability enforcement.
 */
class Capability {

	/**
	 * Whether the current user can manage plugin settings.
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user can install plugins.
	 */
	public static function can_install_plugins(): bool {
		return current_user_can( 'install_plugins' );
	}

	/**
	 * Whether the current user can install themes.
	 */
	public static function can_install_themes(): bool {
		return current_user_can( 'install_themes' );
	}

	/**
	 * Whether the current user can deploy a package type.
	 *
	 * @param string $type Package type: plugin|theme.
	 */
	public static function can_deploy( string $type ): bool {
		if ( ! self::can_manage_settings() ) {
			return false;
		}

		if ( 'plugin' === $type ) {
			return self::can_install_plugins();
		}

		if ( 'theme' === $type ) {
			return self::can_install_themes();
		}

		return false;
	}

	/**
	 * Die with 403 if user cannot manage settings.
	 */
	public static function require_manage_settings(): void {
		if ( ! self::can_manage_settings() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'webba-deploy' ), 403 );
		}
	}

	/**
	 * Die with 403 if user cannot deploy the given type.
	 *
	 * @param string $type Package type.
	 */
	public static function require_deploy( string $type ): void {
		if ( ! self::can_deploy( $type ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this deploy.', 'webba-deploy' ), 403 );
		}
	}
}
