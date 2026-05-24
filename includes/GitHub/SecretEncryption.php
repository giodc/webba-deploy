<?php
/**
 * Encrypt sensitive values before storing in wp_options.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * AES-256-CBC encryption keyed with wp_salt( 'auth' ).
 */
class SecretEncryption {

	/**
	 * Encrypt a string for database storage.
	 *
	 * @param string $plaintext Secret value.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_openssl_encrypt
		$ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return '';
		}

		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value from the database.
	 *
	 * @param string $encoded Base64 payload from encrypt().
	 */
	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}

		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$iv         = substr( $raw, 0, 16 );
		$ciphertext = substr( $raw, 16 );
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_openssl_decrypt
		$plaintext = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return is_string( $plaintext ) ? $plaintext : '';
	}
}
