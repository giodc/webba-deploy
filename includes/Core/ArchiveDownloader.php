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
	 * Default maximum archive size (256 MB).
	 */
	private const MAX_BYTES = 268435456;

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
	 * @param string|null $token Optional Bearer token for private repos.
	 * @return string|\WP_Error Path to temp zip file.
	 */
	public function download( string $url, ?string $token = null ) {
		if ( ! $this->is_allowed_url( $url ) ) {
			return new \WP_Error(
				'webba_deploy_invalid_url',
				__( 'Only GitHub API URLs are allowed for downloads.', 'webba-deploy' )
			);
		}

		$first = $this->request_to_temp( $url, $token, 120 );

		if ( is_wp_error( $first ) ) {
			return $first;
		}

		// GitHub zipball returns 302 to codeload.github.com.
		if ( in_array( $first['code'], array( 301, 302, 303, 307, 308 ), true ) ) {
			wp_delete_file( $first['path'] );

			$location = $first['location'];

			if ( '' === $location || ! $this->is_allowed_url( $location ) ) {
				return new \WP_Error(
					'webba_deploy_redirect_blocked',
					__( 'Archive redirect to a disallowed host was blocked.', 'webba-deploy' )
				);
			}

			$second = $this->request_to_temp( $location, $token, 300 );

			if ( is_wp_error( $second ) ) {
				return $second;
			}

			if ( 200 !== $second['code'] ) {
				wp_delete_file( $second['path'] );

				return $this->download_failed( $second['code'] );
			}

			return $this->finalize_temp( $second['path'] );
		}

		if ( 200 !== $first['code'] ) {
			wp_delete_file( $first['path'] );

			return $this->download_failed( $first['code'] );
		}

		return $this->finalize_temp( $first['path'] );
	}

	/**
	 * Stream a request body into a temp file.
	 *
	 * @param string      $url     Request URL.
	 * @param string|null $token   Optional Bearer token (api.github.com only).
	 * @param int         $timeout Request timeout.
	 * @return array{path: string, code: int, location: string}|\WP_Error
	 */
	private function request_to_temp( string $url, ?string $token, int $timeout ) {
		$tmp = wp_tempnam( 'webba-deploy' );

		if ( ! $tmp ) {
			return new \WP_Error(
				'webba_deploy_temp_failed',
				__( 'Could not create a temporary file for the archive.', 'webba-deploy' )
			);
		}

		$headers = array(
			'User-Agent' => $this->user_agent(),
		);

		if ( $this->is_api_host( $url ) ) {
			$headers['Accept'] = 'application/vnd.github+json';
		}

		if ( null !== $token && '' !== $token && $this->is_api_host( $url ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => $headers,
				'stream'      => true,
				'filename'    => $tmp,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );

			return $response;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( is_array( $location ) ) {
			$location = $location[0] ?? '';
		}

		return array(
			'path'     => $tmp,
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'location' => (string) $location,
		);
	}

	/**
	 * Validate the downloaded temp file and size.
	 *
	 * @param string $path Temp file path.
	 * @return string|\WP_Error
	 */
	private function finalize_temp( string $path ) {
		$size = filesize( $path );

		if ( false === $size || $size <= 0 ) {
			wp_delete_file( $path );

			return new \WP_Error(
				'webba_deploy_empty_archive',
				__( 'Downloaded archive was empty.', 'webba-deploy' )
			);
		}

		/**
		 * Filter the maximum accepted archive size in bytes. Return 0 to disable.
		 *
		 * @param int $max_bytes Maximum size.
		 */
		$max_bytes = (int) apply_filters( 'webba_deploy_max_archive_bytes', self::MAX_BYTES );

		if ( $max_bytes > 0 && $size > $max_bytes ) {
			wp_delete_file( $path );

			return new \WP_Error(
				'webba_deploy_archive_too_large',
				sprintf(
					/* translators: %d: maximum archive size in megabytes */
					__( 'The archive exceeds the maximum allowed size of %d MB.', 'webba-deploy' ),
					(int) floor( $max_bytes / 1048576 )
				)
			);
		}

		return $path;
	}

	/**
	 * Build a download failure error.
	 *
	 * @param int $code HTTP status code.
	 */
	private function download_failed( int $code ): \WP_Error {
		return new \WP_Error(
			'webba_deploy_download_failed',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'GitHub returned HTTP %d while fetching the archive.', 'webba-deploy' ),
				$code
			)
		);
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

		$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : 0;

		if ( 0 !== $port && 443 !== $port ) {
			return false;
		}

		$host = strtolower( (string) $parsed['host'] );

		return in_array( $host, self::ALLOWED_HOSTS, true );
	}

	/**
	 * Whether a URL targets the GitHub API (the only host that should see the token).
	 *
	 * @param string $url URL to check.
	 */
	private function is_api_host( string $url ): bool {
		$parsed = wp_parse_url( $url );

		return is_array( $parsed )
			&& isset( $parsed['host'] )
			&& 'api.github.com' === strtolower( (string) $parsed['host'] );
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
