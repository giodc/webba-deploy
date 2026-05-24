<?php
/**
 * List repositories available to the GitHub App installation.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

/**
 * Fetches repos the user granted during App installation.
 */
class RepositoryLister {

	private InstallationTokenService $tokens;
	private ApiClient $api;
	private ConnectionStore $connection;

	public function __construct(
		?InstallationTokenService $tokens = null,
		?ApiClient $api = null,
		?ConnectionStore $connection = null
	) {
		$this->tokens     = $tokens ?? new InstallationTokenService();
		$this->api        = $api ?? new ApiClient();
		$this->connection = $connection ?? new ConnectionStore();
	}

	/**
	 * Repositories for the connected installation.
	 *
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public function list_repositories() {
		$installation_id = $this->connection->get_installation_id();

		if ( null === $installation_id ) {
			return new \WP_Error(
				'webba_deploy_github_not_connected',
				__( 'Connect the GitHub App before selecting private repositories.', 'webba-deploy' )
			);
		}

		$cache_key = 'webba_deploy_repos_' . $installation_id;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = $this->tokens->get_token( $installation_id );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$repos   = array();
		$page    = 1;
		$per_page = 100;

		do {
			$response = $this->api->get(
				'/installation/repositories?per_page=' . $per_page . '&page=' . $page,
				$token
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch = isset( $response['repositories'] ) && is_array( $response['repositories'] )
				? $response['repositories']
				: array();

			foreach ( $batch as $repo ) {
				if ( ! is_array( $repo ) || empty( $repo['full_name'] ) ) {
					continue;
				}

				$full_name = (string) $repo['full_name'];
				$parts     = explode( '/', $full_name, 2 );

				if ( count( $parts ) < 2 ) {
					continue;
				}

				$repos[] = array(
					'full_name'       => $full_name,
					'owner'           => $parts[0],
					'name'            => $parts[1],
					'default_branch'  => isset( $repo['default_branch'] ) ? (string) $repo['default_branch'] : 'main',
					'private'         => ! empty( $repo['private'] ) ? '1' : '0',
				);
			}

			++$page;
		} while ( count( $batch ) === $per_page && $page <= 10 );

		usort(
			$repos,
			static function ( array $a, array $b ): int {
				return strcmp( $a['full_name'], $b['full_name'] );
			}
		);

		set_transient( $cache_key, $repos, 5 * MINUTE_IN_SECONDS );

		return $repos;
	}

	/**
	 * Whether owner/repo is in the installation.
	 *
	 * @param string $owner Owner.
	 * @param string $repo  Repo name.
	 */
	public function installation_has_repository( string $owner, string $repo ): bool {
		$list = $this->list_repositories();

		if ( is_wp_error( $list ) ) {
			return false;
		}

		$needle = strtolower( $owner ) . '/' . strtolower( $repo );

		foreach ( $list as $item ) {
			if ( strtolower( $item['full_name'] ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Default branch for a repository in the installation.
	 *
	 * @param string $owner Owner.
	 * @param string $repo  Repo.
	 */
	public function get_default_branch( string $owner, string $repo ): string {
		$list = $this->list_repositories();

		if ( is_wp_error( $list ) ) {
			return 'main';
		}

		$needle = strtolower( $owner ) . '/' . strtolower( $repo );

		foreach ( $list as $item ) {
			if ( strtolower( $item['full_name'] ) === $needle ) {
				return '' !== $item['default_branch'] ? $item['default_branch'] : 'main';
			}
		}

		return 'main';
	}
}
