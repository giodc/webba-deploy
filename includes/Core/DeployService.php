<?php
/**
 * Orchestrates deploy pipeline for a package.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Core;

use WebbaDeploy\GitHub\CommitChecker;
use WebbaDeploy\GitHub\ConnectionStore;
use WebbaDeploy\GitHub\InstallationTokenService;
use WebbaDeploy\GitHub\RepositoryLister;
use WebbaDeploy\Logging\Logger;
use WebbaDeploy\Security\Capability;

/**
 * Single code path: download zipball → upgrader → log.
 */
class DeployService {

	/**
	 * Package storage.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * GitHub archive downloader.
	 *
	 * @var ArchiveDownloader
	 */
	private ArchiveDownloader $downloader;

	/**
	 * WordPress upgrader factory.
	 *
	 * @var UpgraderFactory
	 */
	private UpgraderFactory $upgrader;

	/**
	 * Deploy logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param PackageRepository|null $packages   Package repository.
	 * @param ArchiveDownloader|null $downloader Archive downloader.
	 * @param UpgraderFactory|null   $upgrader   Upgrader factory.
	 * @param Logger|null            $logger     Logger instance.
	 */
	public function __construct(
		?PackageRepository $packages = null,
		?ArchiveDownloader $downloader = null,
		?UpgraderFactory $upgrader = null,
		?Logger $logger = null
	) {
		$this->packages   = $packages ?? new PackageRepository();
		$this->downloader = $downloader ?? new ArchiveDownloader();
		$this->upgrader   = $upgrader ?? new UpgraderFactory();
		$this->logger     = $logger ?? new Logger();
	}

	/**
	 * Deploy a package by ID.
	 *
	 * @param string $type                 Package type.
	 * @param string $id                   Package ID.
	 * @param bool   $require_capability   Whether to enforce admin capabilities.
	 * @return true|\WP_Error
	 */
	public function deploy( string $type, string $id, bool $require_capability = true ) {
		if ( $require_capability ) {
			Capability::require_deploy( $type );
		}

		$package = $this->packages->find( $type, $id );

		if ( null === $package ) {
			return new \WP_Error(
				'webba_deploy_not_found',
				__( 'Package not found.', 'webba-deploy' )
			);
		}

		return $this->deploy_package( $type, $package, $require_capability );
	}

	/**
	 * Deploy a package by folder slug (push-to-deploy REST).
	 *
	 * @param string $type               Package type.
	 * @param string $slug               Folder slug.
	 * @param bool   $require_capability Whether to enforce admin capabilities.
	 * @return true|\WP_Error
	 */
	public function deploy_by_slug( string $type, string $slug, bool $require_capability = false ) {
		if ( $require_capability ) {
			Capability::require_deploy( $type );
		}

		$package = $this->packages->find_by_slug( $type, $slug );

		if ( null === $package ) {
			return new \WP_Error(
				'webba_deploy_not_found',
				__( 'Package not found.', 'webba-deploy' )
			);
		}

		return $this->deploy_package( $type, $package, $require_capability );
	}

