<?php
/**
 * Tests for GitHub URL parsing.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\Core\PackageRepository;

final class PackageRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_parse_github_url_reads_owner_and_repo(): void {
		$this->assertSame(
			array(
				'owner' => 'acme',
				'repo'  => 'my-plugin',
			),
			PackageRepository::parse_github_url( 'https://github.com/acme/my-plugin.git' )
		);
	}

	public function test_parse_github_url_ignores_extra_path_segments(): void {
		$this->assertSame(
			array(
				'owner' => 'acme',
				'repo'  => 'my-plugin',
			),
			PackageRepository::parse_github_url( 'https://github.com/acme/my-plugin/tree/main/sub' )
		);
	}

	public function test_parse_github_url_rejects_invalid_input(): void {
		$this->assertNull( PackageRepository::parse_github_url( '' ) );
		$this->assertNull( PackageRepository::parse_github_url( 'https://gitlab.com/acme/repo' ) );
		$this->assertNull( PackageRepository::parse_github_url( 'https://github.com/onlyowner' ) );
		$this->assertNull( PackageRepository::parse_github_url( 'https://github.com/-badowner/repo' ) );
	}

	public function test_is_valid_branch_accepts_common_names(): void {
		$this->assertTrue( PackageRepository::is_valid_branch( 'main' ) );
		$this->assertTrue( PackageRepository::is_valid_branch( 'feature/new-ui' ) );
		$this->assertTrue( PackageRepository::is_valid_branch( 'release/1.2.3' ) );
		$this->assertTrue( PackageRepository::is_valid_branch( 'fix_bug-42' ) );
	}

	public function test_is_valid_branch_rejects_invalid_names(): void {
		$this->assertFalse( PackageRepository::is_valid_branch( '' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( 'has space' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( '../main' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( 'main//sub' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( '/main' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( 'main/' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( '.hidden' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( 'main.lock' ) );
		$this->assertFalse( PackageRepository::is_valid_branch( 'bad~char' ) );
	}
}
