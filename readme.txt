=== PushWP ===
Contributors: faithcoder
Tags: github, deployment, updater, themes
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely install and update WordPress themes and plugins directly from GitHub, without FTP or SSH.

== Description ==

PushWP lets administrators install and update WordPress
themes and plugins straight from a GitHub repository, from the WordPress
dashboard.

* Connect a GitHub account via OAuth.
* Enter a repository URL and branch (or tag/release).
* Auto-detect whether the repository contains a plugin or a theme.
* Install new packages or update managed ones in place, preserving the
  destination folder and keeping active plugins active.
* Optional push webhooks for automatic deployment after merges.
* Rollback on failure, deployment locking, and a sanitized audit log.

No FTP or SSH is required. Private repositories work once the connected GitHub
account has access.

== External services ==

This plugin connects directly to GitHub because its core features require
GitHub OAuth, repository metadata, commit information, releases, and source
archives.

When an administrator connects an account, the site sends the OAuth client ID,
client secret, temporary authorization code, callback URL, and requested scope
to GitHub. Subsequent requests send the resulting access token in an
authorization header along with repository owner, repository name, and the
configured branch, tag, release, or commit reference. GitHub returns account
information, repository metadata, and repository archives. No data is sent to
the plugin author.

This service is provided by GitHub and is subject to the GitHub Terms of Service
(https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)
and GitHub General Privacy Statement
(https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).

== Installation ==

1. Upload the `pushwp` folder to `/wp-content/plugins/`, or install
   the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Define your GitHub OAuth credentials — either on the settings page
   (Tools → PushWP → GitHub OAuth App) or in `wp-config.php` (see below).
4. Go to Tools → PushWP.

== GitHub OAuth App setup ==

1. Visit https://github.com/settings/developers and click "New OAuth App".
2. Set the homepage and callback URLs. The callback URL is shown on the plugin
   settings screen and is typically `https://your-site.example/wp-admin/admin-post.php?action=pushwp_oauth_callback`.
3. Copy the Client ID and Client Secret into the plugin's GitHub OAuth App
   section, or into `wp-config.php`:

`define( 'PUSHWP_GITHUB_CLIENT_ID', 'your-client-id' );`
`define( 'PUSHWP_GITHUB_CLIENT_SECRET', 'your-client-secret' );`

`wp-config.php` constants take precedence over the settings screen. Stored
secrets are encrypted when sodium and WordPress authentication salts are
available and are never displayed. If encryption is unavailable, the settings
screen warns the administrator; defining credentials in `wp-config.php` avoids
database storage.

== Webhook setup ==

1. On the plugin screen, add a repository and enable automatic deployment.
2. Note the webhook URL and one-time secret shown for that repository.
3. In the GitHub repository settings, add a webhook:
   * Payload URL: the URL shown in the plugin.
   * Content type: `application/json`.
   * Secret: the one-time secret shown in the plugin.
   * Events: "Just the push event".

Merging a branch configured for the repository triggers an automatic background
deployment.

== Frequently Asked Questions ==

= Does it need FTP or SSH? =

No. All file operations use the WordPress upgrader and filesystem APIs.

= Are private repositories supported? =

Yes, once the connected GitHub account can access them. The plugin requests the
`repo` OAuth scope.

= Does removing a repository delete the installed plugin or theme? =

No. Removing a repository only removes it from the manager.

== Changelog ==

= 1.0.0 =
* Initial release.
