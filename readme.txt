=== Webba Deploy ===
Contributors: Giovanni De Carlo
Tags: github, deploy, plugin, theme, update
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Deploy plugins and themes from GitHub — public or private — with one-click installs and push-to-deploy.

== Description ==

Webba Deploy lets site administrators install and update WordPress plugins and themes directly from GitHub repositories.

**Current release (0.3.0):** Public and private GitHub deploy via a read-only GitHub App (repository access chosen during App installation on GitHub). Push-to-deploy REST endpoint, commit comparison on the dashboard, and one-click install/update.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/webba-deploy`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Webba Deploy** in the admin menu.

== Frequently Asked Questions ==

= Does this require Git on the server? =

No. Deploys use the GitHub HTTP zipball API and WordPress core upgrader classes.

= Can I deploy private repositories? =

Yes. Create a GitHub App with read-only Contents and Metadata permissions, add the credentials to wp-config.php, connect under **Webba Deploy → GitHub**, and grant only the repositories you need when GitHub asks during installation.

== Changelog ==

= 0.4.0 =
* Coolify-style GitHub setup: one-click manifest registration (no wp-config required); credentials stored encrypted.
* Optional wp-config.php constants still override stored App.

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

= 0.1.0 =
Initial release.
