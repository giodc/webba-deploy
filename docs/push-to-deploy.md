# Push-to-deploy

Trigger plugin or theme deploys over HTTP without logging into WordPress admin.

## Setup

1. Activate **Webba Deploy**.
2. Open **Webba Deploy → Push-to-deploy** to view or rotate the **plugin** and **theme** secrets separately.
3. On the **Dashboard**, copy the **Deploy URL** and the **Secret** for each package. The URL does not contain the secret.

Secrets are scoped by package type: the plugin secret deploys plugins only, the theme secret themes only.

## Endpoint

```
POST /wp-json/webba-deploy/v1/deploy
X-Webba-Deploy-Secret: <plugin or theme secret>

package=my-plugin&type=plugin
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `type`    | Yes      | `plugin` or `theme`; selects the secret to validate against |
| `package` | Yes      | Registered package folder slug |
| `secret`  | No       | Legacy query-string secret (prefer the header) |

### POST JSON body

```json
{
  "package": "my-plugin",
  "type": "plugin"
}
```

Send the secret in the `X-Webba-Deploy-Secret` header. The legacy query-string form is still accepted:

```
GET|POST /wp-json/webba-deploy/v1/deploy?secret=your-site-secret&package=my-plugin&type=plugin
```

## Responses

| HTTP | Meaning |
|------|---------|
| 200  | Deploy succeeded |
| 400  | Missing or invalid `type` |
| 401  | Invalid or missing secret |
| 404  | Unknown package slug |
| 429  | Too many failed attempts |
| 500  | Deploy failed (see message) |

## GitHub Actions

See [deploy-on-push.yml](examples/deploy-on-push.yml). Store the secret as `WEBBA_DEPLOY_SECRET` and the URL as `WEBBA_DEPLOY_URL`.

## Security

- Prefer the `X-Webba-Deploy-Secret` header. Query-string secrets can appear in access logs and `curl` history.
- Rotate the secret if it is exposed.
- Use HTTPS only.
- Failed requests are rate-limited per IP (default: 30 per minute). Successful requests are not counted, so shared CI egress IPs are not blocked.
