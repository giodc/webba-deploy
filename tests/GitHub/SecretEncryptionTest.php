<?php
/**
 * Tests for encrypted credential storage.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\GitHub\SecretEncryption;

final class SecretEncryptionTest extends TestCase {

	private const SALT = 'test-auth-salt';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( self::SALT );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_round_trip_returns_original_value(): void {
		$encrypted = SecretEncryption::encrypt( 'a-private-pem-value' );

		$this->assertNotSame( '', $encrypted );
		$this->assertStringStartsWith( 'v2:', $encrypted );
		$this->assertSame( 'a-private-pem-value', SecretEncryption::decrypt( $encrypted ) );
	}

	public function test_encryption_is_not_deterministic(): void {
		$this->assertNotSame(
			SecretEncryption::encrypt( 'same-value' ),
			SecretEncryption::encrypt( 'same-value' )
		);
	}

	public function test_tampered_ciphertext_is_rejected(): void {
		$encrypted = SecretEncryption::encrypt( 'secret-value' );
		$raw       = base64_decode( substr( $encrypted, 3 ), true );

		$this->assertIsString( $raw );

		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF );

		$tampered = 'v2:' . base64_encode( $raw );

		$this->assertSame( '', SecretEncryption::decrypt( $tampered ) );
	}

	public function test_tampered_mac_is_rejected(): void {
		$encrypted = SecretEncryption::encrypt( 'secret-value' );
		$raw       = base64_decode( substr( $encrypted, 3 ), true );

		$this->assertIsString( $raw );

		$raw[20] = chr( ord( $raw[20] ) ^ 0xFF );

		$this->assertSame( '', SecretEncryption::decrypt( 'v2:' . base64_encode( $raw ) ) );
	}

	public function test_legacy_payload_still_decrypts(): void {
		$key = hash( 'sha256', self::SALT, true );
		$iv  = random_bytes( 16 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_openssl_encrypt
		$ciphertext = openssl_encrypt( 'legacy-secret', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		$legacy     = base64_encode( $iv . $ciphertext );

		$this->assertSame( 'legacy-secret', SecretEncryption::decrypt( $legacy ) );
	}

	public function test_empty_and_invalid_payloads_return_empty_string(): void {
		$this->assertSame( '', SecretEncryption::encrypt( '' ) );
		$this->assertSame( '', SecretEncryption::decrypt( '' ) );
		$this->assertSame( '', SecretEncryption::decrypt( 'v2:not-base64!' ) );
		$this->assertSame( '', SecretEncryption::decrypt( 'v2:'.base64_encode( 'short' ) ) );
	}
}
