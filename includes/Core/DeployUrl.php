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
	 * The secret is normally sent in the X-Webba-Deploy-Secret header. Passing
	 * it here embeds it in the query string for backwards compatibility.
	 *
	 * @param string      $type   plugin|theme.
	 * @param string      $slug   Package folder slug.
	 * @param string|null $secret Legacy query-string secret; omit for header auth.
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
