<?php
/**
 * Register a GitHub App via manifest flow (no wp-config required).
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

	public const ACTION_MANIFEST_CALLBACK = 'webba_deploy_github_manifest_callback';
	public const ACTION_INSTALL_CALLBACK  = 'webba_deploy_github_install_callback';

	/**
	 * Credential store for manifest-created Apps.
	 *
	 * @var AppCredentialStore
	 */
	private AppCredentialStore $credentials;

	/**
	 * GitHub API client.
	 *
	 * @var ApiClient
	 */
	private ApiClient $api;

	/**
	 * Public site URL resolver.
	 *
	 * @var ManifestSiteUrl
	 */
	private ManifestSiteUrl $site_url;

	/**
	 * Constructor.
	 *
	 * @param AppCredentialStore|null $credentials Optional credential store.
	 * @param ApiClient|null          $api         Optional API client.
	 * @param ManifestSiteUrl|null    $site_url    Optional site URL resolver.
	 */
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
		add_action( 'admin_post_' . self::ACTION_MANIFEST_CALLBACK, array( $this, 'handle_manifest_callback' ) );
		// Legacy admin.php callback URLs registered before admin-post switch.
		add_action( 'admin_init', array( $this, 'maybe_handle_legacy_callback' ) );
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
			'action',
			self::ACTION_MANIFEST_CALLBACK,
			$this->site_url->admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Setup / install callback (installation_id).
	 */
	public function get_installation_callback_url(): string {
		return add_query_arg(
			'action',
			self::ACTION_INSTALL_CALLBACK,
			$this->site_url->admin_url( 'admin-post.php' )
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

		$manifest = $this->build_manifest();

		if ( empty( $manifest['url'] ) ) {
			$this->redirect_with_notice(
				'error',
				__( 'Could not determine a public site URL for the GitHub App manifest.', 'webba-deploy' )
			);
		}

		$url_error = $this->validate_manifest_urls( $manifest );

		if ( is_wp_error( $url_error ) ) {
			$this->redirect_with_notice( 'error', $url_error->get_error_message() );
		}

		$this->render_github_form( $manifest, $state );
		exit;
	}

	/**
	 * Exchange manifest code for App credentials (admin-post callback).
	 */
	public function handle_manifest_callback(): void {
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
				__( 'GitHub App registered. Continue installation to choose repositories.', 'webba-deploy' )
			);
		}

		$this->redirect_to_github_install( $install_url );
	}

	/**
	 * Legacy admin.php?page=webba-deploy-github&action=manifest_callback handler.
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

		if ( 'manifest_callback' !== $action ) {
			return;
		}

		$this->handle_manifest_callback();
	}

	/**
	 * Build manifest JSON for GitHub.
	 *
	 * @return array<string, mixed>
	 */
	private function build_manifest(): array {
		$homepage  = $this->site_url->get_homepage_url_for_manifest();
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

		$redirect_url = $this->site_url->sanitize_manifest_url( $this->get_manifest_callback_url() );
		$callback_url = $this->site_url->sanitize_manifest_url( $this->get_installation_callback_url() );

		return array(
			'name'                     => $name,
			'url'                      => $homepage,
			'description'              => 'Read-only access to deploy WordPress plugins and themes from GitHub.',
			'public'                   => false,
			'request_oauth_on_install' => false,
			'redirect_url'             => $redirect_url,
			'callback_urls'            => array( $callback_url ),
			'setup_url'                => $callback_url,
			'default_permissions'      => array(
				'contents' => 'read',
				'metadata' => 'read',
			),
			'default_events'           => array(),
		);
	}

	/**
	 * Validate manifest URLs before posting to GitHub.
	 *
	 * @param array<string, mixed> $manifest Manifest payload.
	 * @return true|\WP_Error
	 */
	private function validate_manifest_urls( array $manifest ) {
		$checks = array(
			'url'          => __( 'homepage URL', 'webba-deploy' ),
			'redirect_url' => __( 'redirect URL', 'webba-deploy' ),
		);

		foreach ( $checks as $key => $label ) {
			$value = isset( $manifest[ $key ] ) ? (string) $manifest[ $key ] : '';

			if ( '' === $value || ! wp_http_validate_url( $value ) ) {
				return new \WP_Error(
					'webba_deploy_manifest_invalid_' . $key,
					sprintf(
						/* translators: 1: URL field label, 2: URL value */
						__( 'GitHub App %1$s is not valid: %2$s', 'webba-deploy' ),
						$label,
						$value
					)
				);
			}
		}

		if ( empty( $manifest['callback_urls'] ) || ! is_array( $manifest['callback_urls'] ) ) {
			return new \WP_Error(
				'webba_deploy_manifest_invalid_callbacks',
				__( 'GitHub App callback URL is missing.', 'webba-deploy' )
			);
		}

		foreach ( $manifest['callback_urls'] as $callback_url ) {
			$callback_url = (string) $callback_url;

			if ( '' === $callback_url || ! wp_http_validate_url( $callback_url ) ) {
				return new \WP_Error(
					'webba_deploy_manifest_invalid_callback',
					sprintf(
						/* translators: %s: callback URL */
						__( 'GitHub App callback URL is not valid: %s', 'webba-deploy' ),
						$callback_url
					)
				);
			}
		}

		return true;
	}

	/**
	 * Auto-submit POST form to GitHub.
	 *
	 * @param array<string, mixed> $manifest Manifest payload.
	 * @param string               $state    CSRF state for the GitHub form action URL.
	 */
	private function render_github_form( array $manifest, string $state ): void {
		$form_action = add_query_arg(
			'state',
			$state,
			'https://github.com/settings/apps/new'
		);
		$json        = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES );

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
			<form id="webba-deploy-github-manifest" action="<?php echo esc_url( $form_action ); ?>" method="post">
				<input type="hidden" name="manifest" value="<?php echo esc_attr( $json ); ?>" />
				<noscript>
					<p><?php echo esc_html__( 'Click Continue to open GitHub.', 'webba-deploy' ); ?></p>
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
	 * Validate the OAuth state nonce.
	 *
	 * @param string $state State from query string.
	 * @return bool
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
		$args = array(
			'notice' => $notice,
		);

		if ( '' !== $message ) {
			$args['message'] = $message;
		}

		wp_safe_redirect( Menu::github_settings_url( $args ) );
		exit;
	}

	/**
	 * Redirect to GitHub's App installation screen.
	 *
	 * @param string $url GitHub install URL.
	 */
	private function redirect_to_github_install( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || 'github.com' !== strtolower( $host ) ) {
			$this->redirect_with_notice(
				'error',
				__( 'Invalid GitHub install URL.', 'webba-deploy' )
			);
		}

		$allow_github = static function ( array $hosts ): array {
			$hosts[] = 'github.com';
			return $hosts;
		};

		add_filter( 'allowed_redirect_hosts', $allow_github );
		wp_safe_redirect( $url );
		remove_filter( 'allowed_redirect_hosts', $allow_github );
		exit;
	}
}
