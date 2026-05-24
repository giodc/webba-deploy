<?php
/**
 * Download GitHub zipball archives safely (SSRF-hardened).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Core;

/**
 * Fetches repository archives from allowed GitHub hosts only.
 */
class ArchiveDownloader {

	private const ALLOWED_HOSTS = array(
		'api.github.com',
		'github.com',
		'codeload.github.com',
	);

	/**
	 * Build zipball API URL for a public repository.
	 *
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @param string $branch Branch or ref.
	 */
	public function build_zipball_url( string $owner, string $repo, string $branch ): string {
		$owner  = rawurlencode( $owner );
		$repo   = rawurlencode( $repo );
		$branch = rawurlencode( $branch );

		return sprintf(
			'https://api.github.com/repos/%s/%s/zipball/%s',
			$owner,
			$repo,
			$branch
		);
	}

	/**
	 * Download archive to a temp file.
	 *
	 * @param string      $url   Initial request URL (must be api.github.com).
	 * @param string|null $token Optional Bearer token for private repos (Phase 4+).
	 * @return string|\WP_Error Path to temp zip file.
	 */
	public function download( string $url, ?string $token = null ) {
		if ( ! $this->is_allowed_url( $url ) ) {
			return new \WP_Error(
				'webba_deploy_invalid_url',
				__( 'Only GitHub API URLs are allowed for downloads.', 'webba-deploy' )
			);
		}

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => $this->user_agent(),
		);

		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 120,
				'redirection' => 0,
				'headers'     => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// GitHub zipball returns 302 to codeload.github.com.
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( is_array( $location ) ) {
				$location = $location[0] ?? '';
			}

			$location = (string) $location;

			if ( '' === $location || ! $this->is_allowed_url( $location ) ) {
				return new \WP_Error(
					'webba_deploy_redirect_blocked',
					__( 'Archive redirect to a disallowed host was blocked.', 'webba-deploy' )
				);
			}

			return $this->download_binary( $location, $token );
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				'webba_deploy_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GitHub returned HTTP %d while fetching the archive.', 'webba-deploy' ),
					$code
				)
			);
		}

		return $this->save_body_to_temp( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Download binary from redirect target (codeload).
	 *
	 * @param string      $url   Redirect URL.
	 * @param string|null $token Optional token.
	 * @return string|\WP_Error
	 */
	private function download_binary( string $url, ?string $token = null ) {
		$headers = array(
			'User-Agent' => $this->user_agent(),
		);

		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'redirection' => 0,
				'headers'     => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'webba_deploy_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Archive download failed with HTTP %d.', 'webba-deploy' ),
					$code
				)
			);
		}

		return $this->save_body_to_temp( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Persist response body to a temp zip file.
	 *
	 * @param string $body Response body.
	 * @return string|\WP_Error
	 */
	private function save_body_to_temp( string $body ) {
		if ( '' === $body ) {
			return new \WP_Error(
				'webba_deploy_empty_archive',
				__( 'Downloaded archive was empty.', 'webba-deploy' )
			);
		}

		$tmp = wp_tempnam( 'webba-deploy' );

		if ( ! $tmp ) {
			return new \WP_Error(
				'webba_deploy_temp_failed',
				__( 'Could not create a temporary file for the archive.', 'webba-deploy' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $tmp, $body );

		if ( false === $written ) {
			wp_delete_file( $tmp );

			return new \WP_Error(
				'webba_deploy_write_failed',
				__( 'Could not write the archive to disk.', 'webba-deploy' )
			);
		}

		return $tmp;
	}

	/**
	 * Validate URL host against allowlist.
	 *
	 * @param string $url URL to check.
	 */
	public function is_allowed_url( string $url ): bool {
		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );

		if ( 'https' !== $scheme ) {
			return false;
		}

		$host = strtolower( (string) $parsed['host'] );

		return in_array( $host, self::ALLOWED_HOSTS, true );
	}

	/**
	 * User-Agent required by GitHub API.
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
