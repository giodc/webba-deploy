<?php
/**
 * Optional GitHub App overrides for Webba Deploy.
 *
 * Most sites: use Webba Deploy → GitHub → "Set up with GitHub" (no file needed).
 *
 * Optional — copy to webba-deploy-config.php or wp-config.php to use a shared App:
 * Never commit your private key PEM or webba-deploy-config.php with real secrets.
 *
 * @package WebbaDeploy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// GitHub App numeric ID from your App settings.
// define( 'WEBBA_DEPLOY_GITHUB_APP_ID', 123456 );

// App slug from https://github.com/apps/YOUR-SLUG (used for the install link).
// define( 'WEBBA_DEPLOY_GITHUB_APP_SLUG', 'webba-deploy' );

// Absolute path to the App private key PEM file (readable by PHP only).
// Or paste the PEM string, or base64-encoded PEM.
// define( 'WEBBA_DEPLOY_GITHUB_APP_KEY', '/path/outside/webroot/webba-deploy-key.pem' );

// Local dev only: public HTTPS URL GitHub can redirect to (ngrok, Cloudflare Tunnel, etc.).
// WordPress may still run on http://localhost — this is only for manifest/callback URLs.
// define( 'WEBBA_DEPLOY_GITHUB_PUBLIC_URL', 'https://your-subdomain.ngrok-free.app' );
