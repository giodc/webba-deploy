<?php
/**
 * GitHub App install / disconnect flow.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

use WebbaDeploy\Admin\Menu;
use WebbaDeploy\Security\Capability;

/**
 * Handles installation callback and connect URL generation.
 */
class ConnectController {

	private AppConfig $config;
	private ConnectionStore $connection;
	private InstallationTokenService $tokens;
	private ApiClient $api;

	public function __construct(
		?AppConfig $config = null,
		?ConnectionStore $connection = null,
		?InstallationTokenService $tokens = null,
		?ApiClient $api = null
	) {
		$this->config     = $config ?? new AppConfig();
		$this->connection = $connection ?? new ConnectionStore();
		$this->tokens     = $tokens ?? new InstallationTokenService();
		$this->api        = $api ?? new ApiClient();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . ManifestRegistrar::ACTION_INSTALL_CALLBACK, array( $this, 'handle_install_callback' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_legacy_callback' ) );
	}

	/**
	 * Build GitHub App installation URL (user picks account + repos on GitHub).
	 *
	 * @return string|\WP_Error
	 */
	public function get_install_url() {
		if ( ! $this->config->is_configured() ) {
			return new \WP_Error(
				'webba_deploy_github_not_configured',
				__( 'Set up the GitHub App first (Webba Deploy → GitHub → Set up with GitHub).', 'webba-deploy' )
			);
		}

		$state = wp_create_nonce( 'webba_deploy_github_connect' );

		set_transient(
			$this->state_transient_key(),
			$state,
			HOUR_IN_SECONDS
		);

		return sprintf(
			'https://github.com/apps/%s/installations/new?state=%s',
			rawurlencode( $this->config->get_app_slug() ),
			rawurlencode( $state )
		);
	}

	/**
	 * Process GitHub setup URL redirect (admin-post callback).
	 */
	public function handle_install_callback(): void {
		Capability::require_manage_settings();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';

		if ( ! $this->verify_state( $state ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid or expired connection state. Please try again.', 'webba-deploy' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$installation_id = isset( $_GET['installation_id'] ) ? absint( $_GET['installation_id'] ) : 0;

		if ( $installation_id <= 0 ) {
			$this->redirect_with_notice( 'error', __( 'GitHub did not return an installation ID.', 'webba-deploy' ) );
		}

		$result = $this->complete_installation( $installation_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		delete_transient( $this->state_transient_key() );
		delete_transient( 'webba_deploy_repos_' . $installation_id );

		$this->redirect_with_notice(
			'connected',
			sprintf(
				/* translators: %s: GitHub account login */
				__( 'Connected to GitHub as %s. Only repositories you selected on GitHub are available.', 'webba-deploy' ),
				$this->connection->get_account_label()
			)
		);
	}

	/**
	 * Legacy admin.php?page=webba-deploy-github&action=callback handler.
	 */
	public function maybe_handle_legacy_callback(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || Menu::SLUG_GITHUB !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'callback' !== $action ) {
			return;
		}

		$this->handle_install_callback();
	}

	/**
	 * Fetch installation metadata and persist.
	 *
	 * @param int $installation_id Installation ID.
	 * @return true|\WP_Error
	 */
	public function complete_installation( int $installation_id ) {
		$app_jwt = $this->tokens->create_app_jwt();

		if ( is_wp_error( $app_jwt ) ) {
			return $app_jwt;
		}

		$response = $this->api->get( '/app/installations/' . $installation_id, $app_jwt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$account = isset( $response['account'] ) && is_array( $response['account'] ) ? $response['account'] : array();

		$this->connection->save(
			array(
				'installation_id' => $installation_id,
				'account_login'   => (string) ( $account['login'] ?? '' ),
				'account_type'    => (string) ( $account['type'] ?? '' ),
			)
		);

		$token = $this->tokens->get_token( $installation_id );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$repos = ( new RepositoryLister( $this->tokens, $this->api, $this->connection ) )->list_repositories();

		if ( is_wp_error( $repos ) ) {
			return $repos;
		}

		if ( empty( $repos ) ) {
			return new \WP_Error(
				'webba_deploy_no_repos',
				__( 'Connected, but no repositories are available. Edit the GitHub App installation and grant access to at least one repository.', 'webba-deploy' )
			);
		}

		return true;
	}

	/**
	 * Disconnect installation and clear caches.
	 */
	public function disconnect(): void {
		$installation_id = $this->connection->get_installation_id();

		if ( null !== $installation_id ) {
			delete_transient( 'webba_deploy_repos_' . $installation_id );
			delete_transient( 'webba_deploy_it_' . $installation_id );
		}

		$this->connection->disconnect();
	}

	/**
	 * Remove stored manifest credentials (wp-config overrides are untouched).
	 */
	public function reset_app_credentials(): void {
		( new AppCredentialStore() )->delete();
	}

	/**
	 * Verify OAuth state nonce.
	 *
	 * @param string $state State from query string.
	 */
	private function verify_state( string $state ): bool {
		if ( '' === $state ) {
			return false;
		}

		$expected = get_transient( $this->state_transient_key() );

		if ( ! is_string( $expected ) || '' === $expected ) {
			return false;
		}

		return hash_equals( $expected, $state );
	}

	/**
	 * Per-user state transient key.
	 */
	private function state_transient_key(): string {
		return 'webba_deploy_github_state_' . get_current_user_id();
	}

	/**
	 * Redirect back to GitHub settings.
	 *
	 * @param string $notice  Notice key.
	 * @param string $message Message.
	 */
	private function redirect_with_notice( string $notice, string $message ): void {
		$args = array(
			'notice' => $notice,
		);

		if ( '' !== $message ) {
			$args['message'] = $message;
		}

		wp_safe_redirect( Menu::github_settings_url( $args ) );
		exit;
	}
}
