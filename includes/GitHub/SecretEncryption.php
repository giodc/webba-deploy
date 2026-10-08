<?php
/**
 * Encrypt sensitive values before storing in wp_options.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * AES-256-CBC with encrypt-then-MAC, keyed with wp_salt( 'auth' ).
 */
class SecretEncryption {

	private const CIPHER = 'AES-256-CBC';
	private const PREFIX = 'v2:';
	private const IV_LENGTH = 16;
	private const MAC_LENGTH = 32;

	/**
	 * Encrypt a string for database storage.
	 *
	 * Uses encrypt-then-MAC so tampered payloads are rejected on decrypt.
	 *
	 * @param string $plaintext Secret value.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		try {
			$iv = random_bytes( self::IV_LENGTH );
		} catch ( \Throwable $e ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_openssl_encrypt
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return '';
		}

		$mac = hash_hmac( 'sha256', $iv . $ciphertext, self::mac_key(), true );

		return self::PREFIX . base64_encode( $iv . $mac . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value from the database.
	 *
	 * Supports authenticated (v2) and legacy (unauthenticated) payloads.
	 *
	 * @param string $encoded Payload from encrypt().
	 */
	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}

		if ( 0 === strpos( $encoded, self::PREFIX ) ) {
			return self::decrypt_authenticated( substr( $encoded, strlen( self::PREFIX ) ) );
		}

		return self::decrypt_legacy( $encoded );
	}

	/**
	 * Verify the MAC and decrypt an authenticated payload.
	 *
	 * @param string $encoded Base64 body without the version prefix.
	 */
	private static function decrypt_authenticated( string $encoded ): string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) < ( self::IV_LENGTH + self::MAC_LENGTH ) ) {
			return '';
		}

		$iv         = substr( $raw, 0, self::IV_LENGTH );
		$mac        = substr( $raw, self::IV_LENGTH, self::MAC_LENGTH );
		$ciphertext = substr( $raw, self::IV_LENGTH + self::MAC_LENGTH );
		$expected   = hash_hmac( 'sha256', $iv . $ciphertext, self::mac_key(), true );

		if ( ! hash_equals( $expected, $mac ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_openssl_decrypt
		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Decrypt a legacy payload (base64 of IV + ciphertext, no MAC).
	 *
	 * @param string $encoded Legacy payload.
	 */
	private static function decrypt_legacy( string $encoded ): string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) < ( self::IV_LENGTH + 1 ) ) {
			return '';
		}

		$iv         = substr( $raw, 0, self::IV_LENGTH );
		$ciphertext = substr( $raw, self::IV_LENGTH );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_openssl_decrypt
		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Encryption key derived from the site auth salt.
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * Separate key for the authentication tag.
	 */
	private static function mac_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|mac', true );
	}
}
