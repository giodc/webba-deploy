<?php
/**
 * GitHub REST API client.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * SSRF-safe GitHub API requests.
 */
class ApiClient {

	private const API_BASE = 'https://api.github.com';

	/**
	 * Perform GET request against GitHub API.
	 *
	 * @param string      $path  API path starting with /.
	 * @param string|null $token Bearer token (installation or app JWT).
	 * @return array<string, mixed>|\WP_Error Decoded JSON body.
	 */
	public function get( string $path, ?string $token = null ) {
		return $this->request( 'GET', $path, null, $token );
	}

	/**
	 * Perform POST request against GitHub API.
	 *
	 * @param string               $path  API path.
	 * @param array<string, mixed> $body  JSON body.
	 * @param string|null          $token Bearer token.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function post( string $path, array $body = array(), ?string $token = null ) {
		return $this->request( 'POST', $path, $body, $token );
	}

	/**
	 * Exchange a manifest registration code for App credentials.
	 *
	 * @param string $code Temporary code from GitHub redirect.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function post_manifest_conversion( string $code ) {
		$code = preg_replace( '/[^a-zA-Z0-9]/', '', $code );

		if ( '' === $code ) {
			return new \WP_Error(
				'webba_deploy_manifest_invalid_code',
				__( 'Invalid GitHub manifest code.', 'webba-deploy' )
			);
		}

		return $this->request( 'POST', '/app-manifests/' . $code . '/conversions', array(), null );
	}

	/**
	 * HTTP request helper.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   API path.
	 * @param array<string, mixed>|null $body   JSON body for POST.
	 * @param string|null              $token  Bearer token.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body, ?string $token ) {
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		$url = self::API_BASE . $path;

		if ( ! $this->is_allowed_url( $url ) ) {
			return new \WP_Error(
				'webba_deploy_invalid_url',
				__( 'Only GitHub API URLs are allowed.', 'webba-deploy' )
			);
		}

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => $this->user_agent(),
		);

		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		);

		if ( 'POST' === $method && null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->extract_error_message( $response );

			return new \WP_Error(
				'webba_deploy_github_api_error',
				$message,
				array( 'status' => $code )
			);
		}

		if ( 204 === $code ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'webba_deploy_github_invalid_json',
				__( 'GitHub API returned an invalid response.', 'webba-deploy' )
			);
		}

		return $decoded;
	}

	/**
	 * Parse GitHub error message from response body.
	 *
	 * @param array<string, mixed> $response HTTP response.
	 */
	private function extract_error_message( array $response ): string {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			return sprintf(
				/* translators: %s: GitHub error message */
				__( 'GitHub API error: %s', 'webba-deploy' ),
				sanitize_text_field( (string) $body['message'] )
			);
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'GitHub API returned HTTP %d.', 'webba-deploy' ),
			(int) wp_remote_retrieve_response_code( $response )
		);
	}

	/**
	 * Validate URL is GitHub API over HTTPS.
	 *
	 * @param string $url Full URL.
	 */
	private function is_allowed_url( string $url ): bool {
		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return false;
		}

		return 'https' === ( $parsed['scheme'] ?? '' ) && 'api.github.com' === strtolower( (string) $parsed['host'] );
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
