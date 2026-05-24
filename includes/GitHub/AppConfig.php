<?php
/**
 * GitHub App configuration from wp-config constants or manifest storage.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * Reads App ID, slug, and private key from constants (override) or encrypted options.
 */
class AppConfig {

	private AppCredentialStore $store;

	public function __construct( ?AppCredentialStore $store = null ) {
		$this->store = $store ?? new AppCredentialStore();
	}

	/**
	 * GitHub App numeric ID.
	 */
	public function get_app_id(): ?int {
		if ( defined( 'WEBBA_DEPLOY_GITHUB_APP_ID' ) ) {
			$id = (int) WEBBA_DEPLOY_GITHUB_APP_ID;

			return $id > 0 ? $id : null;
		}

		return $this->store->get_app_id();
	}

	/**
	 * GitHub App URL slug (used in github.com/apps/{slug}).
	 */
	public function get_app_slug(): string {
		if ( defined( 'WEBBA_DEPLOY_GITHUB_APP_SLUG' ) ) {
			$slug = sanitize_title( (string) WEBBA_DEPLOY_GITHUB_APP_SLUG );

			if ( '' !== $slug ) {
				return $slug;
			}
		}

		$stored = $this->store->get_app_slug();

		return null !== $stored ? $stored : 'webba-deploy';
	}

	/**
	 * PEM private key contents.
	 */
	public function get_private_key_pem(): ?string {
		if ( defined( 'WEBBA_DEPLOY_GITHUB_APP_KEY' ) ) {
			return $this->resolve_key_constant( (string) WEBBA_DEPLOY_GITHUB_APP_KEY );
		}

		return $this->store->get_private_key_pem();
	}

	/**
	 * Whether App credentials are available (wp-config or manifest store).
	 */
	public function is_configured(): bool {
		return null !== $this->get_app_id() && null !== $this->get_private_key_pem();
	}

	/**
	 * Whether credentials come from wp-config.php (overrides stored manifest app).
	 */
	public function uses_wp_config(): bool {
		if ( ! defined( 'WEBBA_DEPLOY_GITHUB_APP_ID' ) || ! defined( 'WEBBA_DEPLOY_GITHUB_APP_KEY' ) ) {
			return false;
		}

		return null !== $this->resolve_key_constant( (string) WEBBA_DEPLOY_GITHUB_APP_KEY )
			&& (int) WEBBA_DEPLOY_GITHUB_APP_ID > 0;
	}

	/**
	 * Whether credentials were saved via GitHub manifest flow.
	 */
	public function uses_stored_credentials(): bool {
		return $this->store->has_credentials();
	}

	/**
	 * Human-readable credential source for admin UI.
	 */
	public function get_credential_source(): string {
		if ( $this->uses_wp_config() ) {
			return 'wp-config';
		}

		if ( $this->uses_stored_credentials() ) {
			return 'manifest';
		}

		return 'none';
	}

	/**
	 * Which credential pieces are present (for admin setup hints).
	 *
	 * @return array{app_id: bool, private_key: bool, source: string}
	 */
	public function get_credential_status(): array {
		return array(
			'app_id'      => null !== $this->get_app_id(),
			'private_key' => null !== $this->get_private_key_pem(),
			'source'      => $this->get_credential_source(),
		);
	}

	/**
	 * Suggested read-only repository permissions (Coolify-style).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function suggested_permissions(): array {
		return array(
			array(
				'name'   => __( 'Contents', 'webba-deploy' ),
				'access' => __( 'Read-only', 'webba-deploy' ),
				'why'    => __( 'Download zipball archives for deploy.', 'webba-deploy' ),
			),
			array(
				'name'   => __( 'Metadata', 'webba-deploy' ),
				'access' => __( 'Read-only', 'webba-deploy' ),
				'why'    => __( 'List repositories and read default branch.', 'webba-deploy' ),
			),
		);
	}

	/**
	 * Resolve WEBBA_DEPLOY_GITHUB_APP_KEY constant value.
	 *
	 * @param string $key Path, PEM string, or base64 PEM.
	 */
	private function resolve_key_constant( string $key ): ?string {
		if ( '' === $key ) {
			return null;
		}

		if ( 0 === strpos( $key, '-----BEGIN' ) ) {
			return $key;
		}

		if ( is_readable( $key ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $key );

			return is_string( $contents ) && false !== strpos( $contents, 'BEGIN' ) ? $contents : null;
		}

		$decoded = base64_decode( $key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( is_string( $decoded ) && false !== strpos( $decoded, 'BEGIN' ) ) {
			return $decoded;
		}

		return null;
	}
}
