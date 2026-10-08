<?php
/**
 * Tests for GitHub App JWT generation.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\GitHub\JwtGenerator;

final class JwtGeneratorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_create_returns_a_verifiable_rs256_token(): void {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		$this->assertNotFalse( $key, 'OpenSSL key generation failed.' );

		openssl_pkey_export( $key, $pem );
		$details = openssl_pkey_get_details( $key );

		$jwt = ( new JwtGenerator() )->create( 12345, (string) $pem );

		$this->assertIsString( $jwt );

		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );

		$header = json_decode( $this->base64url_decode( $parts[0] ), true );
		$this->assertSame( 'RS256', $header['alg'] );

		$payload = json_decode( $this->base64url_decode( $parts[1] ), true );
		$this->assertSame( '12345', $payload['iss'] );

		$signature = $this->base64url_decode( $parts[2] );
		$verified  = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $details['key'], OPENSSL_ALGO_SHA256 );

		$this->assertSame( 1, $verified );
	}

	public function test_create_rejects_an_invalid_private_key(): void {
		$result = ( new JwtGenerator() )->create( 1, 'not-a-real-key' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	private function base64url_decode( string $value ): string {
		$value   = strtr( $value, '-_', '+/' );
		$padding = strlen( $value ) % 4;

		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		return (string) base64_decode( $value, true );
	}
}
