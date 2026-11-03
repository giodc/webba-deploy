<?php
/**
 * Main dashboard listing packages.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

use WebbaDeploy\Core\DeployUrl;
use WebbaDeploy\Core\PackageRepository;
use WebbaDeploy\GitHub\BranchLister;
use WebbaDeploy\GitHub\CommitChecker;
use WebbaDeploy\Logging\Logger;
use WebbaDeploy\Security\Capability;
use WebbaDeploy\Security\SiteSecret;

/**
 * Renders package list with deploy/delete actions.
 */
class DashboardPage {

	/**
	 * Render dashboard.
	 */
	public function render(): void {
		Capability::require_manage_settings();

		$plugins       = ( new PackageRepository() )->all( PackageRepository::TYPE_PLUGIN );
		$themes        = ( new PackageRepository() )->all( PackageRepository::TYPE_THEME );
		$logs          = ( new Logger() )->get_entries( 20 );
		$branch_lister = new BranchLister();

		$this->render_notices();
		?>
		<div class="wrap webba-deploy">
			<h1><?php echo esc_html__( 'Dashboard', 'webba-deploy' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Install and update WordPress plugins and themes from public GitHub repositories.', 'webba-deploy' ); ?>
			</p>

			<div class="webba-deploy-actions">
				<a class="button button-primary" href="<?php echo esc_url( $this->page_url( Menu::SLUG_ADD_PLUGIN ) ); ?>">
					<?php echo esc_html__( 'Add Plugin', 'webba-deploy' ); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url( $this->page_url( Menu::SLUG_ADD_THEME ) ); ?>">
					<?php echo esc_html__( 'Add Theme', 'webba-deploy' ); ?>
				</a>
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="webba-deploy-actions__end"
				>
					<?php wp_nonce_field( 'webba_deploy_check_updates' ); ?>
					<input type="hidden" name="action" value="webba_deploy_action" />
					<input type="hidden" name="webba_deploy_action" value="check_updates" />
					<?php submit_button( __( 'Check for Updates', 'webba-deploy' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( Capability::can_deploy_any() ) : ?>
				<p>
					<a href="<?php echo esc_url( $this->page_url( Menu::SLUG_SETTINGS ) ); ?>">
						<?php echo esc_html__( 'Manage push-to-deploy secrets', 'webba-deploy' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Plugins', 'webba-deploy' ); ?></h2>
			<?php $this->render_package_grid( PackageRepository::TYPE_PLUGIN, $plugins, $branch_lister ); ?>

			<h2><?php echo esc_html__( 'Themes', 'webba-deploy' ); ?></h2>
			<?php $this->render_package_grid( PackageRepository::TYPE_THEME, $themes, $branch_lister ); ?>

			<h2><?php echo esc_html__( 'Recent activity', 'webba-deploy' ); ?></h2>
			<?php $this->render_logs( $logs ); ?>
		</div>
		<?php
	}

	/**
	 * Render admin notices from query args.
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

		$class = 'error' === $notice ? 'notice-error' : 'notice-success';

		if ( '' === $message ) {
			switch ( $notice ) {
				case 'deployed':
					$message = __( 'Deploy completed successfully.', 'webba-deploy' );
					break;
				case 'installed':
					$message = __( 'Installed successfully.', 'webba-deploy' );
					break;
				case 'updated':
					$message = __( 'Updated successfully.', 'webba-deploy' );
					break;
				case 'created':
					$message = __( 'Package saved.', 'webba-deploy' );
					break;
				case 'deleted':
					$message = __( 'Package removed.', 'webba-deploy' );
					break;
				case 'branch_updated':
					$message = __( 'Branch updated. Deploy the package to switch this site to the new branch.', 'webba-deploy' );
					break;
				case 'updates_checked':
					$message = __( 'Update check completed. GitHub and WordPress update lists were refreshed.', 'webba-deploy' );
					break;
				default:
					return;
			}
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Render packages as a responsive card grid.
	 *
	 * @param string                           $type     Package type.
	 * @param array<int, array<string, mixed>> $packages Packages.
	 * @param BranchLister                     $branch_lister Branch lister.
	 */
	private function render_package_grid( string $type, array $packages, BranchLister $branch_lister ): void {
		if ( empty( $packages ) ) {
			echo '<p>' . esc_html__( 'No packages yet.', 'webba-deploy' ) . '</p>';
			return;
		}
		?>
		<div class="webba-deploy-grid" role="list">
			<?php foreach ( $packages as $package ) : ?>
				<?php $this->render_package_card( $type, $package, $branch_lister ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Single package card.
	 *
	 * @param string               $type     Package type.
	 * @param array<string, mixed> $package  Package data.
	 * @param BranchLister         $branch_lister Branch lister.
	 */
	private function render_package_card( string $type, array $package, BranchLister $branch_lister ): void {
		$id         = esc_attr( (string) ( $package['id'] ?? '' ) );
		$slug       = esc_html( (string) ( $package['slug'] ?? '' ) );
		$owner      = esc_html( (string) ( $package['owner'] ?? '' ) );
		$repo       = esc_html( (string) ( $package['repo'] ?? '' ) );
		$branch_raw = (string) ( $package['branch'] ?? 'main' );
		$branch     = esc_html( $branch_raw );
		$last       = isset( $package['last_deploy'] ) && is_array( $package['last_deploy'] ) ? $package['last_deploy'] : array();
		$status     = isset( $last['status'] ) ? (string) $last['status'] : '';
		$time       = isset( $last['time'] ) ? (string) $last['time'] : '';
		$msg        = isset( $last['message'] ) ? (string) $last['message'] : '';

		$can_deploy     = Capability::can_deploy( $type );
		$branch_options = array();

		if ( $can_deploy ) {
			$fetched = $branch_lister->list_branches( $package );

			if ( ! is_wp_error( $fetched ) && ! empty( $fetched ) ) {
				$branch_options = $fetched;

				if ( ! in_array( $branch_raw, $branch_options, true ) ) {
					$branch_options[] = $branch_raw;
					sort( $branch_options, SORT_NATURAL | SORT_FLAG_CASE );
				}
			}
		}

		$deploy_url      = DeployUrl::for_package( $type, (string) ( $package['slug'] ?? '' ) );
		$input_id        = 'webba-deploy-url-' . (string) ( $package['id'] ?? '' );
		$secret_input_id = 'webba-deploy-secret-' . (string) ( $package['id'] ?? '' );
		$secret          = $can_deploy ? ( new SiteSecret() )->get( $type ) : '';
		$installed       = PackageRepository::is_installed_on_disk( $type, (string) ( $package['slug'] ?? '' ) );
		$update_status = ( new CommitChecker() )->get_update_status( $package, $installed );
		$local_commit  = isset( $last['commit'] ) && is_string( $last['commit'] ) ? substr( (string) $last['commit'], 0, 7 ) : '';
		$visibility    = (string) ( $package['visibility'] ?? 'public' );
		$access_label  = 'private' === $visibility
			? __( 'Private', 'webba-deploy' )
			: __( 'Public', 'webba-deploy' );
		$repo_url      = sprintf( 'https://github.com/%s/%s', $package['owner'] ?? '', $package['repo'] ?? '' );
		?>
		<article class="webba-deploy-card" role="listitem">
			<header class="webba-deploy-card__header">
				<h3 class="webba-deploy-card__slug"><code><?php echo $slug; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></code></h3>
				<span class="webba-deploy-badge webba-deploy-badge--<?php echo esc_attr( $update_status ); ?>">
					<?php echo esc_html( CommitChecker::status_label( $update_status ) ); ?>
				</span>
			</header>

			<dl class="webba-deploy-card__meta">
				<dt><?php echo esc_html__( 'Repository', 'webba-deploy' ); ?></dt>
				<dd>
					<a href="<?php echo esc_url( $repo_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $owner . '/' . $repo ); ?>
					</a>
				</dd>
				<dt><?php echo esc_html__( 'Access', 'webba-deploy' ); ?></dt>
				<dd><?php echo esc_html( $access_label ); ?></dd>
				<dt>
					<?php if ( $can_deploy ) : ?>
						<label for="webba-deploy-branch-<?php echo esc_attr( (string) ( $package['id'] ?? '' ) ); ?>">
							<?php echo esc_html__( 'Branch', 'webba-deploy' ); ?>
						</label>
					<?php else : ?>
						<?php echo esc_html__( 'Branch', 'webba-deploy' ); ?>
					<?php endif; ?>
				</dt>
				<dd>
					<?php if ( $can_deploy ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="webba-deploy-branch-form">
							<?php wp_nonce_field( 'webba_deploy_update_branch' ); ?>
							<input type="hidden" name="action" value="webba_deploy_action" />
							<input type="hidden" name="webba_deploy_action" value="update_branch" />
							<input type="hidden" name="package_type" value="<?php echo esc_attr( $type ); ?>" />
							<input type="hidden" name="package_id" value="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
							<?php if ( ! empty( $branch_options ) ) : ?>
								<select
									name="branch"
									id="webba-deploy-branch-<?php echo esc_attr( (string) ( $package['id'] ?? '' ) ); ?>"
									class="webba-deploy-branch-input"
								>
									<?php foreach ( $branch_options as $branch_option ) : ?>
										<option value="<?php echo esc_attr( $branch_option ); ?>" <?php selected( $branch_option, $branch_raw ); ?>>
											<?php echo esc_html( $branch_option ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input
									type="text"
									name="branch"
									id="webba-deploy-branch-<?php echo esc_attr( (string) ( $package['id'] ?? '' ) ); ?>"
									class="webba-deploy-branch-input"
									value="<?php echo esc_attr( $branch_raw ); ?>"
								/>
							<?php endif; ?>
							<button type="submit" class="button button-small">
								<?php echo esc_html__( 'Change', 'webba-deploy' ); ?>
							</button>
						</form>
					<?php else : ?>
						<?php echo $branch; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</dd>
				<?php if ( $local_commit ) : ?>
					<dt><?php echo esc_html__( 'Commit', 'webba-deploy' ); ?></dt>
					<dd><code><?php echo esc_html( $local_commit ); ?></code></dd>
				<?php endif; ?>
				<dt><?php echo esc_html__( 'Last deploy', 'webba-deploy' ); ?></dt>
				<dd>
					<?php if ( $time ) : ?>
						<span class="webba-deploy-status webba-deploy-status--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $time ); ?>
							<?php if ( $msg ) : ?>
								<span class="webba-deploy-card__deploy-msg"><?php echo esc_html( $msg ); ?></span>
							<?php endif; ?>
						</span>
					<?php else : ?>
						<em><?php echo esc_html__( 'Never', 'webba-deploy' ); ?></em>
					<?php endif; ?>
				</dd>
			</dl>

			<?php if ( $can_deploy ) : ?>
				<div class="webba-deploy-card__push">
					<label class="webba-deploy-card__label" for="<?php echo esc_attr( $input_id ); ?>">
						<?php echo esc_html__( 'Deploy URL', 'webba-deploy' ); ?>
					</label>
					<div class="webba-deploy-push-url">
						<input
							type="text"
							readonly
							class="large-text code webba-deploy-copy-input"
							id="<?php echo esc_attr( $input_id ); ?>"
							value="<?php echo esc_url( $deploy_url ); ?>"
						/>
						<button
							type="button"
							class="button button-small webba-deploy-copy"
							data-copy-target="<?php echo esc_attr( $input_id ); ?>"
							data-copied-label="<?php echo esc_attr__( 'Copied!', 'webba-deploy' ); ?>"
						>
							<?php echo esc_html__( 'Copy', 'webba-deploy' ); ?>
						</button>
					</div>

					<label class="webba-deploy-card__label webba-deploy-card__label--secret" for="<?php echo esc_attr( $secret_input_id ); ?>">
						<?php echo esc_html__( 'Secret', 'webba-deploy' ); ?>
					</label>
					<div class="webba-deploy-push-url">
						<input
							type="text"
							readonly
							class="large-text code webba-deploy-copy-input"
							id="<?php echo esc_attr( $secret_input_id ); ?>"
							value="<?php echo esc_attr( $secret ); ?>"
						/>
						<button
							type="button"
							class="button button-small webba-deploy-copy"
							data-copy-target="<?php echo esc_attr( $secret_input_id ); ?>"
							data-copied-label="<?php echo esc_attr__( 'Copied!', 'webba-deploy' ); ?>"
						>
							<?php echo esc_html__( 'Copy', 'webba-deploy' ); ?>
						</button>
					</div>
					<p class="description">
						<?php echo esc_html__( 'Send the secret in the X-Webba-Deploy-Secret header (recommended). The legacy secret query parameter is still accepted.', 'webba-deploy' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<footer class="webba-deploy-card__actions">
				<?php if ( $can_deploy ) : ?>
					<?php if ( CommitChecker::STATUS_UP_TO_DATE === $update_status ) : ?>
						<button type="button" class="button webba-deploy-btn-up-to-date" disabled>
							<?php echo esc_html__( 'Up to date', 'webba-deploy' ); ?>
						</button>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="webba-deploy-inline-form">
							<?php wp_nonce_field( 'webba_deploy_deploy' ); ?>
							<input type="hidden" name="action" value="webba_deploy_action" />
							<input type="hidden" name="webba_deploy_action" value="deploy" />
							<input type="hidden" name="package_type" value="<?php echo esc_attr( $type ); ?>" />
							<input type="hidden" name="package_id" value="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
							<button type="submit" class="button button-primary">
								<?php
								if ( $installed ) {
									echo esc_html__( 'Update now', 'webba-deploy' );
								} else {
									echo esc_html__( 'Install', 'webba-deploy' );
								}
								?>
							</button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="webba-deploy-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this package from Webba Deploy?', 'webba-deploy' ) ); ?>');">
					<?php wp_nonce_field( 'webba_deploy_delete' ); ?>
					<input type="hidden" name="action" value="webba_deploy_action" />
					<input type="hidden" name="webba_deploy_action" value="delete" />
					<input type="hidden" name="package_type" value="<?php echo esc_attr( $type ); ?>" />
					<input type="hidden" name="package_id" value="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
					<button type="submit" class="button button-link-delete">
						<?php echo esc_html__( 'Delete', 'webba-deploy' ); ?>
					</button>
				</form>
			</footer>
		</article>
		<?php
	}

	/**
	 * Render log entries.
	 *
	 * @param array<int, array<string, mixed>> $logs Log entries.
	 */
	private function render_logs( array $logs ): void {
		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No log entries yet.', 'webba-deploy' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Time', 'webba-deploy' ); ?></th>
					<th><?php echo esc_html__( 'Level', 'webba-deploy' ); ?></th>
					<th><?php echo esc_html__( 'Message', 'webba-deploy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $logs ) as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['level'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Admin page URL helper.
	 *
	 * @param string $slug Page slug.
	 */
	private function page_url( string $slug ): string {
		return add_query_arg( 'page', $slug, admin_url( 'admin.php' ) );
	}
}