	/**
	 * Run deploy pipeline for a resolved package record.
	 *
	 * @param string               $type               Package type.
	 * @param array<string, mixed> $package            Package record.
	 * @param bool                 $require_capability Whether to elevate user for upgrader.
	 * @return true|\WP_Error
	 */
	private function deploy_package( string $type, array $package, bool $require_capability ) {
		$id = (string) ( $package['id'] ?? '' );

		if ( '' === $id ) {
			return new \WP_Error(
				'webba_deploy_not_found',
				__( 'Package not found.', 'webba-deploy' )
			);
		}

		$owner  = (string) $package['owner'];
		$repo   = (string) $package['repo'];
		$branch = (string) ( $package['branch'] ?? 'main' );
		$slug   = (string) $package['slug'];

		$download_token = $this->resolve_download_token( $package );

		if ( is_wp_error( $download_token ) ) {
			return $download_token;
		}

		$previous_user = $this->maybe_elevate_user( $type, $require_capability );

		if ( is_wp_error( $previous_user ) ) {
			return $previous_user;
		}

		$url = $this->downloader->build_zipball_url( $owner, $repo, $branch );

		$this->logger->log(
			'info',
			sprintf( 'Starting deploy for %s/%s@%s', $owner, $repo, $branch ),
			array(
				'package_id' => $id,
				'type'       => $type,
				'slug'       => $slug,
			)
		);

		try {
			$zip_path = $this->downloader->download( $url, is_string( $download_token ) ? $download_token : null );

			if ( is_wp_error( $zip_path ) ) {
				$this->record_failure( $type, $id, $zip_path );
				return $zip_path;
			}

			$is_update = PackageRepository::is_installed_on_disk( $type, $slug );

			$result = $this->upgrader->install( $type, $zip_path, $is_update, $slug );

			wp_delete_file( $zip_path );

			if ( is_wp_error( $result ) ) {
				$this->record_failure( $type, $id, $result );
				return $result;
			}
		} finally {
			$this->restore_user( $previous_user );
		}

		$this->record_deploy_success( $type, $package, 'deploy' );

		return true;
	}

	/**
	 * Update package metadata after files are on disk (dashboard, REST, or WP updates screen).
	 *
	 * @param string               $type    plugin|theme.
	 * @param array<string, mixed> $package Package record.
	 * @param string               $source  deploy|wp_update.
	 */
	public function record_deploy_success( string $type, array $package, string $source = 'deploy' ): void {
		$id   = (string) ( $package['id'] ?? '' );
		$slug = (string) ( $package['slug'] ?? '' );

		if ( '' === $id ) {
			return;
		}

		$commit_sha = ( new CommitChecker() )->resolve_deployed_sha_for_package( $package );

		if ( 'wp_update' === $source ) {
			$message = __( 'Updated from the Plugins or Themes screen.', 'webba-deploy' );
		} else {
			$message = __( 'Deploy completed successfully.', 'webba-deploy' );
		}

		if ( is_string( $commit_sha ) ) {
			$message = sprintf(
				/* translators: %s: short Git commit hash */
				__( 'Deployed commit %s.', 'webba-deploy' ),
				substr( $commit_sha, 0, 7 )
			);

			if ( 'wp_update' === $source ) {
				$message = sprintf(
					/* translators: %s: short Git commit hash */
					__( 'Updated from WordPress admin (commit %s).', 'webba-deploy' ),
					substr( $commit_sha, 0, 7 )
				);
			}
		}

		$this->packages->update(
			$type,
			$id,
			array(
				'last_deploy' => array(
					'time'    => current_time( 'mysql' ),
					'commit'  => is_string( $commit_sha ) ? $commit_sha : null,
					'status'  => 'success',
					'message' => $message,
				),
			)
		);

		$this->logger->log(
			'success',
			sprintf( 'Deploy succeeded for %s', $slug ),
			array(
				'package_id' => $id,
				'type'       => $type,
				'source'     => $source,
			)
		);

		/**
		 * Fires after a successful deploy.
		 *
		 * @param array<string, mixed> $package Package record.
		 * @param string               $type    plugin|theme.
		 */
		do_action( 'webba_deploy_after_deploy', $package, $type );

		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );

