# Development

## Requirements

- PHP 7.4+
- WordPress 6.0+
- Composer (dev dependencies only)

## Coding standards

```bash
composer install
composer run phpcs
```

Auto-fix where possible:

```bash
composer run phpcbf
```

## Local GitHub App callbacks

For webhook and install callbacks on a local site, use a tunnel (e.g. ngrok) or [smee.io](https://smee.io/) and point the GitHub App URLs at the public tunnel URL.

## Manual test (Phase 1)

1. Activate the plugin.
2. **Webba Deploy → Add Plugin** — link a public repo, enable **Install now**.
3. Confirm files under `wp-content/plugins/{slug}`.
4. **Deploy now** on the dashboard to update.
5. **Delete** removes the package record (not the installed files).

## Manual test (Phase 2)

1. Copy a package push-to-deploy URL from the dashboard.
2. `curl -fsS -X POST "<url>"` — expect HTTP 200 and updated files.
3. Call with a wrong secret — expect HTTP 401.
4. **Push-to-deploy → Generate new secret** — old URLs must return 401.
