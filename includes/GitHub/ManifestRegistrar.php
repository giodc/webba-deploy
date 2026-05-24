<?php
/**
 * Register a GitHub App via manifest flow (Coolify-style, no wp-config required).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

use WebbaDeploy\Admin\Menu;
use WebbaDeploy\Security\Capability;

/**
 * Redirects admins to GitHub to create the App, then stores returned credentials.
 *
 * @see https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-from-a-manifest
 */
class ManifestRegistrar {

	private AppCredentialStore $credentials;
	private ApiClient $api;
	private ManifestSiteUrl $site_url;

	public function __construct(
		?AppCredentialStore $credentials = null,
		?ApiClient $api = null,
		?ManifestSiteUrl $site_url = null
	) {
		$this->credentials = $credentials ?? new AppCredentialStore();
		$this->api         = $api ?? new ApiClient();
		$this->site_url    = $site_url ?? new ManifestSiteUrl();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_webba_deploy_github_manifest', array( $this, 'handle_start' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_callback' ) );
	}

	/**
	 * URL to begin manifest registration (auto-submit form to GitHub).
	 */
	public function get_start_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=webba_deploy_github_manifest' ),
			'webba_deploy_github_manifest_start'
		);
	}

	/**
	 * Redirect URL GitHub uses after the user creates the App.
	 */
	public function get_manifest_callback_url(): string {
		return add_query_arg(
			array(
				'page'   => Menu::SLUG_GITHUB,
				'action' => 'manifest_callback',
			),
			$this->manifest_admin_url( 'admin.php' )
		);
	}

	/**
	 * Setup / install callback (installation_id).
	 */
	public function get_installation_callback_url(): string {
		return add_query_arg(
			array(
				'page'   => Menu::SLUG_GITHUB,
				'action' => 'callback',
			),
			$this->manifest_admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether manifest setup can run (public HTTPS URL configured).
	 *
	 * @return true|\WP_Error
	 */
	public function validate_environment() {
		return $this->site_url->validate_for_manifest();
	}

	/**
	 * Public homepage used in manifest (for admin notices).
	 */
	public function get_public_site_url(): string {
		return $this->site_url->get_homepage_url();
	}

	/**
	 * Begin manifest flow — POST form to github.com/settings/apps/new.
	 */
	public function handle_start(): void {
		Capability::require_manage_settings();

		check_admin_referer( 'webba_deploy_github_manifest_start' );

		$valid = $this->site_url->validate_for_manifest();

		if ( is_wp_error( $valid ) ) {
			$this->redirect_with_notice( 'error', $valid->get_error_message() );
		}

		$state = wp_create_nonce( 'webba_deploy_github_manifest_callback' );

		set_transient( $this->state_transient_key(), $state, HOUR_IN_SECONDS );

		$manifest = $this->build_manifest( $state );

		if ( empty( $manifest['url'] ) ) {
			$this->redirect_with_notice(
				'error',
				__( 'Could not determine a public site URL for the GitHub App manifest.', 'webba-deploy' )
			);
		}

		$this->render_github_form( $manifest );
		exit;
	}

	/**
	 * Exchange manifest code for App credentials.
	 */
	public function maybe_handle_callback(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || Menu::SLUG_GITHUB !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'manifest_callback' !== $action ) {
			return;
		}

		Capability::require_manage_settings();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';

		if ( ! $this->verify_state( $state ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid or expired setup state. Please try again.', 'webba-deploy' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';

		if ( '' === $code ) {
			$this->redirect_with_notice( 'error', __( 'GitHub did not return an authorization code.', 'webba-deploy' ) );
		}

		$conversion = $this->api->post_manifest_conversion( $code );

		if ( is_wp_error( $conversion ) ) {
			$this->redirect_with_notice( 'error', $conversion->get_error_message() );
		}

		$saved = $this->credentials->save_from_manifest( $conversion );

		if ( is_wp_error( $saved ) ) {
			$this->redirect_with_notice( 'error', $saved->get_error_message() );
		}

		delete_transient( $this->state_transient_key() );

		$connect = new ConnectController();

		$install_url = $connect->get_install_url();

		if ( is_wp_error( $install_url ) ) {
			$this->redirect_with_notice(
				'registered',
				__( 'GitHub App registered. Connect it below to choose repositories.', 'webba-deploy' )
			);
		}

		wp_safe_redirect( $install_url );
		exit;
	}

	/**
	 * Build manifest JSON for GitHub.
	 *
	 * @param string $state CSRF state passed back by GitHub.
	 * @return array<string, mixed>
	 */
	private function build_manifest( string $state ): array {
		$homepage  = $this->site_url->get_homepage_url();
		$site_name = get_bloginfo( 'name' );
		$host      = wp_parse_url( $homepage, PHP_URL_HOST );
		$name      = sprintf(
			/* translators: %s: WordPress site title or hostname */
			__( 'Webba Deploy — %s', 'webba-deploy' ),
			'' !== $site_name ? $site_name : ( is_string( $host ) ? $host : 'WordPress' )
		);

		if ( strlen( $name ) > 34 ) {
			$name = substr( $name, 0, 34 );
		}

		$callback = $this->get_installation_callback_url();

		return array(
			'name'                     => $name,
			'url'                      => $homepage,
			'description'              => 'Read-only access to deploy WordPress plugins and themes from GitHub.',
			'public'                   => false,
			'request_oauth_on_install' => false,
			'redirect_url'             => add_query_arg( 'state', rawurlencode( $state ), $this->get_manifest_callback_url() ),
			'callback_urls'            => array( $callback ),
			'setup_url'                => $callback,
			'default_permissions'      => array(
				'contents' => 'read',
				'metadata' => 'read',
			),
			'default_events'           => array(),
		);
	}

	/**
	 * Admin URL for manifest callbacks (uses public site URL when set).
	 *
	 * @param string $path Path relative to wp-admin/.
	 */
	private function manifest_admin_url( string $path ): string {
		return $this->site_url->admin_url( $path );
	}

	/**
	 * Auto-submit POST form to GitHub.
	 *
	 * @param array<string, mixed> $manifest Manifest payload.
	 */
	private function render_github_form( array $manifest ): void {
		$action = 'https://github.com/settings/apps/new';
		$json   = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) || '' === $json ) {
			wp_die( esc_html__( 'Could not build GitHub App manifest.', 'webba-deploy' ) );
		}
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php echo esc_html__( 'Redirecting to GitHub…', 'webba-deploy' ); ?></title>
		</head>
		<body>
			<p><?php echo esc_html__( 'Redirecting to GitHub to create your App…', 'webba-deploy' ); ?></p>
			<form id="webba-deploy-github-manifest" action="<?php echo esc_url( $action ); ?>" method="post">
				<textarea name="manifest" id="webba-deploy-manifest-json" hidden><?php echo esc_textarea( $json ); ?></textarea>
				<noscript>
					<p><?php echo esc_html__( 'JavaScript is required for automatic redirect. Click Continue to open GitHub.', 'webba-deploy' ); ?></p>
					<button type="submit"><?php echo esc_html__( 'Continue to GitHub', 'webba-deploy' ); ?></button>
				</noscript>
			</form>
			<script>
				document.getElementById( 'webba-deploy-github-manifest' ).submit();
			</script>
		</body>
		</html>
		<?php
	}

	/**
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
	 * Per-user manifest state transient.
	 */
	private function state_transient_key(): string {
		return 'webba_deploy_github_manifest_' . get_current_user_id();
	}

	/**
	 * Redirect back to GitHub settings.
	 *
	 * @param string $notice  Notice key.
	 * @param string $message Message.
	 */
	private function redirect_with_notice( string $notice, string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Menu::SLUG_GITHUB,
					'notice'  => $notice,
					'message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
