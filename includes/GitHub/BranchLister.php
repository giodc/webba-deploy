<?php
/**
 * List Git branches for a package repository.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

use WebbaDeploy\Core\PackageRepository;

/**
 * Fetches branch names from the GitHub API with transient caching.
 */
class BranchLister {

	/**
	 * How long to cache branch lists.
	 */
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Branches requested per page.
	 */
	private const PER_PAGE = 100;

	/**
	 * Maximum pages fetched (caps very large repositories).
	 */
	private const MAX_PAGES = 3;

	/**
	 * GitHub API client.
	 *
	 * @var ApiClient
	 */
	private ApiClient $api;

	/**
	 * Authentication resolver.
	 *
	 * @var GitHubAppAuth
	 */
	private GitHubAppAuth $auth;

	/**
	 * Constructor.
	 *
	 * @param ApiClient|null     $api  Optional API client.
	 * @param GitHubAppAuth|null $auth Optional authentication resolver.
	 */
	public function __construct( ?ApiClient $api = null, ?GitHubAppAuth $auth = null ) {
		$this->api  = $api ?? new ApiClient();
		$this->auth = $auth ?? new GitHubAppAuth();
	}

	/**
	 * Branch names for a package repository (cached).
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return array<int, string>|\WP_Error
	 */
	public function list_branches( array $package ) {
		$owner = (string) ( $package['owner'] ?? '' );
		$repo  = (string) ( $package['repo'] ?? '' );

		if ( '' === $owner || '' === $repo ) {
			return new \WP_Error(
				'webba_deploy_invalid_repository',
				__( 'Package repository is missing an owner or name.', 'webba-deploy' )
			);
		}

		$cache_key = 'webba_deploy_branches_' . md5( strtolower( $owner ) . '/' . strtolower( $repo ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = $this->auth->token_for_package( $package );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$branches = array();
		$page     = 1;

		do {
			$path = sprintf(
				'/repos/%s/%s/branches?per_page=%d&page=%d',
				rawurlencode( $owner ),
				rawurlencode( $repo ),
				self::PER_PAGE,
				$page
			);

			$response = $this->api->get( $path, is_string( $token ) ? $token : null );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$batch = is_array( $response ) ? $response : array();

			foreach ( $batch as $branch ) {
				if ( ! is_array( $branch ) || empty( $branch['name'] ) ) {
					continue;
				}

				$name = (string) $branch['name'];

				if ( PackageRepository::is_valid_branch( $name ) ) {
					$branches[] = $name;
				}
			}

			$count = count( $batch );
			++$page;
		} while ( self::PER_PAGE === $count && self::MAX_PAGES >= $page );

		$branches = array_values( array_unique( $branches ) );
		sort( $branches, SORT_NATURAL | SORT_FLAG_CASE );

		set_transient( $cache_key, $branches, self::CACHE_TTL );

		return $branches;
	}
}
