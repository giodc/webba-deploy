<?php
/**
 * Tests for the GitHub branch lister.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\GitHub\ApiClient;
use WebbaDeploy\GitHub\BranchLister;
use WebbaDeploy\GitHub\GitHubAppAuth;

final class BranchListerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_lists_sorted_and_valid_branch_names(): void {
		$lister = $this->make_lister(
			array(
				array( 'name' => 'main' ),
				array( 'name' => 'feature/b' ),
				array( 'name' => 'feature/a' ),
				array( 'name' => 'bad name' ),
			)
		);

		$this->assertSame(
			array( 'feature/a', 'feature/b', 'main' ),
			$lister->list_branches( $this->package() )
		);
	}

	public function test_uses_cached_branches_without_calling_the_api(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'main', 'develop' ) );

		$api = new class() extends ApiClient {
			public int $calls = 0;

			public function get( string $path, ?string $token = null ) {
				++$this->calls;

				return array();
			}
		};

		$lister = new BranchLister( $api, $this->make_auth() );
		$result = $lister->list_branches( $this->package() );

		$this->assertSame( array( 'main', 'develop' ), $result );
		$this->assertSame( 0, $api->calls );
	}

	public function test_propagates_api_errors(): void {
		$lister = $this->make_lister( new WP_Error( 'boom', 'GitHub is down.' ) );

		$result = $lister->list_branches( $this->package() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'boom', $result->get_error_code() );
	}

	/**
	 * Package record for a public repository.
	 *
	 * @return array<string, string>
	 */
	private function package(): array {
		return array(
			'owner'      => 'acme',
			'repo'       => 'plugin',
			'visibility' => 'public',
		);
	}

	/**
	 * Build a lister returning a fixed API response.
	 *
	 * @param mixed $response API response or error.
	 */
	private function make_lister( $response ): BranchLister {
		$api = new class( $response ) extends ApiClient {
			public function __construct( private $response ) {
			}

			public function get( string $path, ?string $token = null ) {
				return $this->response;
			}
		};

		return new BranchLister( $api, $this->make_auth() );
	}

	/**
	 * Public-only auth resolver.
	 */
	private function make_auth(): GitHubAppAuth {
		return new class() extends GitHubAppAuth {
			public function __construct() {
			}

			public function token_for_package( array $package ) {
				return null;
			}
		};
	}
}
