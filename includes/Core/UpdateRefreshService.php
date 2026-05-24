<?php
/**
 * Force refresh of GitHub HEAD and WordPress update transients.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Core;

use WebbaDeploy\GitHub\CommitChecker;

/**
 * Clears caches and re-fetches update availability.
 */
class UpdateRefreshService {

	/**
	 * Bust Webba Deploy commit caches and WordPress plugin/theme update lists.
	 */
	public function refresh_all(): void {
		$packages = new PackageRepository();
		$checker  = new CommitChecker();

		foreach ( array( PackageRepository::TYPE_PLUGIN, PackageRepository::TYPE_THEME ) as $type ) {
			foreach ( $packages->all( $type ) as $package ) {
				$owner  = (string) ( $package['owner'] ?? '' );
				$repo   = (string) ( $package['repo'] ?? '' );
				$branch = (string) ( $package['branch'] ?? 'main' );

				if ( '' !== $owner && '' !== $repo ) {
					$checker->bust_cache( $owner, $repo, $branch );
				}
			}
		}

		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );

		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		wp_update_plugins();
		wp_update_themes();
	}
}
