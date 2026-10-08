<?php
/**
 * Tests for the unified private-repository auth resolver.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\GitHub\ApiClient;
use WebbaDeploy\GitHub\AppConfig;
use WebbaDeploy\GitHub\ConnectionStore;
use WebbaDeploy\GitHub\GitHubAppAuth;
use WebbaDeploy\GitHub\InstallationTokenService;
use WebbaDeploy\GitHub\RepositoryLister;

final class GitHubAppAuthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_public_package_requires_no_token(): void {
		$auth = $this->make_auth();

		$this->assertFalse( $auth->requires_auth( array( 'visibility' => 'public' ) ) );
		$this->assertNull( $auth->token_for_package( array( 'visibility' => 'public' ) ) );
	}

	public function test_private_package_returns_installation_token_when_granted(): void {
		$auth = $this->make_auth(
			true,
			true,
			42,
			true,
			'installation-token'
		);

		$this->assertSame(
			'installation-token',
			$auth->token_for_package(
				array(
					'visibility' => 'private',
					'owner'      => 'acme',
					'repo'       => 'secret-plugin',
				)
			)
		);
	}

	public function test_private_package_without_app_setup_errors(): void {
		$result = $this->make_auth( false )->token_for_package(
			array(
				'visibility' => 'private',
				'owner'      => 'acme',
				'repo'       => 'secret-plugin',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'webba_deploy_private_requires_app', $result->get_error_code() );
	}

	public function test_private_package_without_connection_errors(): void {
		$result = $this->make_auth( true, false )->token_for_package(
			array(
				'visibility' => 'private',
				'owner'      => 'acme',
				'repo'       => 'secret-plugin',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'webba_deploy_private_requires_app', $result->get_error_code() );
	}

	public function test_repository_not_granted_errors(): void {
		$result = $this->make_auth( true, true, 42, false )->token_for_package(
			array(
				'visibility' => 'private',
				'owner'      => 'acme',
				'repo'       => 'secret-plugin',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'webba_deploy_repo_not_granted', $result->get_error_code() );
	}

	public function test_access_check_failure_is_reported_as_indeterminate(): void {
		$result = $this->make_auth(
			true,
			true,
			42,
			new \WP_Error( 'network', 'timeout' )
		)->token_for_package(
			array(
				'visibility' => 'private',
				'owner'      => 'acme',
				'repo'       => 'secret-plugin',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'webba_deploy_repo_access_check_failed', $result->get_error_code() );
	}

	public function test_resolve_visibility_reads_private_flag(): void {
		$auth = $this->make_auth( true, true, 42, true, 'token', array( 'private' => true ) );

		$this->assertSame( 'private', $auth->resolve_visibility( 'acme', 'secret-plugin' ) );
	}

	public function test_resolve_visibility_reads_public_flag(): void {
		$auth = $this->make_auth( true, true, 42, true, 'token', array( 'private' => false ) );

		$this->assertSame( 'public', $auth->resolve_visibility( 'acme', 'public-plugin' ) );
	}

	public function test_resolve_visibility_errors_on_missing_repository(): void {
		$auth = $this->make_auth(
			true,
			true,
			42,
			true,
			'token',
			new \WP_Error( 'not_found', 'Not Found', array( 'status' => 404 ) )
		);

		$result = $auth->resolve_visibility( 'acme', 'missing' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'webba_deploy_repo_not_found', $result->get_error_code() );
	}

	public function test_resolve_visibility_falls_back_on_indeterminate_error(): void {
		$auth = $this->make_auth(
			true,
			true,
			42,
			true,
			'token',
			new \WP_Error( 'server', 'oops', array( 'status' => 500 ) )
		);

		$this->assertSame( 'public', $auth->resolve_visibility( 'acme', 'public-plugin', 'public' ) );
	}

	public function test_resolve_visibility_uses_anonymous_lookup_without_app(): void {
		$auth = $this->make_auth( false, false, null, true, 'token', array( 'private' => false ) );

		$this->assertSame( 'public', $auth->resolve_visibility( 'acme', 'public-plugin' ) );
	}

	/**
	 * Build a resolver with fake collaborators.
	 *
	 * @param bool                 $configured  App credentials present.
	 * @param bool                 $connected   Installation stored.
	 * @param int|null             $installation Installation ID.
	 * @param bool|\WP_Error|mixed $access      Repository access result.
	 * @param string               $token       Installation token.
	 * @param mixed                $lookup      API lookup response.
	 */
	private function make_auth(
		bool $configured = true,
		bool $connected = true,
		?int $installation = 42,
		$access = true,
		string $token = 'token',
		$lookup = array( 'private' => true )
	): GitHubAppAuth {
		$config = new class( $configured ) extends AppConfig {
			public function __construct( private bool $configured ) {
			}

			public function is_configured(): bool {
				return $this->configured;
			}
		};

		$connection = new class( $connected, $installation ) extends ConnectionStore {
			public function __construct( private bool $connected, private ?int $installation ) {
			}

			public function is_connected(): bool {
				return $this->connected;
			}

			public function get_installation_id(): ?int {
				return $this->installation;
			}
		};

		$lister = new class( $access ) extends RepositoryLister {
			public function __construct( private $access ) {
			}

			public function repository_access( string $owner, string $repo ) {
				return $this->access;
			}
		};

		$tokens = new class( $token ) extends InstallationTokenService {
			public function __construct( private string $token ) {
			}

			public function get_token( int $installation_id ) {
				return $this->token;
			}
		};

		$api = new class( $lookup ) extends ApiClient {
			public function __construct( private $lookup ) {
			}

			public function get( string $path, ?string $token = null ) {
				return $this->lookup;
			}
		};

		return new GitHubAppAuth( $config, $connection, $lister, $tokens, $api );
	}
}
