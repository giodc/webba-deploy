<?php
/**
 * CRUD for plugin and theme package records.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Core;

use WebbaDeploy\Util\Uuid;

/**
 * Stores packages in wp_options.
 */
class PackageRepository {

	public const TYPE_PLUGIN = 'plugin';
	public const TYPE_THEME  = 'theme';

	/**
	 * Get all packages of a type.
	 *
	 * @param string $type plugin|theme.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $type ): array {
		$packages = get_option( $this->option_key( $type ), array() );

		return is_array( $packages ) ? array_values( $packages ) : array();
	}

	/**
	 * Find package by ID.
	 *
	 * @param string $type Package type.
	 * @param string $id   Package UUID.
	 * @return array<string, mixed>|null
	 */
	public function find( string $type, string $id ): ?array {
		foreach ( $this->all( $type ) as $package ) {
			if ( isset( $package['id'] ) && $package['id'] === $id ) {
				return $package;
			}
		}

		return null;
	}

	/**
	 * Find package by slug.
	 *
	 * @param string $type Package type.
	 * @param string $slug Folder slug.
	 * @return array<string, mixed>|null
	 */
	public function find_by_slug( string $type, string $slug ): ?array {
		$slug = sanitize_title( $slug );

		foreach ( $this->all( $type ) as $package ) {
			if ( isset( $package['slug'] ) && $package['slug'] === $slug ) {
				return $package;
			}
		}

		return null;
	}

	/**
	 * Whether the package folder already exists on disk.
	 *
	 * @param string $type plugin|theme.
	 * @param string $slug Folder slug.
	 */
	public static function is_installed_on_disk( string $type, string $slug ): bool {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return false;
		}

		if ( self::TYPE_PLUGIN === $type ) {
			return is_dir( WP_PLUGIN_DIR . '/' . $slug );
		}

		if ( self::TYPE_THEME === $type ) {
			return is_dir( get_theme_root() . '/' . $slug );
		}

		return false;
	}

	/**
	 * Create a new package record.
	 *
	 * @param string               $type Package type.
	 * @param array<string, mixed> $data Package fields.
	 * @return array<string, mixed>|null Created package or null on failure.
	 */
	public function create( string $type, array $data ): ?array {
		$package = $this->normalize_package( $type, $data );

		if ( null === $package ) {
			return null;
		}

		if ( $this->find_by_slug( $type, $package['slug'] ) ) {
			return null;
		}

		$packages   = $this->all( $type );
		$packages[] = $package;

		update_option( $this->option_key( $type ), $packages, false );

		return $package;
	}

	/**
	 * Update an existing package.
	 *
	 * @param string               $type Package type.
	 * @param string               $id   Package ID.
	 * @param array<string, mixed> $data Fields to merge.
	 * @return array<string, mixed>|null
	 */
	public function update( string $type, string $id, array $data ): ?array {
		$packages = $this->all( $type );
		$found    = false;

		foreach ( $packages as $index => $package ) {
			if ( ! isset( $package['id'] ) || $package['id'] !== $id ) {
				continue;
			}

			$merged     = array_merge( $package, $data );
			$normalized = $this->normalize_package( $type, $merged, $id );

			if ( null === $normalized ) {
				return null;
			}

			$packages[ $index ] = $normalized;
			$found              = true;
			break;
		}

		if ( ! $found ) {
			return null;
		}

		update_option( $this->option_key( $type ), $packages, false );

		return $this->find( $type, $id );
	}

	/**
	 * Delete a package by ID.
	 *
	 * @param string $type Package type.
	 * @param string $id   Package ID.
	 */
	public function delete( string $type, string $id ): bool {
		$packages = $this->all( $type );
		$initial  = count( $packages );

		$packages = array_values(
			array_filter(
				$packages,
				static function ( array $package ) use ( $id ): bool {
					return ! isset( $package['id'] ) || $package['id'] !== $id;
				}
			)
		);

		if ( count( $packages ) === $initial ) {
			return false;
		}

		update_option( $this->option_key( $type ), $packages, false );

		return true;
	}

	/**
	 * Option key for package type.
	 *
	 * @param string $type plugin|theme.
	 */
	private function option_key( string $type ): string {
		if ( self::TYPE_THEME === $type ) {
			return 'webba_deploy_themes';
		}

		return 'webba_deploy_plugins';
	}

	/**
	 * Build a normalized package array.
	 *
	 * @param string               $type    Package type.
	 * @param array<string, mixed> $data    Input data.
	 * @param string|null          $existing_id Existing ID when updating.
	 * @return array<string, mixed>|null
	 */
	private function normalize_package( string $type, array $data, ?string $existing_id = null ): ?array {
		$owner  = isset( $data['owner'] ) ? sanitize_text_field( (string) $data['owner'] ) : '';
		$repo   = isset( $data['repo'] ) ? sanitize_text_field( (string) $data['repo'] ) : '';
		$slug   = isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : '';
		$branch = isset( $data['branch'] ) ? sanitize_text_field( (string) $data['branch'] ) : 'main';

		if ( '' === $owner || '' === $repo || '' === $slug ) {
			return null;
		}

		if ( ! preg_match( '/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?$/', $owner ) ) {
			return null;
		}

		if ( ! preg_match( '/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?$/', $repo ) ) {
			return null;
		}

		$visibility = isset( $data['visibility'] ) ? (string) $data['visibility'] : 'public';

		if ( ! in_array( $visibility, array( 'public', 'private' ), true ) ) {
			$visibility = 'public';
		}

		$auth = 'none';

		if ( 'private' === $visibility ) {
			$auth = 'github_app';
		}

		$id = $existing_id ?? ( isset( $data['id'] ) ? (string) $data['id'] : '' );

		if ( '' === $id ) {
			$id = Uuid::v4();
		}

		$now = current_time( 'mysql' );

		return array(
			'id'          => $id,
			'slug'        => $slug,
			'type'        => $type,
			'visibility'  => $visibility,
			'owner'       => $owner,
			'repo'        => $repo,
			'branch'      => $branch,
			'auth'        => $auth,
			'auto_deploy' => false,
			'webhook_id'  => null,
			'last_deploy' => isset( $data['last_deploy'] ) && is_array( $data['last_deploy'] )
				? $data['last_deploy']
				: array(
					'time'    => null,
					'commit'  => null,
					'status'  => null,
					'message' => null,
				),
			'created_at'  => isset( $data['created_at'] ) ? (string) $data['created_at'] : $now,
		);
	}

	/**
	 * Parse a GitHub repository URL into owner and repo.
	 *
	 * @param string $url GitHub URL.
	 * @return array{owner: string, repo: string}|null
	 */
	public static function parse_github_url( string $url ): ?array {
		$url = trim( $url );

		if ( '' === $url ) {
			return null;
		}

		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return null;
		}

		$host = strtolower( (string) $parsed['host'] );

		if ( 'github.com' !== $host && 'www.github.com' !== $host ) {
			return null;
		}

		$path  = trim( (string) ( $parsed['path'] ?? '' ), '/' );
		$parts = explode( '/', $path );

		if ( count( $parts ) < 2 ) {
			return null;
		}

		$owner = $parts[0];
		$repo  = preg_replace( '/\.git$/', '', $parts[1] );

		if ( ! is_string( $repo ) || '' === $repo ) {
			return null;
		}

		if ( ! preg_match( '/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?$/', $owner ) ) {
			return null;
		}

		if ( ! preg_match( '/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?$/', $repo ) ) {
			return null;
		}

		return array(
			'owner' => $owner,
			'repo'  => $repo,
		);
	}
}
