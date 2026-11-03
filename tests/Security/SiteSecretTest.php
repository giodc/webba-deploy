<?php
/**
 * Tests for the site-wide deploy secret.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\Security\SiteSecret;

final class SiteSecretTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( 'stored-secret-value' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_validate_accepts_the_stored_secret(): void {
		$this->assertTrue( ( new SiteSecret() )->validate( 'plugin', 'stored-secret-value' ) );
	}

	public function test_validate_rejects_wrong_or_empty_secrets(): void {
		$secret = new SiteSecret();

		$this->assertFalse( $secret->validate( 'plugin', 'wrong' ) );
		$this->assertFalse( $secret->validate( 'plugin', '' ) );
	}

	public function test_type_secrets_are_isolated(): void {
		$store = array(
			'webba_deploy_site_secret_plugin' => 'plugin-secret',
			'webba_deploy_site_secret_theme'  => 'theme-secret',
		);

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = '' ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);

		$secret = new SiteSecret();

		$this->assertTrue( $secret->validate( 'plugin', 'plugin-secret' ) );
		$this->assertFalse( $secret->validate( 'theme', 'plugin-secret' ) );
		$this->assertTrue( $secret->validate( 'theme', 'theme-secret' ) );
	}

	public function test_get_migrates_legacy_secret_into_type_option(): void {
		$store = array( 'webba_deploy_site_secret' => 'legacy-secret' );

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = '' ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;

				return true;
			}
		);

		$secret = new SiteSecret();

		$this->assertSame( 'legacy-secret', $secret->get( 'theme' ) );
		$this->assertSame( 'legacy-secret', $store['webba_deploy_site_secret_theme'] );
	}

	public function test_mask_hides_middle_of_long_secret(): void {
		$masked = ( new SiteSecret() )->mask( str_repeat( 'a', 64 ) );

		$this->assertStringStartsWith( 'aaaa', $masked );
		$this->assertStringEndsWith( 'aaaa', $masked );
		$this->assertStringContainsString( '•', $masked );
	}

	public function test_mask_fully_hides_short_secret(): void {
		$this->assertSame( '••••', ( new SiteSecret() )->mask( 'abcd' ) );
	}
}
