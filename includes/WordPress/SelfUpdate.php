<?php
/**
 * Self-updates Webba Deploy from GitHub Releases.
 *
 * Uses the WordPress 5.8+ `Update URI` mechanism, which is the supported path
 * for plugins distributed outside WordPress.org. WordPress.org continues to
 * manage every other installed plugin.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\WordPress;

/**
 * Adds update offers for this plugin from its public GitHub repository.
 */
class SelfUpdate {

	private const PLUGIN_FILE = 'webba-deploy/webba-deploy.php';
	private const SLUG        = 'webba-deploy';
	private const REPO        = 'giodc/webba-deploy';
	private const HOST        = 'github.com';
	private const ASSET       = 'webba-deploy.zip';
	private const CACHE_KEY   = 'webba_deploy_self_release';
	private const CACHE_TTL   = 6 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// Dynamic hook name: update_plugins_{hostname from the Update URI header}.
		add_filter( 'update_plugins_' . self::HOST, array( $this, 'filter_update' ), 10, 3 );
	}

	/**
	 * Offer the latest GitHub release when it is newer than the installed copy.
	 *
	 * WordPress fills id, plugin, and new_version, and performs the version
	 * comparison itself.
	 *
	 * @param array<string, mixed>|false $update      Existing update data.
	 * @param array<string, mixed>       $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin basename.
	 * @return array<string, mixed>|false
	 */
	public function filter_update( $update, array $plugin_data, string $plugin_file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $plugin_data );

		if ( self::PLUGIN_FILE !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $update;
		}

		return array(
			'slug'    => self::SLUG,
			'version' => $release['version'],
			'package' => $release['package'],
			'url'     => 'https://' . self::HOST . '/' . self::REPO,
		);
	}

	/**
	 * Latest published release, cached to respect GitHub rate limits.
	 *
	 * @return array{version: string, package: string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return array(
				'version' => (string) $cached['version'],
				'package' => (string) $cached['package'],
			);
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => $this->user_agent(),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$tag = isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '';
		$tag = (string) preg_replace( '/^[vV]/', '', $tag );

		if ( '' === $tag ) {
			return null;
		}

		$package = $this->find_package_url( $data );

		if ( '' === $package ) {
			return null;
		}

		$release = array(
			'version' => $tag,
			'package' => $package,
		);

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Find the release asset WordPress should install.
	 *
	 * @param array<string, mixed> $data GitHub release payload.
	 */
	private function find_package_url( array $data ): string {
		$assets = isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array();

		foreach ( $assets as $asset ) {
			$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';

			if ( '' === $url || self::ASSET !== basename( $url ) ) {
				continue;
			}

			$parsed = wp_parse_url( $url );

			if ( ! is_array( $parsed ) ) {
				continue;
			}

			$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
			$host   = strtolower( (string) ( $parsed['host'] ?? '' ) );

			if ( 'https' === $scheme && self::HOST === $host ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * User-Agent required by the GitHub API.
	 */
	private function user_agent(): string {
		global $wp_version;

		return sprintf(
			'Webba-Deploy/%s; WordPress/%s; %s',
			WEBBA_DEPLOY_VERSION,
			$wp_version,
			home_url( '/' )
		);
	}
}
