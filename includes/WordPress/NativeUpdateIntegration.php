<?php
/**
 * Surface GitHub updates on Plugins / Themes screens and sync after WP updates.
 *
 * This is not a self-updater for Webba Deploy. It only injects update offers for
 * packages the site admin registered for GitHub deploy (same zipball path as
 * Dashboard → Deploy). WordPress.org continues to handle updates for this plugin.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\WordPress;

use WebbaDeploy\Core\ArchiveDownloader;
use WebbaDeploy\Core\DeployService;
use WebbaDeploy\Core\PackageRepository;
use WebbaDeploy\Core\UpgraderFactory;
use WebbaDeploy\GitHub\CommitChecker;

/**
 * Injects GitHub updates into WordPress update transients.
 */
class NativeUpdateIntegration {

	/**
	 * Package registry.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * Commit comparison service.
	 *
	 * @var CommitChecker
	 */
	private CommitChecker $commits;

	/**
	 * GitHub archive downloader.
	 *
	 * @var ArchiveDownloader
	 */
	private ArchiveDownloader $downloader;

	/**
	 * Deploy service.
	 *
	 * @var DeployService
	 */
	private DeployService $deploy;

	/**
	 * Upgrader factory.
	 *
	 * @var UpgraderFactory
	 */
	private UpgraderFactory $upgrader;

	/**
	 * Package being updated via core upgrader (for completion hook).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $pending_package = null;

	/**
	 * Constructor.
	 *
	 * @param PackageRepository|null $packages   Optional package registry.
	 * @param CommitChecker|null     $commits    Optional commit checker.
	 * @param ArchiveDownloader|null $downloader Optional archive downloader.
	 * @param DeployService|null     $deploy     Optional deploy service.
	 * @param UpgraderFactory|null   $upgrader   Optional upgrader factory.
	 */
	public function __construct(
		?PackageRepository $packages = null,
		?CommitChecker $commits = null,
		?ArchiveDownloader $downloader = null,
		?DeployService $deploy = null,
		?UpgraderFactory $upgrader = null
	) {
		$this->packages   = $packages ?? new PackageRepository();
		$this->commits    = $commits ?? new CommitChecker();
		$this->downloader = $downloader ?? new ArchiveDownloader();
		$this->deploy     = $deploy ?? new DeployService( $this->packages );
		$this->upgrader   = $upgrader ?? new UpgraderFactory();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_plugin_updates' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'inject_theme_updates' ) );
		// WordPress often serves a cached transient without rebuilding — merge on read too.
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_plugin_updates' ) );
		add_filter( 'site_transient_update_themes', array( $this, 'inject_theme_updates' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'download_github_archive' ), 10, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'before_core_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'after_core_install' ), 10, 2 );
	}

	/**
	 * Add update offers for managed plugins.
	 *
	 * @param object|false $transient Update transient.
	 * @return object|false
	 */
	public function inject_plugin_updates( $transient ) {
		$packages = $this->packages->all( PackageRepository::TYPE_PLUGIN );

		// Do not fabricate an update transient when nothing is managed.
		if ( ! is_object( $transient ) && empty( $packages ) ) {
			return $transient;
		}

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $packages as $package ) {
			$offer = $this->build_plugin_update_offer( $package );

			if ( null === $offer ) {
				continue;
			}

			$transient->response[ $offer['plugin'] ] = (object) $offer['data'];
		}

		return $transient;
	}

	/**
	 * Add update offers for managed themes.
	 *
	 * @param object|false $transient Update transient.
	 * @return object|false
	 */
	public function inject_theme_updates( $transient ) {
		$packages = $this->packages->all( PackageRepository::TYPE_THEME );

		// Do not fabricate an update transient when nothing is managed.
		if ( ! is_object( $transient ) && empty( $packages ) ) {
			return $transient;
		}

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		foreach ( $packages as $package ) {
			$offer = $this->build_theme_update_offer( $package );

			if ( null === $offer ) {
				continue;
			}

			$stylesheet = $offer['theme'];
			$transient->response[ $stylesheet ] = $offer['data'];

			if ( isset( $transient->no_update[ $stylesheet ] ) ) {
				unset( $transient->no_update[ $stylesheet ] );
			}
		}

		return $transient;
	}

