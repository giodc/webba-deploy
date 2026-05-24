# GitHub App setup

Webba Deploy uses a **read-only** GitHub App for private repositories. Repository access is chosen **on GitHub** during installation (Coolify-style).

## Recommended: one-click setup (no wp-config)

1. In WordPress go to **Webba Deploy → GitHub**.
2. Click **Set up with GitHub**.
3. On GitHub: confirm the App name and **Create GitHub App** (permissions are pre-filled: Contents + Metadata, read-only).
4. You are redirected back, then to **Install GitHub App** — pick user/org and **Only select repositories**.
5. Return to WordPress — status shows connected. Use **Add Plugin/Theme → Private (GitHub App)**.

Credentials (App ID + private key) are stored **encrypted** in your WordPress database. Nothing is sent to third parties except GitHub.

### Requirements

- Site must be reachable at a **public HTTPS URL** (GitHub manifest redirect). Plain `http://localhost` will not work — GitHub rejects the manifest and shows errors like `"url" wasn't supplied`.
- PHP **OpenSSL** enabled (for JWT and encrypted storage).

### Local development (ngrok / tunnel)

1. Start a tunnel to your local WordPress port, e.g. `ngrok http 8080`.
2. Use the **https** forwarding URL (not `http://127.0.0.1`).
3. Tell WordPress about it — either:
   - **Settings → General** — set **WordPress Address** and **Site Address** to `https://your-subdomain.ngrok-free.app`, or
   - **`wp-config.php`** (or `webba-deploy-config.php`):
     ```php
     define( 'WEBBA_DEPLOY_GITHUB_PUBLIC_URL', 'https://your-subdomain.ngrok-free.app' );
     ```
4. Open **Webba Deploy → GitHub** — the page shows the URL that will be sent to GitHub. Click **Set up with GitHub** only when that URL is HTTPS and not localhost.

After setup, keep the same public URL for GitHub callbacks (installation redirect). If the tunnel URL changes, update WordPress or `WEBBA_DEPLOY_GITHUB_PUBLIC_URL` and re-run setup if callbacks break.

## Optional: wp-config.php override

For agencies running one shared App across many sites, define in `wp-config.php` (or `webba-deploy-config.php`):

```php
define( 'WEBBA_DEPLOY_GITHUB_APP_ID', 123456 );
define( 'WEBBA_DEPLOY_GITHUB_APP_SLUG', 'your-app-slug' );
define( 'WEBBA_DEPLOY_GITHUB_APP_KEY', '/path/outside/webroot/webba-deploy.pem' );
```

When these constants are set, they **override** any App registered via one-click setup. Never commit the PEM file.

Manual App creation on GitHub is still supported — use this setup URL:

```
https://YOUR-SITE.com/wp-admin/admin.php?page=webba-deploy-github&action=callback
```

## Permissions

| Permission | Access   | Why                          |
|------------|----------|------------------------------|
| Contents   | Read-only | Download zipball archives   |
| Metadata   | Read-only | List repos, default branch  |

Webhooks are **not** required for deploy (optional for future auto-deploy).

## Remove / reset

- **Disconnect installation** — removes repository access from this site; App remains on GitHub.
- **Remove App registration** — deletes stored credentials (one-click setup only). Use **Set up with GitHub** again to recreate.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Manifest error `"url" wasn't supplied` | Site URL is empty, localhost, or HTTP. Use a public **HTTPS** URL (tunnel + `WEBBA_DEPLOY_GITHUB_PUBLIC_URL` or Settings → General). |
| Other manifest errors on GitHub | Retry; ensure HTTPS and valid site URL. See [GitHub manifest docs](https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-from-a-manifest). |
| Private option disabled | Complete **Set up with GitHub** and **Install GitHub App** on the GitHub settings page. |
| No repos in dropdown | On GitHub, edit the App installation and grant repository access. |
