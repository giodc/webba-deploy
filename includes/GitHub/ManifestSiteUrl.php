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
	 * @param string $admin_path Path under wp-admin, e.g. admin.php?page=...
	 */
	public function admin_url( string $admin_path ): string {
		$base = $this->get_homepage_url();

		if ( '' === $base ) {
			return admin_url( $admin_path );
		}

		return trailingslashit( $base ) . 'wp-admin/' . ltrim( $admin_path, '/' );
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
