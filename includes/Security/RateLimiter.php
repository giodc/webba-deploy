<?php
/**
 * Transient-based rate limiting for public deploy endpoint.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Security;

/**
 * Limits requests per client key within a time window.
 */
class RateLimiter {

	private const TRANSIENT_PREFIX = 'webba_deploy_rl_';
	private const DEFAULT_MAX      = 30;
	private const DEFAULT_WINDOW   = 60;

	/**
	 * Max requests per window.
	 *
	 * @var int
	 */
	private int $max_requests;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	private int $window_seconds;

	/**
	 * Constructor.
	 *
	 * @param int $max_requests   Maximum requests allowed per window.
	 * @param int $window_seconds Window duration in seconds.
	 */
	public function __construct( int $max_requests = self::DEFAULT_MAX, int $window_seconds = self::DEFAULT_WINDOW ) {
		$this->max_requests   = max( 1, $max_requests );
		$this->window_seconds = max( 1, $window_seconds );
	}

	/**
	 * Whether another request is allowed for this key.
	 *
	 * @param string $key Client identifier (e.g. IP hash).
	 */
	public function is_allowed( string $key ): bool {
		$transient_key = self::TRANSIENT_PREFIX . hash( 'sha256', $key );
		$count         = (int) get_transient( $transient_key );

		if ( $count >= $this->max_requests ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, $this->window_seconds );

		return true;
	}

	/**
	 * Build a rate-limit key from the current request IP.
	 */
	public static function client_key_from_request(): string {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		/**
		 * Filter the rate-limit client key.
		 *
		 * @param string $key Default key derived from IP.
		 */
		return (string) apply_filters( 'webba_deploy_rate_limit_key', $ip );
	}
}
