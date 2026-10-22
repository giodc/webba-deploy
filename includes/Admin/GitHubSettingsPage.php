<?php
/**
 * GitHub App connection settings.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

use WebbaDeploy\GitHub\AppConfig;
use WebbaDeploy\GitHub\ConnectController;
use WebbaDeploy\GitHub\ConnectionStore;
use WebbaDeploy\GitHub\ManifestRegistrar;
use WebbaDeploy\Security\Capability;

/**
 * GitHub App manifest setup + optional wp-config overrides.
 */
class GitHubSettingsPage {

	/**
	 * Render settings page.
	 */
	public function render(): void {
		Capability::require_manage_settings();

		$config     = new AppConfig();
		$connection = new ConnectionStore();
		$connect    = new ConnectController();
		$manifest   = new ManifestRegistrar();

		$this->render_notices();
		?>
		<div class="wrap webba-deploy">
			<h1><?php echo esc_html__( 'GitHub connection', 'webba-deploy' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Set up a read-only GitHub App in a few clicks: create the app on GitHub, pick repositories, then deploy private plugins and themes.', 'webba-deploy' ); ?>
			</p>

			<h2><?php echo esc_html__( 'App permissions (read-only)', 'webba-deploy' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Permission', 'webba-deploy' ); ?></th>
						<th><?php echo esc_html__( 'Access', 'webba-deploy' ); ?></th>
						<th><?php echo esc_html__( 'Purpose', 'webba-deploy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( AppConfig::suggested_permissions() as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( $row['access'] ); ?></td>
							<td><?php echo esc_html( $row['why'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'GitHub App', 'webba-deploy' ); ?></h2>
			<?php $this->render_app_section( $config, $manifest, $connect ); ?>

			<h2><?php echo esc_html__( 'Installation', 'webba-deploy' ); ?></h2>
			<?php $this->render_installation_section( $config, $connection, $connect ); ?>

			<details class="webba-deploy-advanced">
				<summary><?php echo esc_html__( 'Advanced: wp-config.php override (optional)', 'webba-deploy' ); ?></summary>
				<p class="description">
					<?php echo esc_html__( 'Hosts can define WEBBA_DEPLOY_GITHUB_APP_ID, WEBBA_DEPLOY_GITHUB_APP_SLUG, and WEBBA_DEPLOY_GITHUB_APP_KEY in wp-config.php to use a shared App instead of the one-click setup.', 'webba-deploy' ); ?>
				</p>
				<p><code><?php echo esc_html( $manifest->get_installation_callback_url() ); ?></code></p>
				<p class="description"><?php echo esc_html__( 'Setup URL if you create the App manually on GitHub.', 'webba-deploy' ); ?></p>
				<p>
					<?php
					echo esc_html__(
						'See webba-deploy-config-sample.php and docs/github-app-setup.md.',
						'webba-deploy'
					);
					?>
				</p>
			</details>

			<p>
				<a href="<?php echo esc_url( $this->dashboard_url() ); ?>">&larr; <?php echo esc_html__( 'Back to dashboard', 'webba-deploy' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * GitHub App registration block.
	 *
	 * @param AppConfig         $config   App config.
	 * @param ManifestRegistrar $manifest Manifest helper.
	 * @param ConnectController $connect  Connect flow.
	 */
	private function render_app_section( AppConfig $config, ManifestRegistrar $manifest, ConnectController $connect ): void {
		unset( $connect );

		if ( $config->is_configured() ) {
			$source = $config->get_credential_source();
			?>
			<p>
				<strong><?php echo esc_html__( 'Status:', 'webba-deploy' ); ?></strong>
				<?php echo esc_html__( 'App configured', 'webba-deploy' ); ?>
				<?php if ( 'wp-config' === $source ) : ?>
					<span class="description">(<?php echo esc_html__( 'wp-config.php', 'webba-deploy' ); ?>)</span>
				<?php else : ?>
					<span class="description">(<?php echo esc_html__( 'registered on this site', 'webba-deploy' ); ?>)</span>
				<?php endif; ?>
			</p>
			<ul class="webba-deploy-setup-status description">
				<li class="is-ok">
					<?php
					printf(
						/* translators: 1: numeric app id, 2: app slug */
						esc_html__( 'App ID %1$s — slug %2$s', 'webba-deploy' ),
						esc_html( (string) $config->get_app_id() ),
						esc_html( $config->get_app_slug() )
					);
					?>
				</li>
			</ul>
			<?php if ( 'manifest' === $source && ! $config->uses_wp_config() ) : ?>
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="webba-deploy-inline-form"
					onsubmit="return confirm('<?php echo esc_js( __( 'Remove the GitHub App from this site? You can register again with Set up with GitHub.', 'webba-deploy' ) ); ?>');"
				>
					<?php wp_nonce_field( 'webba_deploy_github_reset_app' ); ?>
					<input type="hidden" name="action" value="webba_deploy_action" />
					<input type="hidden" name="webba_deploy_action" value="github_reset_app" />
					<?php submit_button( __( 'Remove App registration', 'webba-deploy' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<?php
			return;
		}

		$env        = $manifest->validate_environment();
		$public_url = $manifest->get_public_site_url();
		?>
		<p>
			<strong><?php echo esc_html__( 'Status:', 'webba-deploy' ); ?></strong>
			<?php echo esc_html__( 'Not set up', 'webba-deploy' ); ?>
		</p>
		<?php if ( is_wp_error( $env ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php echo esc_html__( 'One-click setup is not available yet', 'webba-deploy' ); ?></strong></p>
				<p><?php echo esc_html( $env->get_error_message() ); ?></p>
			</div>
			<p class="description">
				<?php echo esc_html__( 'For local development: start an HTTPS tunnel (e.g. ngrok), point WordPress Address to that URL, or add WEBBA_DEPLOY_GITHUB_PUBLIC_URL in wp-config.php. See docs/github-app-setup.md.', 'webba-deploy' ); ?>
			</p>
		<?php else : ?>
			<?php if ( '' !== $public_url ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: public HTTPS site URL used in the GitHub App manifest */
						esc_html__( 'GitHub will use this site URL: %s', 'webba-deploy' ),
						esc_html( $public_url )
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<a class="button button-primary button-hero" href="<?php echo esc_url( $manifest->get_start_url() ); ?>">
					<?php echo esc_html__( 'Set up with GitHub', 'webba-deploy' ); ?>
				</a>
			</p>
			<p class="description">
				<?php echo esc_html__( 'You will name the App on GitHub, confirm read-only permissions, then choose which repositories to allow.', 'webba-deploy' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Installation / repository access block.
	 *
	 * @param AppConfig         $config     App config.
	 * @param ConnectionStore   $connection Installation store.
	 * @param ConnectController $connect    Connect flow.
	 */
	private function render_installation_section( AppConfig $config, ConnectionStore $connection, ConnectController $connect ): void {
		if ( ! $config->is_configured() ) {
			echo '<p class="description">' . esc_html__( 'Set up the GitHub App first.', 'webba-deploy' ) . '</p>';
			return;
		}

		if ( $connection->is_connected() ) {
			?>
			<p>
				<strong><?php echo esc_html__( 'Connected as', 'webba-deploy' ); ?>:</strong>
				<?php echo esc_html( $connection->get_account_label() ); ?>
			</p>
			<p class="description">
				<?php echo esc_html__( 'To change repositories, open the App installation on GitHub and adjust access, then click Reconnect.', 'webba-deploy' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect this installation? Private deploys will stop until you reconnect.', 'webba-deploy' ) ); ?>');">
				<?php wp_nonce_field( 'webba_deploy_github_disconnect' ); ?>
				<input type="hidden" name="action" value="webba_deploy_action" />
				<input type="hidden" name="webba_deploy_action" value="github_disconnect" />
				<?php submit_button( __( 'Disconnect installation', 'webba-deploy' ), 'delete', 'submit', false ); ?>
			</form>
			<?php
			$install_url = $connect->get_install_url();
			if ( ! is_wp_error( $install_url ) ) :
				?>
				<p>
					<a class="button" href="<?php echo esc_url( $install_url ); ?>">
						<?php echo esc_html__( 'Reconnect / change repositories', 'webba-deploy' ); ?>
					</a>
				</p>
			<?php endif; ?>
			<?php
			return;
		}

		$install_url = $connect->get_install_url();
		?>
		<p>
			<strong><?php echo esc_html__( 'Status:', 'webba-deploy' ); ?></strong>
			<?php echo esc_html__( 'App not installed on an account yet', 'webba-deploy' ); ?>
		</p>
		<?php if ( is_wp_error( $install_url ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $install_url->get_error_message() ); ?></p></div>
		<?php else : ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $install_url ); ?>">
					<?php echo esc_html__( 'Continue GitHub App installation', 'webba-deploy' ); ?>
				</a>
			</p>
			<p class="description">
				<?php echo esc_html__( 'The App is registered — finish on GitHub by choosing your user or organization, then "Only select repositories" (recommended).', 'webba-deploy' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render admin notices.
	 */
	private function render_notices(): void {
		if ( empty( $_GET['notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice  = sanitize_key( wp_unslash( (string) $_GET['notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = '';
		if ( isset( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Display-only notice; sanitized below. Nonce not required for read-only GET flash.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$message = sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['message'] ) ) );
		}

		if ( '' === $message ) {
			switch ( $notice ) {
				case 'connected':
					$message = __( 'GitHub App connected successfully.', 'webba-deploy' );
					break;
				case 'disconnected':
					$message = __( 'GitHub installation disconnected.', 'webba-deploy' );
					break;
				case 'registered':
					$message = __( 'GitHub App registered. Continue installation to choose repositories.', 'webba-deploy' );
					break;
				case 'reset':
					$message = __( 'GitHub App registration removed from this site.', 'webba-deploy' );
					break;
				default:
					return;
			}
		}

		$class = in_array( $notice, array( 'error' ), true ) ? 'notice-error' : 'notice-success';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Dashboard URL.
	 */
	private function dashboard_url(): string {
		return add_query_arg( 'page', Menu::SLUG_DASHBOARD, admin_url( 'admin.php' ) );
	}
}