	/**
	 * Download GitHub zipballs with Webba Deploy (auth + redirect handling).
	 *
	 * @param bool|\WP_Error|string $reply      Short-circuit value.
	 * @param string                $package_url Package URL.
	 * @param \WP_Upgrader          $upgrader   Upgrader instance.
	 * @param array<string, mixed>  $hook_extra Hook extra.
	 * @return bool|\WP_Error|string
	 */
	public function download_github_archive( $reply, string $package_url, \WP_Upgrader $upgrader, array $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $upgrader );

		if ( false !== $reply ) {
			return $reply;
		}

		$package = $this->find_package_for_zipball_url( $package_url );

		if ( null === $package ) {
			$package = $this->package_from_hook_extra( $hook_extra );
		}

		if ( null === $package ) {
			return $reply;
		}

		$token = $this->deploy->download_token_for_package( $package );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$path = $this->downloader->download( $package_url, is_string( $token ) ? $token : null );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		return $path;
	}

	/**
	 * Ensure GitHub zipballs install into the configured folder slug.
	 *
	 * @param bool|mixed           $response   Whether to continue install.
	 * @param array<string, mixed> $hook_extra Hook extra.
	 * @return bool|mixed
	 */
	public function before_core_install( $response, array $hook_extra ) {
		$package = $this->package_from_hook_extra( $hook_extra );

		if ( null === $package ) {
			return $response;
		}

		$this->pending_package = $package;
		$this->upgrader->enable_slug_override( (string) $package['slug'] );

		return $response;
	}

	/**
	 * Sync Webba Deploy metadata after updating from Plugins / Themes.
	 *
	 * @param \WP_Upgrader         $upgrader   Upgrader instance.
	 * @param array<string, mixed> $hook_extra Hook extra.
	 */
	public function after_core_install( \WP_Upgrader $upgrader, array $hook_extra ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->upgrader->disable_slug_override();

		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			$this->pending_package = null;
			return;
		}

		$package = $this->pending_package ?? $this->package_from_hook_extra( $hook_extra );
		$this->pending_package = null;

		if ( null === $package ) {
			return;
		}

		$type = (string) ( $package['type'] ?? '' );
		$slug = (string) ( $package['slug'] ?? '' );

		if ( '' === $type || '' === $slug ) {
			return;
		}

		$this->deploy->sync_after_wordpress_update( $type, $slug );
	}

	/**
	 * Build plugin update metadata when GitHub is ahead.
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return array{plugin: string, data: array<string, mixed>}|null
	 */
	private function build_plugin_update_offer( array $package ): ?array {
		$slug = (string) ( $package['slug'] ?? '' );

		if ( '' === $slug || ! PackageRepository::is_installed_on_disk( PackageRepository::TYPE_PLUGIN, $slug ) ) {
			return null;
		}

		if ( CommitChecker::STATUS_UPDATE_AVAILABLE !== $this->commits->get_update_status( $package, true ) ) {
			return null;
		}

		$plugin_file = $this->plugin_basename_for_slug( $slug );

		if ( null === $plugin_file ) {
			return null;
		}

		$installed_version = $this->installed_plugin_version( $plugin_file );
		$owner             = (string) ( $package['owner'] ?? '' );
		$repo              = (string) ( $package['repo'] ?? '' );
		$branch            = (string) ( $package['branch'] ?? 'main' );

		return array(
			'plugin' => $plugin_file,
			'data'   => array(
				'id'           => 'webba-deploy/' . (string) ( $package['id'] ?? $slug ),
				'slug'         => $slug,
				'plugin'       => $plugin_file,
				'new_version'  => $this->bump_version( $installed_version ),
				'url'          => sprintf( 'https://github.com/%s/%s', $owner, $repo ),
				'package'      => $this->downloader->build_zipball_url( $owner, $repo, $branch ),
				'icons'        => array(),
				'banners'      => array(),
				'requires'     => false,
				'tested'       => false,
				'requires_php' => false,
			),
		);
	}

	/**
	 * Build theme update metadata when GitHub is ahead.
	 *
	 * @param array<string, mixed> $package Package record.
	 * @return array{theme: string, data: array<string, mixed>}|null
	 */
	private function build_theme_update_offer( array $package ): ?array {
		$slug = (string) ( $package['slug'] ?? '' );

		if ( '' === $slug || ! PackageRepository::is_installed_on_disk( PackageRepository::TYPE_THEME, $slug ) ) {
			return null;
		}

		if ( CommitChecker::STATUS_UPDATE_AVAILABLE !== $this->commits->get_update_status( $package, true ) ) {
			return null;
		}

		$theme = wp_get_theme( $slug );

		if ( ! $theme->exists() ) {
			return null;
		}

		$owner  = (string) ( $package['owner'] ?? '' );
		$repo   = (string) ( $package['repo'] ?? '' );
		$branch = (string) ( $package['branch'] ?? 'main' );
		$new_version = $this->bump_version( (string) $theme->get( 'Version' ) );

		// Themes screen expects arrays in update_themes->response (not objects).
		return array(
			'theme' => $slug,
			'data'  => array(
				'theme'        => $slug,
				'new_version'  => $new_version,
				'version'      => $new_version,
				'url'          => sprintf( 'https://github.com/%s/%s', $owner, $repo ),
				'package'      => $this->downloader->build_zipball_url( $owner, $repo, $branch ),
				'requires'     => false,
				'requires_php' => false,
			),
		);
	}

	/**
	 * Map upgrader hook extra to a Webba Deploy package.
	 *
	 * @param array<string, mixed> $hook_extra Hook extra.
	 * @return array<string, mixed>|null
	 */
	private function package_from_hook_extra( array $hook_extra ): ?array {
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$slug = dirname( $hook_extra['plugin'] );

			if ( '.' === $slug || '' === $slug ) {
				return null;
			}

			$package = $this->packages->find_by_slug( PackageRepository::TYPE_PLUGIN, $slug );

			if ( null !== $package ) {
				$package['type'] = PackageRepository::TYPE_PLUGIN;
				return $package;
			}
		}

		if ( isset( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
			$package = $this->packages->find_by_slug( PackageRepository::TYPE_THEME, $hook_extra['theme'] );

			if ( null !== $package ) {
				$package['type'] = PackageRepository::TYPE_THEME;
				return $package;
			}
		}

		return null;
	}

	/**
	 * Find a package matching a GitHub zipball URL.
	 *
	 * @param string $url Download URL.
	 * @return array<string, mixed>|null
	 */
	private function find_package_for_zipball_url( string $url ): ?array {
		$parsed = $this->parse_zipball_url( $url );

		if ( null === $parsed ) {
			return null;
		}

		foreach ( array( PackageRepository::TYPE_PLUGIN, PackageRepository::TYPE_THEME ) as $type ) {
			foreach ( $this->packages->all( $type ) as $package ) {
				$owner  = (string) ( $package['owner'] ?? '' );
				$repo   = (string) ( $package['repo'] ?? '' );
				$branch = (string) ( $package['branch'] ?? 'main' );

				if ( 0 === strcasecmp( $owner, $parsed['owner'] )
					&& 0 === strcasecmp( $repo, $parsed['repo'] )
					&& $branch === $parsed['branch']
				) {
					$package['type'] = $type;
					return $package;
				}
			}
		}

		return null;
	}

	/**
	 * Parse api.github.com zipball URLs.
	 *
	 * @param string $url Request URL.
	 * @return array{owner: string, repo: string, branch: string}|null
	 */
	private function parse_zipball_url( string $url ): ?array {
		if ( ! preg_match( '#api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([^/?#]+)#i', $url, $matches ) ) {
			return null;
		}

		return array(
			'owner'  => rawurldecode( $matches[1] ),
			'repo'   => rawurldecode( $matches[2] ),
			'branch' => rawurldecode( $matches[3] ),
		);
	}

	/**
	 * Plugin basename (folder/file.php) for a folder slug.
	 *
	 * @param string $slug Plugin folder slug.
	 */
	private function plugin_basename_for_slug( string $slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$prefix = $slug . '/';

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( 0 === strpos( $plugin_file, $prefix ) ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Read installed plugin version from headers.
	 *
	 * @param string $plugin_file Plugin basename.
	 */
	private function installed_plugin_version( string $plugin_file ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$ver  = isset( $data['Version'] ) ? (string) $data['Version'] : '';

		return '' !== $ver ? $ver : '0.0.0';
	}

	/**
	 * Return a version string WordPress will treat as newer than the installed copy.
	 *
	 * @param string $version Installed version.
	 */
	private function bump_version( string $version ): string {
		$version = trim( $version );

		if ( '' === $version ) {
			return '1.0.1';
		}

		if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)(.*)$/', $version, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.' . ( (int) $matches[3] + 1 ) . $matches[4];
		}

		return $version . '.1';
	}
}
