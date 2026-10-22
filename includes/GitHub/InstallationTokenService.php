<?php
/**
 * GitHub App installation access tokens.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * Exchanges App JWT for short-lived installation tokens (cached).
 */
class InstallationTokenService {

	/**
	 * App credentials reader.
	 *
	 * @var AppConfig
	 */
	private AppConfig $config;

	/**
	 * JWT generator for App authentication.
	 *
	 * @var JwtGenerator
	 */
	private JwtGenerator $jwt;

	/**
	 * GitHub API client.
	 *
	 * @var ApiClient
	 */
	private ApiClient $api;

	/**
	 * Constructor.
	 *
	 * @param AppConfig|null    $config Optional App credentials reader.
	 * @param JwtGenerator|null $jwt    Optional JWT generator.
	 * @param ApiClient|null    $api    Optional API client.
	 */
	public function __construct(
		?AppConfig $config = null,
		?JwtGenerator $jwt = null,
		?ApiClient $api = null
	) {
		$this->config = $config ?? new AppConfig();
		$this->jwt      = $jwt ?? new JwtGenerator();
		$this->api      = $api ?? new ApiClient();
	}

	/**
	 * Get a valid installation access token.
	 *
	 * @param int $installation_id GitHub App installation ID.
	 * @return string|\WP_Error Bearer token.
	 */
	public function get_token( int $installation_id ) {
		if ( $installation_id <= 0 ) {
			return new \WP_Error(
				'webba_deploy_no_installation',
				__( 'GitHub App is not connected.', 'webba-deploy' )
			);
		}

		$cache_key = 'webba_deploy_it_' . $installation_id;
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$app_jwt = $this->create_app_jwt();

		if ( is_wp_error( $app_jwt ) ) {
			return $app_jwt;
		}

		$response = $this->api->post(
			'/app/installations/' . $installation_id . '/access_tokens',
			null,
			$app_jwt
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$token = isset( $response['token'] ) ? (string) $response['token'] : '';

		if ( '' === $token ) {
			return new \WP_Error(
				'webba_deploy_no_token',
				__( 'GitHub did not return an installation token.', 'webba-deploy' )
			);
		}

		$ttl = 55 * MINUTE_IN_SECONDS;

		if ( ! empty( $response['expires_at'] ) ) {
			$expires = strtotime( (string) $response['expires_at'] );

			if ( $expires ) {
				$ttl = max( 60, $expires - time() - 60 );
			}
		}

		set_transient( $cache_key, $token, $ttl );

		return $token;
	}

	/**
	 * Create App JWT for GitHub API.
	 *
	 * @return string|\WP_Error
	 */
	public function create_app_jwt() {
		if ( ! $this->config->is_configured() ) {
			return new \WP_Error(
				'webba_deploy_github_not_configured',
				__( 'GitHub App is not set up. Use Webba Deploy → GitHub.', 'webba-deploy' )
			);
		}

		$app_id = $this->config->get_app_id();
		$pem    = $this->config->get_private_key_pem();

		if ( null === $app_id || null === $pem ) {
			return new \WP_Error(
				'webba_deploy_github_not_configured',
				__( 'GitHub App credentials are incomplete.', 'webba-deploy' )
			);
		}

		return $this->jwt->create( $app_id, $pem );
	}
}
