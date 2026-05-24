<?php
/**
 * Resolve a public site URL for GitHub App manifest fields.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * GitHub requires absolute homepage and callback URLs in the manifest.
 */
class ManifestSiteUrl {

	/**
	 * Homepage URL for the GitHub App (manifest "url" field).
	 */
	public function get_homepage_url(): string {
		$candidates = array();

		if ( defined( 'WEBBA_DEPLOY_GITHUB_PUBLIC_URL' ) && '' !== (string) WEBBA_DEPLOY_GITHUB_PUBLIC_URL ) {
			$candidates[] = (string) WEBBA_DEPLOY_GITHUB_PUBLIC_URL;
		}

		if ( defined( 'WP_HOME' ) && '' !== (string) WP_HOME ) {
			$candidates[] = (string) WP_HOME;
		}

		$candidates[] = home_url( '/' );
		$candidates[] = site_url( '/' );

		foreach ( $candidates as $candidate ) {
			$normalized = $this->normalize( $candidate );

			if ( '' !== $normalized ) {
				/**
				 * Filter the public site URL used in the GitHub App manifest.
				 *
				 * @param string $normalized Absolute site URL.
				 */
				return (string) apply_filters( 'webba_deploy_github_manifest_site_url', $normalized );
			}
		}

		return '';
	}

	/**
	 * Homepage URL without trailing slash (GitHub manifest "url" field).
	 */
	public function get_homepage_url_for_manifest(): string {
		$url = $this->get_homepage_url();

		return '' === $url ? '' : untrailingslashit( $url );
	}

	/**
	 * Whether the site URL looks usable for GitHub manifest registration.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_for_manifest() {
		$url = $this->get_homepage_url();

		if ( '' === $url ) {
			return new \WP_Error(
				'webba_deploy_manifest_no_url',
				__( 'WordPress site URL is empty. Set Settings → General → WordPress Address, or define WEBBA_DEPLOY_GITHUB_PUBLIC_URL in wp-config.php (use your ngrok URL for local dev).', 'webba-deploy' )
			);
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error(
				'webba_deploy_manifest_invalid_url',
				sprintf(
					/* translators: %s: site URL */
					__( 'WordPress site URL is not valid for GitHub: %s', 'webba-deploy' ),
					$url
				)
			);
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( is_string( $host ) && in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return new \WP_Error(
				'webba_deploy_manifest_localhost',
				__( 'GitHub cannot redirect to localhost. Use a public HTTPS tunnel (e.g. ngrok), set WordPress Address to that URL, or define WEBBA_DEPLOY_GITHUB_PUBLIC_URL in wp-config.php.', 'webba-deploy' )
			);
		}

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new \WP_Error(
				'webba_deploy_manifest_not_https',
				__( 'GitHub App setup requires a public HTTPS site URL. Use HTTPS or an ngrok https:// URL for local development.', 'webba-deploy' )
			);
		}

		return true;
	}

	/**
	 * Build an absolute admin URL using the manifest site origin.
	 *
	 * Preserves WordPress subdirectory paths from site_url() while swapping the
	 * public origin when WEBBA_DEPLOY_GITHUB_PUBLIC_URL is used for tunnels.
	 *
	 * @param string $admin_path Path under wp-admin, e.g. admin-post.php.
	 */
	public function admin_url( string $admin_path ): string {
		$resolved = admin_url( $admin_path );
		$public   = $this->get_homepage_url();

		if ( '' === $public ) {
			return $this->sanitize_manifest_url( $resolved );
		}

		return $this->sanitize_manifest_url( $this->replace_url_origin( $resolved, $public ) );
	}

	/**
	 * Ensure a manifest URL is absolute HTTPS and passes basic validation.
	 */
	public function sanitize_manifest_url( string $url ): string {
		$url = esc_url_raw( $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Replace scheme/host/port on a WordPress URL with the public site origin.
	 */
	private function replace_url_origin( string $url, string $public_homepage ): string {
		$public_parts = wp_parse_url( untrailingslashit( $public_homepage ) );
		$url_parts    = wp_parse_url( $url );

		if ( ! is_array( $public_parts ) || ! is_array( $url_parts ) ) {
			return $url;
		}

		$scheme = $public_parts['scheme'] ?? 'https';
		$host   = $public_parts['host'] ?? '';

		if ( '' === $host ) {
			return $url;
		}

		$port  = isset( $public_parts['port'] ) ? ':' . $public_parts['port'] : '';
		$path  = $url_parts['path'] ?? '';
		$query = isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '';

		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * @param string $url Raw URL candidate.
	 */
	private function normalize( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$url = esc_url_raw( $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		return trailingslashit( untrailingslashit( $url ) );
	}
}
