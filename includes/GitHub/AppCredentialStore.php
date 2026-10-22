<?php
/**
 * GitHub App credentials from manifest flow (encrypted in wp_options).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * Persists App ID, slug, and private key when registered via GitHub manifest.
 * wp-config.php constants override these when defined.
 */
class AppCredentialStore {

	private const OPTION_KEY = 'webba_deploy_github_app_credentials';

	/**
	 * Whether manifest-stored credentials exist.
	 */
	public function has_credentials(): bool {
		$data = $this->get_raw();

		return null !== $data
			&& ! empty( $data['app_id'] )
			&& ! empty( $data['private_key_enc'] );
	}

	/**
	 * GitHub App numeric ID from stored credentials.
	 */
	public function get_app_id(): ?int {
		$data = $this->get_raw();

		if ( null === $data || empty( $data['app_id'] ) ) {
			return null;
		}

		$id = (int) $data['app_id'];

		return $id > 0 ? $id : null;
	}

	/**
	 * App slug from stored credentials.
	 */
	public function get_app_slug(): ?string {
		$data = $this->get_raw();

		if ( null === $data || empty( $data['app_slug'] ) ) {
			return null;
		}

		$slug = sanitize_title( (string) $data['app_slug'] );

		return '' !== $slug ? $slug : null;
	}

	/**
	 * Decrypted PEM private key.
	 */
	public function get_private_key_pem(): ?string {
		$data = $this->get_raw();

		if ( null === $data || empty( $data['private_key_enc'] ) ) {
			return null;
		}

		$pem = SecretEncryption::decrypt( (string) $data['private_key_enc'] );

		return '' !== $pem && false !== strpos( $pem, 'BEGIN' ) ? $pem : null;
	}

	/**
	 * Save credentials returned from manifest conversion.
	 *
	 * @param array<string, mixed> $conversion GitHub API conversion payload.
	 * @return true|\WP_Error
	 */
	public function save_from_manifest( array $conversion ) {
		$app_id = isset( $conversion['id'] ) ? (int) $conversion['id'] : 0;
		$slug   = isset( $conversion['slug'] ) ? sanitize_title( (string) $conversion['slug'] ) : '';
		$pem    = isset( $conversion['pem'] ) ? (string) $conversion['pem'] : '';

		if ( $app_id <= 0 || '' === $slug || '' === $pem || false === strpos( $pem, 'BEGIN' ) ) {
			return new \WP_Error(
				'webba_deploy_manifest_incomplete',
				__( 'GitHub did not return complete App credentials.', 'webba-deploy' )
			);
		}

		$encrypted = SecretEncryption::encrypt( $pem );

		if ( '' === $encrypted ) {
			return new \WP_Error(
				'webba_deploy_encrypt_failed',
				__( 'Could not securely store the GitHub App private key.', 'webba-deploy' )
			);
		}

		update_option(
			self::OPTION_KEY,
			array(
				'app_id'           => $app_id,
				'app_slug'         => $slug,
				'private_key_enc'  => $encrypted,
				'registered_at'    => current_time( 'mysql' ),
				'source'           => 'manifest',
			),
			false
		);

		return true;
	}

	/**
	 * Remove manifest-stored credentials (does not affect wp-config.php).
	 */
	public function delete(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Read the stored manifest credentials.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_raw(): ?array {
		$data = get_option( self::OPTION_KEY, null );

		return is_array( $data ) ? $data : null;
	}
}
