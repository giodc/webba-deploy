<?php
/**
 * Per-type push-to-deploy secrets.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Security;

/**
 * Generates, stores, and validates plugin/theme deploy URL secrets.
 */
class SiteSecret {

	/**
	 * Option prefix for per-type secrets.
	 */
	private const OPTION_PREFIX = 'webba_deploy_site_secret_';

	/**
	 * Legacy single-site secret (migrated into per-type secrets on read).
	 */
	private const LEGACY_OPTION = 'webba_deploy_site_secret';

	/**
	 * Get the current secret for a package type, creating one if missing.
	 *
	 * @param string $type plugin|theme.
	 */
	public function get( string $type = 'plugin' ): string {
		$type   = $this->normalize_type( $type );
		$secret = get_option( self::OPTION_PREFIX . $type, '' );

		if ( is_string( $secret ) && '' !== $secret ) {
			return $secret;
		}

		$legacy = get_option( self::LEGACY_OPTION, '' );

		if ( is_string( $legacy ) && '' !== $legacy ) {
			update_option( self::OPTION_PREFIX . $type, $legacy, false );

			return $legacy;
		}

		return $this->rotate( $type );
	}

	/**
	 * Generate and persist a new secret for a package type.
	 *
	 * @param string $type plugin|theme.
	 */
	public function rotate( string $type = 'plugin' ): string {
		$type   = $this->normalize_type( $type );
		$secret = wp_generate_password( 64, false, false );

		update_option( self::OPTION_PREFIX . $type, $secret, false );

		return $secret;
	}

	/**
	 * Timing-safe secret comparison for a package type.
	 *
	 * @param string $type     plugin|theme.
	 * @param string $provided Secret from the request.
	 */
	public function validate( string $type, string $provided ): bool {
		if ( '' === $provided ) {
			return false;
		}

		$type   = $this->normalize_type( $type );
		$stored = get_option( self::OPTION_PREFIX . $type, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			$stored = get_option( self::LEGACY_OPTION, '' );
		}

		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $provided );
	}

	/**
	 * Mask secret for display (first 4 + ellipsis + last 4).
	 *
	 * @param string|null $secret Secret to mask; uses plugin secret when null.
	 */
	public function mask( ?string $secret = null ): string {
		$secret = null !== $secret ? $secret : $this->get( 'plugin' );
		$length = strlen( $secret );

		if ( $length <= 12 ) {
			return str_repeat( '•', $length );
		}

		return substr( $secret, 0, 4 ) . str_repeat( '•', min( 24, $length - 8 ) ) . substr( $secret, -4 );
	}

	/**
	 * Normalize a package type to plugin|theme.
	 *
	 * @param string $type Raw type.
	 */
	private function normalize_type( string $type ): string {
		return 'theme' === $type ? 'theme' : 'plugin';
	}
}
