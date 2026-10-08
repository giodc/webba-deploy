<?php
/**
 * Push-to-deploy settings (site secret rotation).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

use WebbaDeploy\Core\DeployUrl;
use WebbaDeploy\Security\Capability;
use WebbaDeploy\Security\SiteSecret;

/**
 * Site secret and deploy URL documentation.
 */
class SettingsPage {

	/**
	 * Render settings page.
	 */
	public function render(): void {
		Capability::require_manage_settings();

		$secret = ( new SiteSecret() )->get();
		$this->render_notices();
		?>
		<div class="wrap webba-deploy">
			<h1><?php echo esc_html__( 'Push-to-deploy', 'webba-deploy' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Trigger deploys from CI or GitHub Actions using a secret URL. Keep your site secret private.', 'webba-deploy' ); ?>
			</p>

			<h2><?php echo esc_html__( 'Site secret', 'webba-deploy' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Current secret', 'webba-deploy' ); ?></th>
					<td>
						<code class="webba-deploy-secret-mask"><?php echo esc_html( ( new SiteSecret() )->mask( $secret ) ); ?></code>
						<p class="description">
							<?php echo esc_html__( 'Full secret is only shown immediately after rotation.', 'webba-deploy' ); ?>
						</p>
						<?php if ( ! empty( $_GET['show_secret'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
							<p>
								<label for="webba-deploy-full-secret"><?php echo esc_html__( 'New secret (copy now):', 'webba-deploy' ); ?></label><br />
								<input
									type="text"
									readonly
									class="large-text code webba-deploy-copy-input"
									id="webba-deploy-full-secret"
									value="<?php echo esc_attr( $secret ); ?>"
								/>
								<button
									type="button"
									class="button webba-deploy-copy"
									data-copy-target="webba-deploy-full-secret"
									data-copied-label="<?php echo esc_attr__( 'Copied!', 'webba-deploy' ); ?>"
								>
									<?php echo esc_html__( 'Copy', 'webba-deploy' ); ?>
								</button>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Rotate secret', 'webba-deploy' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Rotate the deploy secret? Existing CI jobs must be updated.', 'webba-deploy' ) ); ?>');">
							<?php wp_nonce_field( 'webba_deploy_rotate_secret' ); ?>
							<input type="hidden" name="action" value="webba_deploy_action" />
							<input type="hidden" name="webba_deploy_action" value="rotate_secret" />
							<?php submit_button( __( 'Generate new secret', 'webba-deploy' ), 'delete', 'submit', false ); ?>
						</form>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Deploy endpoint', 'webba-deploy' ); ?></h2>
			<p><code><?php echo esc_html( DeployUrl::endpoint() ); ?></code></p>

			<h3><?php echo esc_html__( 'Example request', 'webba-deploy' ); ?></h3>
			<pre class="webba-deploy-code"><code><?php echo esc_html( $this->curl_example( $secret ) ); ?></code></pre>

			<p class="description">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: path to example workflow file */
						__( 'See %s for a GitHub Actions workflow template.', 'webba-deploy' ),
						'<code>docs/examples/deploy-on-push.yml</code>'
					)
				);
				?>
			</p>

			<p>
				<a href="<?php echo esc_url( $this->dashboard_url() ); ?>">&larr; <?php echo esc_html__( 'Back to dashboard', 'webba-deploy' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Example curl command for documentation.
	 *
	 * @param string $secret Site secret.
	 */
	private function curl_example( string $secret ): string {
		$url = DeployUrl::for_package( 'plugin', 'your-plugin-slug', $secret );

		return 'curl -fsS -X POST "' . $url . '"';
	}

	/**
	 * Render admin notices.
	 */
	private function render_notices(): void {
		if ( empty( $_GET['notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( wp_unslash( (string) $_GET['notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'rotated' !== $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Deploy secret rotated. Copy the new secret below and update your CI configuration.', 'webba-deploy' )
		);
	}

	/**
	 * Dashboard URL.
	 */
	private function dashboard_url(): string {
		return add_query_arg( 'page', Menu::SLUG_DASHBOARD, admin_url( 'admin.php' ) );
	}
}
