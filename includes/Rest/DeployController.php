<?php
/**
 * Push-to-deploy REST endpoint.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Rest;

use WebbaDeploy\Core\DeployService;
use WebbaDeploy\Core\PackageRepository;
use WebbaDeploy\Security\RateLimiter;
use WebbaDeploy\Security\SiteSecret;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public deploy route secured by site secret.
 */
class DeployController {

	/**
	 * Site secret validator.
	 *
	 * @var SiteSecret
	 */
	private SiteSecret $site_secret;

	/**
	 * Request rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param SiteSecret|null  $site_secret  Site secret service.
	 * @param RateLimiter|null $rate_limiter Rate limiter.
	 */
	public function __construct( ?SiteSecret $site_secret = null, ?RateLimiter $rate_limiter = null ) {
		$this->site_secret  = $site_secret ?? new SiteSecret();
		$this->rate_limiter = $rate_limiter ?? new RateLimiter();
	}

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		register_rest_route(
			'webba-deploy/v1',
			'/deploy',
			array(
				array(
					'methods'             => array( 'GET', 'POST' ),
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'authorize' ),
					'args'                => array(
						'secret'  => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'package' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_title',
						),
						'type'    => array(
							'type'              => 'string',
							'required'          => false,
							'enum'              => array( 'plugin', 'theme' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Validate secret and rate limit before deploy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		if ( ! $this->rate_limiter->is_allowed( RateLimiter::client_key_from_request() ) ) {
			return new \WP_Error(
				'webba_deploy_rate_limited',
				__( 'Too many deploy requests. Try again later.', 'webba-deploy' ),
				array( 'status' => 429 )
			);
		}

		$secret = $this->extract_secret( $request );

		if ( ! $this->site_secret->validate( $secret ) ) {
			return new \WP_Error(
				'webba_deploy_unauthorized',
				__( 'Invalid or missing deploy secret.', 'webba-deploy' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Run deploy for the requested package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$slug = $this->extract_param( $request, 'package' );
		$type = $this->extract_param( $request, 'type' );

		if ( '' === $slug || '' === $type ) {
			return new \WP_Error(
				'webba_deploy_bad_request',
				__( 'Parameters "package" and "type" are required.', 'webba-deploy' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $type, array( PackageRepository::TYPE_PLUGIN, PackageRepository::TYPE_THEME ), true ) ) {
			return new \WP_Error(
				'webba_deploy_invalid_type',
				__( 'Parameter "type" must be plugin or theme.', 'webba-deploy' ),
				array( 'status' => 400 )
			);
		}

		$service = new DeployService();
		$result  = $service->deploy_by_slug( $type, $slug, false );

		if ( is_wp_error( $result ) ) {
			$status = 500;

			if ( 'webba_deploy_not_found' === $result->get_error_code() ) {
				$status = 404;
			} elseif ( 'webba_deploy_private_requires_app' === $result->get_error_code() ) {
				$status = 403;
			}

			$data           = $result->get_error_data();
			$data           = is_array( $data ) ? $data : array();
			$data['status'] = $status;

			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$data
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Deploy completed successfully.', 'webba-deploy' ),
				'package' => $slug,
				'type'    => $type,
			),
			200
		);
	}

	/**
	 * Read a parameter from query, form body, or JSON body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Parameter name.
	 */
	private function extract_param( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		$json = $request->get_json_params();

		if ( is_array( $json ) && isset( $json[ $key ] ) && is_scalar( $json[ $key ] ) ) {
			$raw = (string) $json[ $key ];

			if ( 'type' === $key ) {
				return sanitize_key( $raw );
			}

			if ( 'package' === $key ) {
				return sanitize_title( $raw );
			}

			return sanitize_text_field( $raw );
		}

		return '';
	}

	/**
	 * Extract deploy secret from request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function extract_secret( WP_REST_Request $request ): string {
		$secret = $this->extract_param( $request, 'secret' );

		if ( '' !== $secret ) {
			return $secret;
		}

		$header = $request->get_header( 'x-webba-deploy-secret' );

		if ( is_string( $header ) && '' !== $header ) {
			return sanitize_text_field( $header );
		}

		return '';
	}
}
