<?php
/**
 * Encrypt sensitive option values at rest.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Security;

/**
 * AES-256-CBC encryption using WordPress auth salt.
 */
class Crypto {

	private const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt plaintext for storage.
	 *
	 * @param string $plain Plaintext.
	 * @return string Base64-encoded payload or empty string on failure.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$key = self::derive_key();
		$iv  = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );

		$ciphertext = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return '';
		}

		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt stored payload.
	 *
	 * @param string $encoded Base64-encoded payload.
	 * @return string Plaintext or empty string on failure.
	 */
	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}

		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = substr( $raw, 0, $iv_length );
		$payload   = substr( $raw, $iv_length );

		if ( strlen( $iv ) !== $iv_length ) {
			return '';
		}

		$plain = openssl_decrypt( $payload, self::CIPHER, self::derive_key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Derive a 32-byte key from WordPress salts.
	 */
	private static function derive_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
