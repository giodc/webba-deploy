# Push-to-deploy

Trigger plugin or theme deploys over HTTP without logging into WordPress admin.

## Setup

1. Activate **Webba Deploy**.
2. Open **Webba Deploy → Push-to-deploy** to view or rotate your **site secret**.
3. On the **Dashboard**, copy the **Push-to-deploy URL** for each package (includes `secret`, `package`, and `type`).

## Endpoint

```
GET|POST /wp-json/webba-deploy/v1/deploy
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `secret`  | Yes      | Site secret from Push-to-deploy settings |
| `package` | Yes      | Registered package folder slug |
| `type`    | Yes      | `plugin` or `theme` |

### POST JSON body

```json
{
  "secret": "your-site-secret",
  "package": "my-plugin",
  "type": "plugin"
}
```

### Header alternative

```
X-Webba-Deploy-Secret: your-site-secret
```

## Responses

| HTTP | Meaning |
|------|---------|
| 200  | Deploy succeeded |
| 401  | Invalid or missing secret |
| 404  | Unknown package slug |
| 429  | Rate limit exceeded |
| 500  | Deploy failed (see message) |

## GitHub Actions

See [deploy-on-push.yml](examples/deploy-on-push.yml). Store the full dashboard URL (with secret) as `WEBBA_DEPLOY_URL`.

## Security

- Rotate the secret if it is exposed.
- Use HTTPS only.
- The endpoint is rate-limited per IP (default: 30 requests per minute).
