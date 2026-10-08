<?php
/**
 * Single source of truth for private-repository authentication and visibility.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * Resolves installation tokens and repository visibility for a package.
 *
 * Every code path that needs private-repo credentials (manual deploy, REST
 * push-to-deploy, native WordPress updates, commit comparison) goes through
 * this class so the preconditions and error codes stay consistent.
 */
class GitHubAppAuth {

	private AppConfig $config;
	private ConnectionStore $connection;
	private RepositoryLister $lister;
	private InstallationTokenService $tokens;
	private ApiClient $api;

	/**
	 * Constructor.
	 *
	 * @param AppConfig|null               $config     App credentials.
	 * @param ConnectionStore|null         $connection Installation store.
	 * @param RepositoryLister|null        $lister     Repository access checker.
	 * @param InstallationTokenService|null $tokens    Installation token service.
	 * @param ApiClient|null               $api        GitHub API client.
	 */
	public function __construct(
		?AppConfig $config = null,
		?ConnectionStore $connection = null,
		?RepositoryLister $lister = null,
		?InstallationTokenService $tokens = null,
		?ApiClient $api = null
	) {
		$this->api        = $api ?? new ApiClient();
		$this->config     = $config ?? new AppConfig();
		$this->connection = $connection ?? new ConnectionStore();
		$this->tokens     = $tokens ?? new InstallationTokenService( $this->config );
		$this->lister     = $lister ?? new RepositoryLister( $this->tokens, $this->api, $this->connection );
	}

	/**
	 * Whether a package needs an installation token to be downloaded.
	 *
	 * @param array<string, mixed> $package Package record.
	 */
	public function requires_auth( array $package ): bool {
		return 'private' === (string) ( $package['visibility'] ?? 'public' );
	}

	/**
	 * Bearer token for a package.
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return null|string|\WP_Error Null for public packages, token string for
	 *                               private packages, or an error.
	 */
	public function token_for_package( array $package ) {
		if ( ! $this->requires_auth( $package ) ) {
			return null;
		}

		return $this->token_for_repository(
			(string) ( $package['owner'] ?? '' ),
			(string) ( $package['repo'] ?? '' )
		);
	}

	/**
	 * Installation token for a repository, verifying App access first.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return string|\WP_Error
	 */
	public function token_for_repository( string $owner, string $repo ) {
		if ( ! $this->config->is_configured() ) {
			return new \WP_Error(
				'webba_deploy_private_requires_app',
				__( 'Set up the GitHub App (Webba Deploy → GitHub) before deploying private repositories.', 'webba-deploy' )
			);
		}

		if ( ! $this->connection->is_connected() ) {
			return new \WP_Error(
				'webba_deploy_private_requires_app',
				__( 'Connect the GitHub App before deploying private repositories.', 'webba-deploy' )
			);
		}

		$installation_id = $this->connection->get_installation_id();

		if ( null === $installation_id ) {
			return new \WP_Error(
				'webba_deploy_private_requires_app',
				__( 'GitHub App installation ID is missing. Reconnect the GitHub App.', 'webba-deploy' )
			);
		}

		$access = $this->lister->repository_access( $owner, $repo );

		if ( is_wp_error( $access ) ) {
			return new \WP_Error(
				'webba_deploy_repo_access_check_failed',
				__( 'Could not verify repository access with GitHub. Please try again shortly.', 'webba-deploy' )
			);
		}

		if ( ! $access ) {
			return new \WP_Error(
				'webba_deploy_repo_not_granted',
				__( 'This repository is not available on your GitHub App installation. Grant access on GitHub or pick another repository.', 'webba-deploy' )
			);
		}

		return $this->tokens->get_token( $installation_id );
	}

	/**
	 * Determine a repository's real visibility from GitHub.
	 *
	 * Uses the installation token when the App is connected, otherwise an
	 * anonymous lookup. A 404 is authoritative (missing, or private and not
	 * accessible); any other failure is treated as indeterminate so callers can
	 * fall back to the declared value instead of blocking the request.
	 *
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @param string $declared Caller's declared visibility fallback.
	 * @return string|\WP_Error 'public', 'private', or an error.
	 */
	public function resolve_visibility( string $owner, string $repo, string $declared = 'public' ) {
		$response = $this->lookup_repository( $owner, $repo );

		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

			if ( 404 === $status ) {
				return new \WP_Error(
					'webba_deploy_repo_not_found',
					__( 'Repository not found, or it is private and not accessible to the GitHub App. Check the URL, and grant access on GitHub for private repositories.', 'webba-deploy' )
				);
			}

			return in_array( $declared, array( 'public', 'private' ), true ) ? $declared : 'public';
		}

		return ! empty( $response['private'] ) ? 'private' : 'public';
	}

	/**
	 * Fetch repository metadata, authenticated when possible.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function lookup_repository( string $owner, string $repo ) {
		$path = '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo );

		return $this->api->get( $path, $this->installation_token() );
	}

	/**
	 * An installation token when the App is configured and connected.
	 */
	private function installation_token(): ?string {
		if ( ! $this->config->is_configured() || ! $this->connection->is_connected() ) {
			return null;
		}

		$installation_id = $this->connection->get_installation_id();

		if ( null === $installation_id ) {
			return null;
		}

		$token = $this->tokens->get_token( $installation_id );

		return is_string( $token ) && '' !== $token ? $token : null;
	}
}
