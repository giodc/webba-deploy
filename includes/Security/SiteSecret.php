<?php
/**
 * Site-wide push-to-deploy secret.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Security;

/**
 * Generates, stores, and validates the deploy URL secret.
 */
class SiteSecret {

	private const OPTION_KEY = 'webba_deploy_site_secret';

	/**
	 * Get the current secret, creating one if missing.
	 */
	public function get(): string {
		$secret = get_option( self::OPTION_KEY, '' );

		if ( is_string( $secret ) && '' !== $secret ) {
			return $secret;
		}

		return $this->rotate();
	}

	/**
	 * Generate and persist a new secret.
	 */
	public function rotate(): string {
		$secret = wp_generate_password( 64, true, true );
		update_option( self::OPTION_KEY, $secret, false );

		return $secret;
	}

	/**
	 * Timing-safe secret comparison.
	 *
	 * @param string $provided Secret from the request.
	 */
	public function validate( string $provided ): bool {
		if ( '' === $provided ) {
			return false;
		}

		$stored = get_option( self::OPTION_KEY, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $provided );
	}

	/**
	 * Mask secret for display (first 4 + ellipsis + last 4).
	 *
	 * @param string|null $secret Secret to mask; uses stored secret when null.
	 */
	public function mask( ?string $secret = null ): string {
		$secret = null !== $secret ? $secret : $this->get();
		$length = strlen( $secret );

		if ( $length <= 12 ) {
			return str_repeat( '•', $length );
		}

		return substr( $secret, 0, 4 ) . str_repeat( '•', min( 24, $length - 8 ) ) . substr( $secret, -4 );
	}
}
