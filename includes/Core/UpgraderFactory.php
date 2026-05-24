<?php
/**
 * WordPress plugin/theme upgrader wrappers.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Core;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

/**
 * Installs or updates packages from a local zip path.
 */
class UpgraderFactory {

	/**
	 * Target folder slug for the current install (sanitized).
	 *
	 * @var string|null
	 */
	private ?string $install_target_slug = null;

	/**
	 * Whether the slug filter is registered for a native WordPress update.
	 *
	 * @var bool
	 */
	private bool $slug_override_filter_active = false;

	/**
	 * Install or update from a zip file.
	 *
	 * @param string      $type        plugin|theme.
	 * @param string      $zip_path    Local zip path.
	 * @param bool        $is_update   Whether destination may be cleared.
	 * @param string|null $target_slug Folder name under plugins/ or themes/.
	 * @return true|\WP_Error
	 */
	public function install( string $type, string $zip_path, bool $is_update = false, ?string $target_slug = null ) {
		if ( ! is_readable( $zip_path ) ) {
			return new \WP_Error(
				'webba_deploy_zip_missing',
				__( 'Archive file is not readable.', 'webba-deploy' )
			);
		}

		if ( ! $this->is_valid_zip( $zip_path ) ) {
			return new \WP_Error(
				'webba_deploy_invalid_zip',
				__( 'Downloaded file is not a valid ZIP archive.', 'webba-deploy' )
			);
		}

		$this->install_target_slug = null;
		$slug_filter_active        = false;

		if ( null !== $target_slug && '' !== $target_slug ) {
			$this->install_target_slug = sanitize_title( $target_slug );

			if ( '' !== $this->install_target_slug ) {
				add_filter( 'upgrader_source_selection', array( $this, 'filter_source_to_slug' ), 20, 4 );
				$slug_filter_active = true;
			}
		}

		$skin = new \WP_Ajax_Upgrader_Skin();

		try {
			if ( PackageRepository::TYPE_PLUGIN === $type ) {
				$upgrader = new \Plugin_Upgrader( $skin );
				$result   = $upgrader->install(
					$zip_path,
					array(
						'clear_destination' => $is_update,
						'overwrite_package' => $is_update,
					)
				);
			} elseif ( PackageRepository::TYPE_THEME === $type ) {
				$upgrader = new \Theme_Upgrader( $skin );
				$result   = $upgrader->install(
					$zip_path,
					array(
						'clear_destination' => $is_update,
						'overwrite_package' => $is_update,
					)
				);
			} else {
				$result = new \WP_Error(
					'webba_deploy_invalid_type',
					__( 'Invalid package type.', 'webba-deploy' )
				);
			}
		} finally {
			if ( $slug_filter_active ) {
				remove_filter( 'upgrader_source_selection', array( $this, 'filter_source_to_slug' ), 20 );
			}

			$this->install_target_slug = null;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			$message = $skin->get_error_messages();

			return new \WP_Error(
				'webba_deploy_upgrader_failed',
				$message ? implode( ' ', $message ) : __( 'Upgrader failed without details.', 'webba-deploy' )
			);
		}

		if ( null !== $target_slug && '' !== sanitize_title( $target_slug ) ) {
			if ( ! PackageRepository::is_installed_on_disk( $type, $target_slug ) ) {
				return new \WP_Error(
					'webba_deploy_install_path',
					__( 'Package was processed but the expected folder was not created. Check the folder slug and archive structure.', 'webba-deploy' )
				);
			}
		}

		return true;
	}

	/**
	 * Rename extracted folders during Plugins/Themes screen updates.
	 *
	 * @param string $slug Target folder slug.
	 */
	public function enable_slug_override( string $slug ): void {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return;
		}

		$this->install_target_slug = $slug;

		if ( $this->slug_override_filter_active ) {
			return;
		}

		add_filter( 'upgrader_source_selection', array( $this, 'filter_source_to_slug' ), 20, 4 );
		$this->slug_override_filter_active = true;
	}

	/**
	 * Remove slug override after an upgrader run.
	 */
	public function disable_slug_override(): void {
		if ( $this->slug_override_filter_active ) {
			remove_filter( 'upgrader_source_selection', array( $this, 'filter_source_to_slug' ), 20 );
			$this->slug_override_filter_active = false;
		}

		$this->install_target_slug = null;
	}

	/**
	 * Rename extracted archive folder to the configured slug before install.
	 *
	 * Runs after Theme/Plugin_Upgrader::check_package() (priority 10).
	 *
	 * @param string|\WP_Error     $source         Source directory path.
	 * @param string               $remote_source  Remote source path.
	 * @param \WP_Upgrader         $upgrader       Upgrader instance.
	 * @param array<string, mixed> $hook_extra Hook extra args.
	 * @return string|\WP_Error
	 */
	public function filter_source_to_slug( $source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $upgrader, $hook_extra, $remote_source );

		if ( null === $this->install_target_slug || is_wp_error( $source ) ) {
			return $source;
		}

		return $this->relocate_source_to_slug( $source, $this->install_target_slug );
	}

	/**
	 * Move the unpacked package directory to match the Webba Deploy folder slug.
	 *
	 * @param string $source Source directory (trailing slash).
	 * @param string $slug   Target folder name.
	 * @return string|\WP_Error
	 */
	private function relocate_source_to_slug( string $source, string $slug ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			\WP_Filesystem();
		}

		$source = trailingslashit( wp_normalize_path( $source ) );
		$slug   = sanitize_title( $slug );

		if ( '' === $slug ) {
			return $source;
		}

		$current = basename( untrailingslashit( $source ) );

		if ( $current === $slug ) {
			return $source;
		}

		if ( ! $wp_filesystem->is_dir( $source ) ) {
			return $source;
		}

		$parent     = trailingslashit( dirname( untrailingslashit( $source ) ) );
		$new_source = $parent . $slug . '/';

		if ( $wp_filesystem->exists( $new_source ) ) {
			$deleted = $wp_filesystem->delete( $new_source, true );

			if ( ! $deleted ) {
				return new \WP_Error(
					'webba_deploy_rename_failed',
					__( 'Could not replace the existing temporary install folder.', 'webba-deploy' )
				);
			}
		}

		$moved = $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $new_source ), true );

		if ( ! $moved ) {
			return new \WP_Error(
				'webba_deploy_rename_failed',
				__( 'Could not rename the package folder to the configured slug.', 'webba-deploy' )
			);
		}

		return $new_source;
	}

	/**
	 * Verify zip magic bytes and structure.
	 *
	 * @param string $path File path.
	 */
	private function is_valid_zip( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$header = fread( $handle, 4 );
		fclose( $handle );

		if ( false === $header || 'PK' !== substr( $header, 0, 2 ) ) {
			return false;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return true;
		}

		$zip = new \ZipArchive();
		$ok  = $zip->open( $path );

		if ( true !== $ok ) {
			return false;
		}

		$zip->close();

		return true;
	}
}
