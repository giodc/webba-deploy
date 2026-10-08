<?php
/**
 * General plugin settings (logging, cache flush, uninstall).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

use WebbaDeploy\Security\Capability;

/**
 * Global toggles stored in wp_options.
 */
class GeneralSettingsPage {

	/**
	 * Render settings page.
	 */
	public function render(): void {
		Capability::require_manage_settings();

		$settings = get_option( 'webba_deploy_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$logging = ! isset( $settings['logging'] ) || ! empty( $settings['logging'] );
		$flush   = ! isset( $settings['flush_cache'] ) || ! empty( $settings['flush_cache'] );
		$delete  = (bool) get_option( 'webba_deploy_delete_on_uninstall', false );

		$this->render_notices();
		?>
		<div class="wrap webba-deploy">
			<h1><?php echo esc_html__( 'Settings', 'webba-deploy' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Control deploy logging, cache flushing, and cleanup on uninstall.', 'webba-deploy' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'webba_deploy_save_settings' ); ?>
				<input type="hidden" name="action" value="webba_deploy_action" />
				<input type="hidden" name="webba_deploy_action" value="save_settings" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Activity log', 'webba-deploy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="logging" value="1" <?php checked( $logging ); ?> />
								<?php echo esc_html__( 'Record deploy events on the dashboard (last 50 entries).', 'webba-deploy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Cache', 'webba-deploy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="flush_cache" value="1" <?php checked( $flush ); ?> />
								<?php echo esc_html__( 'Flush caches after each successful deploy.', 'webba-deploy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Uninstall', 'webba-deploy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $delete ); ?> />
								<?php echo esc_html__( 'Delete all Webba Deploy data when the plugin is uninstalled.', 'webba-deploy' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Packages, logs, the deploy secret, and GitHub App credentials are removed. This cannot be undone.', 'webba-deploy' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'webba-deploy' ) ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( $this->dashboard_url() ); ?>">&larr; <?php echo esc_html__( 'Back to dashboard', 'webba-deploy' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 */
	private function render_notices(): void {
		if ( empty( $_GET['notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( wp_unslash( (string) $_GET['notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'settings_saved' !== $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Settings saved.', 'webba-deploy' )
		);
	}

	/**
	 * Dashboard URL.
	 */
	private function dashboard_url(): string {
		return add_query_arg( 'page', Menu::SLUG_DASHBOARD, admin_url( 'admin.php' ) );
	}
}
