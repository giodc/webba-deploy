<?php
/**
 * Tests for the push-to-deploy rate limiter.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\Security\RateLimiter;

final class RateLimiterTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->store = array();

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->store[ $key ] ?? false;
			}
		);

		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->store[ $key ] = $value;

				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allows_requests_up_to_the_limit_then_blocks(): void {
		$limiter = new RateLimiter( 3, 60 );

		$this->assertTrue( $limiter->is_allowed( 'client' ) );
		$this->assertTrue( $limiter->is_allowed( 'client' ) );
		$this->assertTrue( $limiter->is_allowed( 'client' ) );
		$this->assertFalse( $limiter->is_allowed( 'client' ) );
	}

	public function test_separate_clients_have_separate_budgets(): void {
		$limiter = new RateLimiter( 1, 60 );

		$this->assertTrue( $limiter->is_allowed( 'alpha' ) );
		$this->assertFalse( $limiter->is_allowed( 'alpha' ) );
		$this->assertTrue( $limiter->is_allowed( 'beta' ) );
	}
}
