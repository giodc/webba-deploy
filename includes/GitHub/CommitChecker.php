<?php
/**
 * Compare deployed commit SHA with GitHub branch HEAD.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\GitHub;

use WebbaDeploy\Core\PackageRepository;

/**
 * Detects whether GitHub has commits newer than the last deploy.
 */
class CommitChecker {

	public const STATUS_NOT_INSTALLED     = 'not_installed';
	public const STATUS_UPDATE_AVAILABLE  = 'update_available';
	public const STATUS_UP_TO_DATE        = 'up_to_date';
	public const STATUS_UNKNOWN           = 'unknown';

	private const CACHE_TTL = 300;

	private ApiClient $api;

	private GitHubAppAuth $auth;

	public function __construct( ?ApiClient $api = null, ?GitHubAppAuth $auth = null ) {
		$this->api  = $api ?? new ApiClient();
		$this->auth = $auth ?? new GitHubAppAuth();
	}

	/**
	 * Latest commit SHA for a branch (cached).
	 *
	 * @param string      $owner  Repository owner.
	 * @param string      $repo   Repository name.
	 * @param string      $branch Branch name.
	 * @param string|null $token  Optional Bearer token.
	 * @return string|\WP_Error Full commit SHA.
	 */
	public function get_branch_head_sha( string $owner, string $repo, string $branch, ?string $token = null ) {
		$cache_key = 'webba_deploy_head_' . md5( strtolower( $owner ) . '/' . strtolower( $repo ) . '@' . $branch );

		$cached = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$path = sprintf(
			'/repos/%s/%s/commits/%s',
			rawurlencode( $owner ),
			rawurlencode( $repo ),
			rawurlencode( $branch )
		);

		$response = $this->api->get( $path, $token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$sha = isset( $response['sha'] ) ? (string) $response['sha'] : '';

		if ( '' === $sha || ! preg_match( '/^[a-f0-9]{40}$/i', $sha ) ) {
			return new \WP_Error(
				'webba_deploy_github_no_sha',
				__( 'Could not read the latest commit from GitHub.', 'webba-deploy' )
			);
		}

		set_transient( $cache_key, $sha, self::CACHE_TTL );

		return $sha;
	}

	/**
	 * Clear cached HEAD for a repository branch (after deploy).
	 *
	 * @param string $owner  Owner.
	 * @param string $repo   Repo.
	 * @param string $branch Branch.
	 */
	public function bust_cache( string $owner, string $repo, string $branch ): void {
		$cache_key = 'webba_deploy_head_' . md5( strtolower( $owner ) . '/' . strtolower( $repo ) . '@' . $branch );
		delete_transient( $cache_key );
	}

	/**
	 * Whether GitHub has a newer commit than the last successful deploy.
	 *
	 * @param array<string, mixed> $package Package record.
	 * @param bool                 $installed Whether the folder exists on disk.
	 * @return string One of CommitChecker::STATUS_* constants.
	 */
	public function get_update_status( array $package, bool $installed ): string {
		if ( ! $installed ) {
			return self::STATUS_NOT_INSTALLED;
		}

		$owner  = (string) ( $package['owner'] ?? '' );
		$repo   = (string) ( $package['repo'] ?? '' );
		$branch = (string) ( $package['branch'] ?? 'main' );

		$last      = isset( $package['last_deploy'] ) && is_array( $package['last_deploy'] ) ? $package['last_deploy'] : array();
		$local_sha = $this->get_stored_commit_sha( $last );

		$api_token = $this->resolve_api_token( $package );

		if ( is_wp_error( $api_token ) ) {
			return self::STATUS_UNKNOWN;
		}

		$remote_sha = $this->get_branch_head_sha( $owner, $repo, $branch, is_string( $api_token ) ? $api_token : null );

		if ( is_wp_error( $remote_sha ) ) {
			return self::STATUS_UNKNOWN;
		}

		if ( '' === $local_sha ) {
			$deploy_status = isset( $last['status'] ) ? (string) $last['status'] : '';
			$deploy_time   = isset( $last['time'] ) ? (string) $last['time'] : '';

			if ( 'success' === $deploy_status && '' !== $deploy_time ) {
				// Deploy succeeded but commit SHA was not stored — baseline from current HEAD once.
				$this->backfill_commit( $package, $remote_sha );

				return self::STATUS_UP_TO_DATE;
			}

			// Installed on disk without a tracked deploy commit.
			return self::STATUS_UNKNOWN;
		}

		if ( hash_equals( strtolower( $local_sha ), strtolower( $remote_sha ) ) ) {
			return self::STATUS_UP_TO_DATE;
		}

		return self::STATUS_UPDATE_AVAILABLE;
	}

	/**
	 * Human-readable label for update status.
	 *
	 * @param string $status Status constant.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case self::STATUS_UP_TO_DATE:
				return __( 'Up to date', 'webba-deploy' );
			case self::STATUS_UPDATE_AVAILABLE:
				return __( 'Update available', 'webba-deploy' );
			case self::STATUS_NOT_INSTALLED:
				return __( 'Not installed', 'webba-deploy' );
			default:
				return __( 'Unknown', 'webba-deploy' );
		}
	}

	/**
	 * Record HEAD SHA after a successful deploy.
	 *
	 * @param string $owner  Owner.
	 * @param string $repo   Repo.
	 * @param string $branch Branch.
	 * @return string|\WP_Error SHA or error.
	 */
	public function resolve_deployed_sha( string $owner, string $repo, string $branch ) {
		$this->bust_cache( $owner, $repo, $branch );

		return $this->get_branch_head_sha( $owner, $repo, $branch, null );
	}

	/**
	 * Record HEAD SHA after deploy (supports private repos).
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return string|\WP_Error
	 */
	public function resolve_deployed_sha_for_package( array $package ) {
		$owner  = (string) ( $package['owner'] ?? '' );
		$repo   = (string) ( $package['repo'] ?? '' );
		$branch = (string) ( $package['branch'] ?? 'main' );

		$this->bust_cache( $owner, $repo, $branch );

		$api_token = $this->resolve_api_token( $package );

		if ( is_wp_error( $api_token ) ) {
			return $api_token;
		}

		return $this->get_branch_head_sha( $owner, $repo, $branch, is_string( $api_token ) ? $api_token : null );
	}

	/**
	 * Read last deployed commit SHA from package metadata.
	 *
	 * @param array<string, mixed> $last_deploy last_deploy block.
	 */
	private function get_stored_commit_sha( array $last_deploy ): string {
		if ( ! isset( $last_deploy['commit'] ) || ! is_string( $last_deploy['commit'] ) ) {
			return '';
		}

		$sha = trim( $last_deploy['commit'] );

		return preg_match( '/^[a-f0-9]{40}$/i', $sha ) ? $sha : '';
	}

	/**
	 * Persist missing commit SHA after a successful deploy (one-time baseline).
	 *
	 * @param array<string, mixed> $package   Package record.
	 * @param string               $remote_sha Remote HEAD SHA.
	 */
	private function backfill_commit( array $package, string $remote_sha ): void {
		$type = (string) ( $package['type'] ?? '' );
		$id   = (string) ( $package['id'] ?? '' );

		if ( '' === $type || '' === $id ) {
			return;
		}

		$last = isset( $package['last_deploy'] ) && is_array( $package['last_deploy'] ) ? $package['last_deploy'] : array();

		if ( '' !== $this->get_stored_commit_sha( $last ) ) {
			return;
		}

		$last['commit'] = $remote_sha;

		( new PackageRepository() )->update(
			$type,
			$id,
			array( 'last_deploy' => $last )
		);
	}

	/**
	 * Installation token for private packages.
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return string|null|\WP_Error
	 */
	private function resolve_api_token( array $package ) {
		return $this->auth->token_for_package( $package );
	}

}
