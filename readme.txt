=== Webba Deploy ===
Contributors: giodc
Tags: github, deploy, theme, update, git
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Deploy plugins and themes from GitHub — public or private repos, one-click GitHub App setup, push-to-deploy, and native WP updates.

== Description ==

Webba Deploy lets site administrators install and update WordPress plugins and themes directly from GitHub repositories — without Git on the server.

**Features**

* **Public repositories** — add a GitHub URL, pick a branch, deploy.
* **Private repositories** — read-only GitHub App; repository access is chosen on GitHub during installation.
* **One-click GitHub setup** — register the App from **Webba Deploy → GitHub** (no wp-config.php required); credentials stored encrypted in the database.
* **Native WordPress updates** — packages appear on **Plugins** and **Themes** update screens.
* **Push-to-deploy** — REST endpoint secured by a rotatable site secret (for CI / GitHub Actions).
* **Dashboard** — deploy status, commit comparison, and per-package actions.

**External services**

This plugin connects to **GitHub** (`api.github.com`, `github.com`, `codeload.github.com`) to list repositories, download zipballs, and register or use a GitHub App. No data is sent to third parties other than GitHub.

* Terms of Service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* Privacy Statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

When you use one-click setup, the plugin stores the GitHub App ID and private key **encrypted** in your WordPress database (`wp_options`). Optional `wp-config.php` constants can override stored credentials for agency setups.

**WordPress update screens**

Webba Deploy does **not** update itself outside wordpress.org. It only injects update offers for plugins and themes that an administrator has explicitly registered to deploy from GitHub, using the same GitHub zipball download path as a manual deploy.

**Requirements**

* PHP **OpenSSL** extension (GitHub App JWT and encrypted credential storage).
* For one-click GitHub App registration, the site must use a **public HTTPS URL** (GitHub manifest redirect).

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/webba-deploy`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Webba Deploy** in the admin menu.
4. For private repos: **Webba Deploy → GitHub → Set up with GitHub**, then continue installation on GitHub and select repositories.

== Frequently Asked Questions ==

= Does this require Git on the server? =

No. Deploys use the GitHub HTTP zipball API and WordPress core upgrader classes.

= Can I deploy private repositories? =

Yes. Go to **Webba Deploy → GitHub** and use **Set up with GitHub** (recommended). You create a read-only GitHub App on GitHub, then choose which repositories to grant during installation.

Alternatively, define `WEBBA_DEPLOY_GITHUB_APP_ID`, `WEBBA_DEPLOY_GITHUB_APP_SLUG`, and `WEBBA_DEPLOY_GITHUB_APP_KEY` in `wp-config.php` to use a shared App. See `webba-deploy-config-sample.php` in the plugin directory.

= Does the plugin work on localhost? =

Public GitHub repos work on local sites. One-click GitHub App setup requires a public HTTPS URL (use a tunnel such as ngrok, or define `WEBBA_DEPLOY_GITHUB_PUBLIC_URL` in wp-config.php).

= What permissions does the GitHub App need? =

Read-only **Contents** and **Metadata**. Repository access is limited to repos you select when installing the App on GitHub.

= Is push-to-deploy secure? =

Deploy URLs include a site secret. Keep it private, rotate it from **Webba Deploy → Push-to-deploy** if exposed, and use HTTPS only.

== Screenshots ==

1. Dashboard with package cards and deploy actions
2. GitHub connection — one-click App setup
3. Add plugin from a private GitHub repository

== Changelog ==

= 1.0.0 =
* First stable release.
* Public and private GitHub deploy with read-only GitHub App (one-click setup or wp-config override).
* Native WordPress plugin and theme update integration.
* Push-to-deploy REST endpoint with rotatable site secret.
* Admin dashboard with commit comparison and package management.
* Translation template (`languages/webba-deploy.pot`) included.

= 0.4.0 =
* One-click GitHub App setup via manifest flow (no wp-config required); credentials stored encrypted.
* Manifest URL fixes for production and HTTPS sites; clearer installation step labels.
* Optional wp-config.php constants still override stored App credentials.

= 0.3.0 =
* GitHub App connection for private repositories (read-only; repo access selected on GitHub during install).
* Add package wizard: public URL or private repo picker from installation.
* GitHub settings page with connect/disconnect and setup URL.
* Updates appear on **Plugins** and **Themes** screens; updating there syncs Webba Deploy commit state.

= 0.2.0 =
* Push-to-deploy REST endpoint (`/wp-json/webba-deploy/v1/deploy`) with secret auth and rate limiting.
* Push-to-deploy settings page with secret rotation.
* Per-package deploy URLs on the dashboard.

= 0.1.0 =
* Initial release: public GitHub plugin and theme deploy, admin dashboard, deploy logging.

== Upgrade Notice ==

= 1.0.0 =
First stable release. Recommended for production use.

= 0.4.0 =
One-click GitHub App setup — no wp-config.php required for most sites. Existing wp-config App credentials still work.

= 0.3.0 =
Private GitHub deploy via read-only GitHub App and native WordPress update integration.
