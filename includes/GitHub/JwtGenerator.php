<?php
/**
 * GitHub App JWT generation (RS256).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * Builds short-lived JWTs for GitHub App authentication.
 */
class JwtGenerator {

	/**
	 * Build a JWT for GitHub App authentication.
	 *
	 * @param int    $app_id          GitHub App ID.
	 * @param string $private_key_pem PEM-encoded private key.
	 * @return string|\WP_Error JWT string.
	 */
	public function create( int $app_id, string $private_key_pem ) {
		$now = time();

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$payload = array(
			'iat' => $now - 60,
			'exp' => $now + ( 9 * MINUTE_IN_SECONDS ),
			'iss' => (string) $app_id,
		);

		$segments = array(
			$this->base64url_encode( (string) wp_json_encode( $header ) ),
			$this->base64url_encode( (string) wp_json_encode( $payload ) ),
		);

		$signing_input = implode( '.', $segments );

		$key = openssl_pkey_get_private( $private_key_pem );

		if ( false === $key ) {
			return new \WP_Error(
				'webba_deploy_jwt_key',
				__( 'Invalid GitHub App private key.', 'webba-deploy' )
			);
		}

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- OpenSSL resource cleanup.
		openssl_free_key( $key );

		if ( ! $signed || '' === $signature ) {
			return new \WP_Error(
				'webba_deploy_jwt_sign',
				__( 'Could not sign the GitHub App JWT.', 'webba-deploy' )
			);
		}

		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Base64url encode without padding.
	 *
	 * @param string $value Raw string.
	 */
	private function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
