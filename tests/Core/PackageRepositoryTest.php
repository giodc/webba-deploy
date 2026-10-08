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
}