		$this->maybe_flush_caches();
	}

	/**
	 * Sync Webba Deploy state for a managed slug after a WordPress core update.
	 *
	 * @param string $type plugin|theme.
	 * @param string $slug Folder slug.
	 */
	public function sync_after_wordpress_update( string $type, string $slug ): void {
		$package = $this->packages->find_by_slug( $type, $slug );

		if ( null === $package ) {
			return;
		}

		$this->record_deploy_success( $type, $package, 'wp_update' );
	}

	/**
	 * Bearer token for private GitHub zipball downloads.
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return null|string|\WP_Error
	 */
	public function download_token_for_package( array $package ) {
		return $this->resolve_download_token( $package );
	}

	/**
	 * Record failed deploy on package and log.
	 *
	 * @param string    $type Package type.
	 * @param string    $id   Package ID.
	 * @param \WP_Error $error Error.
	 */
	private function record_failure( string $type, string $id, \WP_Error $error ): void {
		$this->packages->update(
			$type,
			$id,
			array(
				'last_deploy' => array(
					'time'    => current_time( 'mysql' ),
					'commit'  => null,
					'status'  => 'error',
					'message' => $error->get_error_message(),
				),
			)
		);

		$this->logger->log(
			'error',
			$error->get_error_message(),
			array(
				'package_id' => $id,
				'type'       => $type,
				'code'       => $error->get_error_code(),
			)
		);
	}

	/**
	 * Bearer token for private GitHub downloads, or null for public.
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return null|string|\WP_Error
	 */
	private function resolve_download_token( array $package ) {
		if ( 'private' !== ( $package['visibility'] ?? 'public' ) ) {
			return null;
		}

		$connection = new ConnectionStore();

		if ( ! $connection->is_connected() ) {
			return new \WP_Error(
				'webba_deploy_private_requires_app',
				__( 'Connect the GitHub App before deploying private repositories.', 'webba-deploy' )
			);
		}

		$owner = (string) ( $package['owner'] ?? '' );
		$repo  = (string) ( $package['repo'] ?? '' );

		$lister = new RepositoryLister();

		if ( ! $lister->installation_has_repository( $owner, $repo ) ) {
			return new \WP_Error(
				'webba_deploy_repo_not_granted',
				__( 'This repository is not available on your GitHub App installation. Grant access on GitHub or pick another repository.', 'webba-deploy' )
			);
		}

		$installation_id = $connection->get_installation_id();

		if ( null === $installation_id ) {
			return new \WP_Error(
				'webba_deploy_private_requires_app',
				__( 'GitHub App installation ID is missing.', 'webba-deploy' )
			);
		}

		return ( new InstallationTokenService() )->get_token( $installation_id );
	}

	/**
	 * Temporarily set current user for filesystem/upgrader when no admin session exists.
	 *
	 * @param string $type               Package type.
	 * @param bool   $require_capability Whether capability was required (admin UI).
	 * @return int|null|\WP_Error Previous user ID, null if unchanged, or error.
	 */
	private function maybe_elevate_user( string $type, bool $require_capability ) {
		if ( $require_capability ) {
			return null;
		}

		if ( get_current_user_id() > 0 && Capability::can_deploy( $type ) ) {
			return null;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		if ( empty( $admins ) ) {
			return new \WP_Error(
				'webba_deploy_no_admin',
				__( 'No administrator account is available to run the deploy.', 'webba-deploy' )
			);
		}

		$previous = get_current_user_id();
		wp_set_current_user( (int) $admins[0] );

		if ( ! Capability::can_deploy( $type ) ) {
			wp_set_current_user( $previous );

			return new \WP_Error(
				'webba_deploy_insufficient_caps',
				__( 'No user with permission to install packages could be found.', 'webba-deploy' )
			);
		}

		return $previous;
	}

	/**
	 * Restore user after temporary elevation.
	 *
	 * @param int|null|\WP_Error $previous_user Previous user ID or sentinel.
	 */
	private function restore_user( $previous_user ): void {
		if ( ! is_int( $previous_user ) ) {
			return;
		}

		wp_set_current_user( $previous_user );
	}

	/**
	 * Flush caches when enabled in settings.
	 */
	private function maybe_flush_caches(): void {
		$settings = get_option( 'webba_deploy_settings', array() );

		if ( empty( $settings['flush_cache'] ) ) {
			return;
		}

		/**
		 * Filter cache flush callbacks after deploy.
		 *
		 * @param array<int, callable> $flushers Callables that flush cache.
		 */
		$flushers = apply_filters( 'webba_deploy_cache_flushers', array() );

		foreach ( $flushers as $callback ) {
			if ( is_callable( $callback ) ) {
				call_user_func( $callback );
			}
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}
}
