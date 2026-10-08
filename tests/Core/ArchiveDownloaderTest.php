<?php
/**
 * Tests for the GitHub archive URL handling.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WebbaDeploy\Core\ArchiveDownloader;

final class ArchiveDownloaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_build_zipball_url_encodes_components(): void {
		$downloader = new ArchiveDownloader();

		$this->assertSame(
			'https://api.github.com/repos/acme/my%20repo/zipball/feature%2Fbranch',
			$downloader->build_zipball_url( 'acme', 'my repo', 'feature/branch' )
		);
	}

	public function test_is_allowed_url_accepts_github_hosts_over_https(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$downloader = new ArchiveDownloader();

		$this->assertTrue( $downloader->is_allowed_url( 'https://api.github.com/repos/a/b/zipball/main' ) );
		$this->assertTrue( $downloader->is_allowed_url( 'https://codeload.github.com/a/b/legacy.zip/main' ) );
	}

	public function test_is_allowed_url_rejects_other_hosts_and_schemes(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$downloader = new ArchiveDownloader();

		$this->assertFalse( $downloader->is_allowed_url( 'https://evil.example.com/repos/a/b' ) );
		$this->assertFalse( $downloader->is_allowed_url( 'http://api.github.com/repos/a/b' ) );
		$this->assertFalse( $downloader->is_allowed_url( 'not a url' ) );
		$this->assertFalse( $downloader->is_allowed_url( '' ) );
	}
}
