<?php
/**
 * Ring-buffer deploy log stored in options.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Logging;

/**
 * Persists deploy events (max 50 entries).
 */
class Logger {

	private const OPTION_KEY  = 'webba_deploy_logs';
	private const MAX_ENTRIES = 50;

	/**
	 * Append a log entry.
	 *
	 * @param string               $level   Log level: info|error|success.
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Additional context (no secrets).
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$settings = get_option( 'webba_deploy_settings', array() );

		if ( empty( $settings['logging'] ) ) {
			return;
		}

		$entries   = get_option( self::OPTION_KEY, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => sanitize_key( $level ),
			'message' => sanitize_text_field( $message ),
			'context' => $this->sanitize_context( $context ),
		);

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $entries, false );
	}

	/**
	 * Get recent log entries (newest last).
	 *
	 * @param int $limit Max entries to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_entries( int $limit = 50 ): array {
		$entries = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		if ( $limit > 0 && count( $entries ) > $limit ) {
			$entries = array_slice( $entries, -$limit );
		}

		return $entries;
	}

	/**
	 * Sanitize log context — strip keys that may hold secrets.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ): array {
		$deny = array( 'token', 'secret', 'password', 'authorization', 'pem', 'key' );
		$out  = array();

		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( (string) $key );
			$blocked   = false;

			foreach ( $deny as $needle ) {
				if ( false !== strpos( $key_lower, $needle ) ) {
					$blocked = true;
					break;
				}
			}

			if ( $blocked ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $out;
	}
}
