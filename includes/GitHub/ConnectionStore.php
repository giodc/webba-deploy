<?php
/**
 * Persist GitHub App installation for the site.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * Stores installation metadata in wp_options.
 */
class ConnectionStore {

	private const OPTION_KEY = 'webba_deploy_github';

	/**
	 * Whether a GitHub App installation is stored.
	 */
	public function is_connected(): bool {
		return null !== $this->get_installation_id();
	}

	/**
	 * Installation ID or null.
	 */
	public function get_installation_id(): ?int {
		$data = $this->get();

		if ( null === $data || empty( $data['installation_id'] ) ) {
			return null;
		}

		$id = (int) $data['installation_id'];

		return $id > 0 ? $id : null;
	}

	/**
	 * Full connection record.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get(): ?array {
		$data = get_option( self::OPTION_KEY, null );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Save installation after OAuth callback.
	 *
	 * @param array<string, mixed> $data Installation fields.
	 */
	public function save( array $data ): void {
		$record = array(
			'installation_id' => (int) ( $data['installation_id'] ?? 0 ),
			'account_login'   => sanitize_text_field( (string) ( $data['account_login'] ?? '' ) ),
			'account_type'    => sanitize_text_field( (string) ( $data['account_type'] ?? '' ) ),
			'connected_at'    => current_time( 'mysql' ),
		);

		update_option( self::OPTION_KEY, $record, false );
	}

	/**
	 * Remove installation data.
	 */
	public function disconnect(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Human-readable account label.
	 */
	public function get_account_label(): string {
		$data = $this->get();

		if ( null === $data || empty( $data['account_login'] ) ) {
			return '';
		}

		$login = (string) $data['account_login'];
		$type  = (string) ( $data['account_type'] ?? '' );

		if ( '' !== $type ) {
			return sprintf( '%s (%s)', $login, $type );
		}

		return $login;
	}
}
