<?php
/**
 * Build push-to-deploy REST URLs.
 *
 * @package WebbaDeploy
 */

declare(strict_types=1);

namespace WebbaDeploy\Core;

/**
 * REST deploy URL helpers.
 */
class DeployUrl {

	/**
	 * Base REST deploy endpoint.
	 */
	public static function endpoint(): string {
		return rest_url( 'webba-deploy/v1/deploy' );
	}

	/**
	 * Full deploy URL for a package.
	 *
	 * @param string      $type   plugin|theme.
	 * @param string      $slug   Package folder slug.
	 * @param string|null $secret Site secret; omit to build a template without secret.
	 */
	public static function for_package( string $type, string $slug, ?string $secret = null ): string {
		$args = array(
			'package' => sanitize_title( $slug ),
			'type'    => sanitize_key( $type ),
		);

		if ( null !== $secret && '' !== $secret ) {
			$args['secret'] = $secret;
		}

		return add_query_arg( $args, self::endpoint() );
	}
}
