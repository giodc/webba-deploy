<?php
/**
 * Add plugin/theme wizard (public and private GitHub repos).
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Admin;

use WebbaDeploy\Core\PackageRepository;
use WebbaDeploy\GitHub\AppConfig;
use WebbaDeploy\GitHub\ConnectionStore;
use WebbaDeploy\GitHub\RepositoryLister;
use WebbaDeploy\Security\Capability;

/**
 * Form to link a public URL or a private repo from the GitHub App installation.
 */
class AddPackagePage {

	/**
	 * Package type: plugin or theme.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Constructor.
	 *
	 * @param string $type Package type: plugin|theme.
	 */
	public function __construct( string $type ) {
		$this->type = $type;
	}

	/**
	 * Render wizard form.
	 */
	public function render(): void {
		Capability::require_deploy( $this->type );

		$config     = new AppConfig();
		$connection = new ConnectionStore();
		$connected  = $connection->is_connected();
		$configured = $config->is_configured();
		$creds      = $config->get_credential_status();
		$private_ok = $configured && $connected;

		$private_repos = array();
		$repo_error    = '';

		if ( $private_ok ) {
			$list = ( new RepositoryLister() )->list_repositories();

			if ( is_wp_error( $list ) ) {
				$repo_error = $list->get_error_message();
			} else {
				$private_repos = $list;
			}
		}

		$label = PackageRepository::TYPE_THEME === $this->type
			? __( 'Add Theme', 'webba-deploy' )
			: __( 'Add Plugin', 'webba-deploy' );

		$this->render_notices();
		?>
		<div class="wrap webba-deploy">
			<h1><?php echo esc_html( $label ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Link a GitHub repository. Public repos use a URL; private repos use your connected GitHub App and only repositories you granted during installation.', 'webba-deploy' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="webba-deploy-form" id="webba-deploy-add-form">
				<?php wp_nonce_field( 'webba_deploy_add_' . $this->type ); ?>
				<input type="hidden" name="action" value="webba_deploy_action" />
				<input type="hidden" name="webba_deploy_action" value="add_package" />
				<input type="hidden" name="package_type" value="<?php echo esc_attr( $this->type ); ?>" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Repository access', 'webba-deploy' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="visibility" value="public" class="webba-deploy-visibility" checked />
									<?php echo esc_html__( 'Public', 'webba-deploy' ); ?>
								</label>
								<br />
								<label>
									<input
										type="radio"
										name="visibility"
										value="private"
										class="webba-deploy-visibility"
										<?php disabled( ! $private_ok ); ?>
									/>
									<?php echo esc_html__( 'Private (GitHub App)', 'webba-deploy' ); ?>
								</label>
							</fieldset>
							<?php $this->render_github_setup_status( $configured, $connected, $creds ); ?>
						</td>
					</tr>
					<tr class="webba-deploy-field-public">
						<th scope="row">
							<label for="repo_url"><?php echo esc_html__( 'Repository URL', 'webba-deploy' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								name="repo_url"
								id="repo_url"
								class="regular-text"
								placeholder="https://github.com/owner/repo"
								value=""
							/>
							<p class="description">
								<?php echo esc_html__( 'GitHub repository URL. Public repos work directly; a private URL requires the GitHub App. The repository root must be the plugin or theme root.', 'webba-deploy' ); ?>
							</p>
						</td>
					</tr>
					<tr class="webba-deploy-field-private" hidden>
						<th scope="row">
							<label for="repo_full"><?php echo esc_html__( 'Repository', 'webba-deploy' ); ?></label>
						</th>
						<td>
							<?php if ( '' !== $repo_error ) : ?>
								<div class="notice notice-error inline"><p><?php echo esc_html( $repo_error ); ?></p></div>
							<?php elseif ( ! $connected ) : ?>
								<p class="description">
									<a href="<?php echo esc_url( $this->github_settings_url() ); ?>">
										<?php echo esc_html__( 'Connect the GitHub App', 'webba-deploy' ); ?>
									</a>
									<?php echo esc_html__( 'to list private repositories.', 'webba-deploy' ); ?>
								</p>
							<?php elseif ( empty( $private_repos ) ) : ?>
								<p class="description">
									<?php echo esc_html__( 'No repositories are available for this installation. On GitHub, open your App installation and grant access to the repositories you need (recommended: Only select repositories).', 'webba-deploy' ); ?>
								</p>
							<?php else : ?>
								<select name="repo_full" id="repo_full" class="regular-text">
									<option value=""><?php echo esc_html__( '— Select repository —', 'webba-deploy' ); ?></option>
									<?php foreach ( $private_repos as $repo ) : ?>
										<option
											value="<?php echo esc_attr( $repo['full_name'] ); ?>"
											data-default-branch="<?php echo esc_attr( $repo['default_branch'] ); ?>"
											data-slug="<?php echo esc_attr( sanitize_title( $repo['name'] ) ); ?>"
										>
											<?php echo esc_html( $repo['full_name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php echo esc_html__( 'Only repositories you allowed when installing the GitHub App are listed here.', 'webba-deploy' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="branch"><?php echo esc_html__( 'Branch', 'webba-deploy' ); ?></label>
						</th>
						<td>
							<input type="text" name="branch" id="branch" class="regular-text" value="main" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="slug"><?php echo esc_html__( 'Folder slug', 'webba-deploy' ); ?></label>
						</th>
						<td>
							<input type="text" name="slug" id="slug" class="regular-text" pattern="[a-z0-9\-]+" />
							<p class="description">
								<?php echo esc_html__( 'WordPress folder name under wp-content/plugins/ or themes/ (auto-filled from repo name when left empty).', 'webba-deploy' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Install', 'webba-deploy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="install_now" value="1" checked />
								<?php echo esc_html__( 'Install after saving', 'webba-deploy' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save package', 'webba-deploy' ) ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( $this->dashboard_url() ); ?>">&larr; <?php echo esc_html__( 'Back to dashboard', 'webba-deploy' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * GitHub App setup checklist under repository access radios.
	 *
	 * @param bool                $configured App credentials loaded.
	 * @param bool                $connected  Installation stored.
	 * @param array<string, bool> $creds      app_id / private_key flags.
	 */
	private function render_github_setup_status( bool $configured, bool $connected, array $creds ): void {
		$github_url = $this->github_settings_url();
		$source     = isset( $creds['source'] ) ? (string) $creds['source'] : 'none';
		?>
		<ul class="webba-deploy-setup-status description">
			<li class="<?php echo $configured ? 'is-ok' : 'is-missing'; ?>">
				<?php if ( $configured ) : ?>
					<?php
					if ( 'wp-config' === $source ) {
						echo esc_html__( 'GitHub App configured (wp-config.php)', 'webba-deploy' );
					} else {
						echo esc_html__( 'GitHub App configured', 'webba-deploy' );
					}
					?>
				<?php else : ?>
					<a href="<?php echo esc_url( $github_url ); ?>">
						<?php echo esc_html__( 'Set up with GitHub', 'webba-deploy' ); ?>
					</a>
				<?php endif; ?>
			</li>
			<li class="<?php echo $connected ? 'is-ok' : 'is-missing'; ?>">
				<?php if ( $connected ) : ?>
					<?php echo esc_html__( 'Repositories connected', 'webba-deploy' ); ?>
				<?php else : ?>
					<a href="<?php echo esc_url( $github_url ); ?>">
						<?php echo esc_html__( 'Continue GitHub App installation & choose repositories', 'webba-deploy' ); ?>
					</a>
				<?php endif; ?>
			</li>
		</ul>
		<?php
	}

	/**
	 * Render notices.
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

		if ( 'error' !== $notice || '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Dashboard URL.
	 */
	private function dashboard_url(): string {
		return add_query_arg( 'page', Menu::SLUG_DASHBOARD, admin_url( 'admin.php' ) );
	}

	/**
	 * GitHub settings URL.
	 */
	private function github_settings_url(): string {
		return Menu::github_settings_url();
	}
}
