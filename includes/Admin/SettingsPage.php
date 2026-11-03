<?php
/**
 * Push-to-deploy settings (per-type secret rotation).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

use WebbaDeploy\Core\DeployUrl;
use WebbaDeploy\Core\PackageRepository;
use WebbaDeploy\Security\Capability;
use WebbaDeploy\Security\SiteSecret;

/**
 * Per-type deploy secrets and deploy URL documentation.
 */
class SettingsPage {

	/**
	 * Render settings page.
	 */
	public function render(): void {
		Capability::require_deploy_any();

		$secret_service = new SiteSecret();

		$this->render_notices();
		?>
		<div class="wrap webba-deploy">
			<h1><?php echo esc_html__( 'Push-to-deploy', 'webba-deploy' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Trigger deploys from CI or GitHub Actions using a secret. Send it in the X-Webba-Deploy-Secret header. Keep your secrets private.', 'webba-deploy' ); ?>
			</p>

			<?php foreach ( array( PackageRepository::TYPE_PLUGIN, PackageRepository::TYPE_THEME ) as $type ) : ?>
				<?php
				if ( ! Capability::can_deploy( $type ) ) {
					continue;
				}

				$secret     = $secret_service->get( $type );
				$new_secret = $this->take_new_secret( $type );
				$label      = PackageRepository::TYPE_THEME === $type
					? __( 'Theme deploys', 'webba-deploy' )
					: __( 'Plugin deploys', 'webba-deploy' );
				?>
				<h2><?php echo esc_html( $label ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current secret', 'webba-deploy' ); ?></th>
						<td>
							<code class="webba-deploy-secret-mask"><?php echo esc_html( $secret_service->mask( $secret ) ); ?></code>
							<?php if ( '' !== $new_secret ) : ?>
								<p>
									<label for="webba-deploy-full-secret-<?php echo esc_attr( $type ); ?>">
										<?php echo esc_html__( 'New secret (shown once — copy now):', 'webba-deploy' ); ?>
									</label><br />
									<input
										type="text"
										readonly
										class="large-text code webba-deploy-copy-input"
										id="webba-deploy-full-secret-<?php echo esc_attr( $type ); ?>"
										value="<?php echo esc_attr( $new_secret ); ?>"
									/>
									<button
										type="button"
										class="button webba-deploy-copy"
										data-copy-target="webba-deploy-full-secret-<?php echo esc_attr( $type ); ?>"
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
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Rotate this deploy secret? Existing CI jobs for this type must be updated.', 'webba-deploy' ) ); ?>');">
								<?php wp_nonce_field( 'webba_deploy_rotate_secret' ); ?>
								<input type="hidden" name="action" value="webba_deploy_action" />
								<input type="hidden" name="webba_deploy_action" value="rotate_secret" />
								<input type="hidden" name="package_type" value="<?php echo esc_attr( $type ); ?>" />
								<?php submit_button( __( 'Generate new secret', 'webba-deploy' ), 'delete', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				</table>

				<h3><?php echo esc_html__( 'Example request', 'webba-deploy' ); ?></h3>
				<pre class="webba-deploy-code"><code><?php echo esc_html( $this->curl_example( $type ) ); ?></code></pre>
			<?php endforeach; ?>

			<h2><?php echo esc_html__( 'Deploy endpoint', 'webba-deploy' ); ?></h2>
			<p><code><?php echo esc_html( DeployUrl::endpoint() ); ?></code></p>
			<p class="description">
				<?php echo esc_html__( 'The legacy secret query parameter is still accepted for backwards compatibility, but the header avoids leaking the secret into access logs.', 'webba-deploy' ); ?>
			</p>

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
	 * Read and consume a one-time rotated secret for the current user.
	 *
	 * @param string $type plugin|theme.
	 */
	private function take_new_secret( string $type ): string {
		$key    = 'webba_deploy_new_secret_' . $type . '_' . get_current_user_id();
		$secret = get_transient( $key );

		if ( ! is_string( $secret ) || '' === $secret ) {
			return '';
		}

		delete_transient( $key );

		return $secret;
	}

	/**
	 * Example curl command for documentation.
	 *
	 * @param string $type plugin|theme.
	 */
	private function curl_example( string $type ): string {
		$url = DeployUrl::for_package( $type, 'your-' . $type . '-slug' );

		return 'curl -fsS -X POST -H "X-Webba-Deploy-Secret: YOUR_SECRET" "' . $url . '"';
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

		if ( 'rotated' !== $notice && 'error' !== $notice ) {
			return;
		}

		if ( '' === $message ) {
			$message = 'rotated' === $notice
				? __( 'Deploy secret rotated. Copy the new secret shown above and update your CI configuration.', 'webba-deploy' )
				: __( 'Could not rotate the deploy secret.', 'webba-deploy' );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $notice ? 'notice-error' : 'notice-success',
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
