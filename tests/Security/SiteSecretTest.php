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
		$this->assertTrue( ( new SiteSecret() )->validate( 'stored-secret-value' ) );
	}

	public function test_validate_rejects_wrong_or_empty_secrets(): void {
		$secret = new SiteSecret();

		$this->assertFalse( $secret->validate( 'wrong' ) );
		$this->assertFalse( $secret->validate( '' ) );
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
