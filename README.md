# GitHub Theme & Plugin Deployer

Securely install and update WordPress themes and plugins from GitHub, without
FTP or SSH.

- **Requires:** WordPress 6.5+, PHP 8.0+
- **Text domain:** `github-wp-deployer`
- **Main file:** `github-wp-deployer.php`

## Overview

An administrator authenticates with GitHub via OAuth, registers a repository
(URL + branch/tag/release + optional subdirectory), and the plugin detects
whether it contains a WordPress plugin or theme, then installs or updates it
using the WordPress upgrader infrastructure. Optional push webhooks deploy
automatically after merges.

```
Local development → feature branch → pull request → merge into main
  → GitHub push webhook → signature validation → background deployment
  → validation → success or rollback
```

## Architecture

| Class | Responsibility |
|---|---|
| `Plugin` | Bootstrap, service wiring, activation/deactivation hooks |
| `Settings` | Options API persistence, encrypted token store |
| `Encryption` | Sodium secretbox keyed from `AUTH_KEY` + `AUTH_SALT` |
| `GitHubClient` | WordPress HTTP API wrapper for api.github.com |
| `GitHubAuth` | OAuth connect/callback, state + nonce |
| `Utils\Url` / `Slug` / `Ref` / `WebhookSignature` / `ReplayGuard` | Pure validators |
| `PackageInspector` | Safe archive extraction + header-only detection |
| `Installer` | Upgrader orchestration, rollback, locking |
| `RepositoryManager` | Managed repository CRUD |
| `UpdateChecker` | Twice-daily cron + background deploy action |
| `Webhook` | REST endpoint with HMAC + replay protection |
| `Logger` | Capped, sanitized audit log |
| `AdminUI` | Tools → GitHub Deployer screen |

## Installation

1. Copy `github-wp-deployer` to `/wp-content/plugins/` and activate.
2. Create a GitHub OAuth App (see below) and add the credentials to
   `wp-config.php`.
3. Open **Tools → GitHub Deployer**.

## GitHub OAuth App setup

1. GitHub → Settings → Developer settings → OAuth Apps → New OAuth App.
2. Set **Authorization callback URL** to your site's
   `https://example.com/wp-admin/admin-post.php?action=gwp_deployer_oauth_callback` (the exact URL is shown on the
   plugin settings screen).
3. Enter the credentials in one of two ways:

   * On **Tools → GitHub Deployer → GitHub OAuth App** (stored in the
     database; the client secret is encrypted and never displayed), or
   * In `wp-config.php`, which takes precedence over the settings screen:

```php
define( 'GWPD_GITHUB_CLIENT_ID', '...' );
define( 'GWPD_GITHUB_CLIENT_SECRET', '...' );
```

The client secret is only ever used server-side during the token exchange and
is never stored, logged, or rendered.

## Webhook setup

1. Add a repository and enable automatic deployment.
2. Copy the one-time webhook URL and secret shown for that repository.
3. In GitHub, add a webhook with **Content type** `application/json`, the
   secret, and **Just the push event**.

The plugin verifies `X-Hub-Signature-256`, ignores pushes to other branches,
deduplicates delivery IDs, and deploys in the background.

## Configuration filters

| Filter | Default | Purpose |
|---|---|---|
| `gwp_deployer_api_timeout` | `20` | GitHub API request timeout (seconds) |
| `gwp_deployer_download_timeout` | `300` | Archive download timeout (seconds) |
| `gwp_deployer_max_archive_bytes` | `50 * MB_IN_BYTES` | Maximum archive size |
| `gwp_deployer_oauth_scopes` | `repo` | OAuth scopes requested |

## Actions

- `gwp_deployer_deployed` — after a successful deployment (`$type`, `$slug`,
  `$version`, `$sha`).

## Security model (threat-model notes)

- **Token at rest** — encrypted with libsodium secretbox using a key derived
  from `AUTH_KEY` + `AUTH_SALT`. If libsodium is missing, the token is stored
  in plaintext and the admin is warned. Tokens are never placed in transients,
  cookies, or local storage.
- **OAuth CSRF** — a high-entropy `state` value plus a WordPress nonce; state
  is verified with `hash_equals()` before accepting a token.
- **Webhook forgery** — every request must carry a valid HMAC-SHA256
  (`X-Hub-Signature-256`) for that repository's secret. The repository, branch,
  and destination are **always** read from saved admin configuration, never
  from the payload.
- **Replay** — `X-GitHub-Delivery` IDs are recorded and rejected if repeated.
- **ZIP Slip / traversal** — archive entries with absolute paths, `..`, `\`, or
  null bytes are rejected before extraction.
- **Code execution during inspection** — detection only reads file headers
  (first 8 KB) using the same algorithm as `get_file_data()`; downloaded PHP is
  never executed.
- **SSRF** — only `api.github.com` and its archive endpoint are ever contacted;
  SSL verification is never disabled.
- **Privilege escalation** — every administrative action requires
  `install_plugins` **and** `install_themes`, plus a nonce.
- **Accidental overwrite** — the plugin refuses to replace itself, refuses to
  overwrite unmanaged packages without explicit confirmation, and refuses to
  touch a destination managed by a different entry.
- **Data loss** — a rollback copy is made before every replacement and restored
  on failure. A transient lock prevents concurrent deployments.

## Known limitations

- The archive size limit is enforced after the download completes (with a
  best-effort early rejection via `Content-Length`); an oversized download is
  still consumed before being rejected.
- If `AUTH_KEY`/`AUTH_SALT` are changed, stored tokens can no longer be
  decrypted and the account must be reconnected.
- The full OAuth flow and private-repository behavior require real GitHub OAuth
  App credentials and cannot be fully automated in CI.
- `secret_shown` persistence is best-effort; the secret is displayed once after
  the repository is created and then hidden.

## Manual end-to-end testing checklist

1. Activate the plugin with no warnings or fatal errors.
2. Define `GWPD_GITHUB_CLIENT_ID`/`GWPD_GITHUB_CLIENT_SECRET`, click **Connect
   GitHub**, and confirm the account appears.
3. Validate a public plugin repository (e.g. `WordPress/two-factor`) and confirm
   the detected name/version/type/sha are shown.
4. Install the repository and confirm it appears in Plugins with the correct
   folder slug.
5. Change the branch HEAD and click **Deploy now**; confirm the version updates
   and the folder slug is unchanged, and an active plugin stays active.
6. Install a theme repository and confirm it appears under Appearance → Themes.
7. Enable automatic deployment, add a webhook with the shown secret, and push
   to the configured branch; confirm a background deployment occurs.
8. Push with a wrong secret (or no signature) and confirm a `401`.
9. Push to a non-configured branch and confirm it is ignored.
10. Confirm temporary files are removed after both successful and failed
    operations (`ls` in the system temp dir).
11. Force a failure (e.g. invalid branch) and confirm the previous version is
    restored.

## Development

```bash
composer install
vendor/bin/phpcs --standard=phpcs.xml.dist .
vendor/bin/phpunit
```

PHPUnit tests cover URL parsing, slug validation, ref matching, signature
verification, replay protection, and archive inspection. The test suite is
dependency-light and does not require a running WordPress installation.

## Packaging

The distributable ZIP must contain a single top-level `github-wp-deployer`
directory and exclude development-only files (`vendor/`, `composer.json`,
`composer.lock`, `tests/`, `phpcs.xml.dist`, `.git*`).

## License

GPLv2 or later.
