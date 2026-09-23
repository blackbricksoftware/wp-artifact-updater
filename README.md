# wp-artifact-updater

WordPress updates for plugins that don't live on wordpress.org.

Point it at the release artifact your build already publishes — a zip in
Bitbucket **Downloads** or a GitHub **release asset** — and WordPress treats the
plugin like any other: update notice, one-click update, and the per-plugin
"Enable auto-updates" toggle all start working.

```php
use BlackBrickSoftware\WpArtifactUpdater\V1\{Updater, BitbucketDownloads};

Updater::register([
  'plugin_file'   => __FILE__,
  'source'        => new BitbucketDownloads('acme/my-plugin'),
  'artifact'      => '/^my-plugin-v?(\d+\.\d+\.\d+)\.zip$/',
  'authorization' => static fn(): string => defined('MY_PLUGIN_UPDATE_AUTH')
    ? 'Bearer ' . MY_PLUGIN_UPDATE_AUTH
    : '',
]);
```

Plus one header in the plugin's main file:

```php
 * Update URI: https://bitbucket.org/acme/my-plugin
```

---

## Why this exists

WordPress can only update what it can find on wordpress.org. The usual answer is
[plugin-update-checker][puc], and it's a good library — but its **Bitbucket
integration installs the tag archive** (`bitbucket.org/…/get/<ref>.zip`), which
is the repository tree. A composer-based plugin's tree has no `vendor/`, so the
"update" installs a plugin with no autoloader that fatals on activation. There
is no Downloads support to point it at instead, and its auth header is gated on
a `/get/` URL prefix, so rewriting the download URL yields a 401.

This library installs **what your pipeline built**, which is the thing you
actually tested.

[puc]: https://github.com/YahnisElsts/plugin-update-checker

## What it deliberately does not do

- **Force installation.** No `auto_update_plugin` filter is registered, so
  updates are *offered* and each site opts in. For a plugin that can lock people
  out — authentication, access control — a bad release installing itself
  unattended across every site at once is the failure worth designing against.
- **Guess where your credential lives.** You pass a callable. Read it from an
  environment variable, a constant, your own settings page; the library never
  looks.
- **Downgrade.** Only a strictly newer version is offered.
- **Send your credential to storage hosts.** Release hosts redirect to signed
  URLs on separate infrastructure; the redirect is resolved first so the signed
  URL — which carries its own auth — is what gets downloaded.

## Install

```bash
composer require blackbricksoftware/wp-artifact-updater
```

Requires PHP 8.0+ and WordPress 4.6+ (5.8+ uses the better `Update URI` path
automatically). No dependencies.

## Configuration

| Key | Required | Meaning |
|---|---|---|
| `plugin_file` | yes | Your plugin's main file, normally `__FILE__`. Basename, slug and installed version are read from it — don't repeat them. |
| `source` | yes | A `Source`: `BitbucketDownloads` or `GitHubReleases`. |
| `artifact` | yes | PCRE selecting the release file. Capture group 1 is the version, when the filename has one. |
| `authorization` | no | `callable(): string` returning an **Authorization header value**. `''` means none. |
| `enabled` | no | `callable(): bool`. Defaults to "the source needs no credential, or one is configured". |
| `ttl` | no | Cache lifetime for the release lookup. Default 6 hours. |

### `authorization` is a header value, not a username and password

Different hosts and token types want different schemes, and the caller knows
which it has:

```php
'authorization' => static fn(): string => 'Bearer ' . $token,                       // Bitbucket/GitHub token
'authorization' => static fn(): string => 'Basic ' . base64_encode("$user:$pass"),  // Bitbucket app password
'authorization' => static fn(): string => '',                                       // public repo, or updates off
```

### `enabled` — the off switch

By default, a private source with no credential registers **no hooks and makes
no HTTP requests**. That is what keeps the library out of the way on
composer-managed installs, where the deploy owns the plugin version and a
self-update would be reverted on the next deploy.

A public source has nothing to switch it off, so pass `enabled` yourself:

```php
'enabled' => static fn(): bool => !defined('COMPOSER_MANAGED_SITE'),
```

## Bitbucket

```php
new BitbucketDownloads('workspace/repository')          // private (default)
new BitbucketDownloads('workspace/repository', false)   // public
```

Reads the repository's Downloads list and picks the highest version matching
`artifact` — by version comparison, not list order, so `2.10.0` correctly beats
`2.9.0`.

**Credentials.** There is **no Downloads-only scope**; `repository` (read) is
the finest grant Bitbucket offers. Prefer a **repository access token**, which
is scoped to a single repository and isn't tied to a person's account (an app
password dies when that user is deactivated — a memorable way to discover your
update mechanism depended on someone's employment). A leaked per-repo token
reads one repository whose code the site already has on disk.

```php
'authorization' => static fn(): string => 'Bearer ' . MY_PLUGIN_UPDATE_TOKEN,
```

## GitHub

```php
new GitHubReleases('owner/repository')          // public (default)
new GitHubReleases('owner/repository', true)    // private
```

Reads the latest release and picks the matching **asset**. If `artifact`
captures a version, that wins; otherwise the version comes from `tag_name`, so a
workflow publishing a fixed filename works:

```php
'artifact' => '/^my-plugin\.zip$/',   // version comes from the tag
```

## What your pipeline must produce

Three requirements, each of which fails silently if you get it wrong:

1. **A built artifact, not a source archive.** Run `composer install --no-dev
   --optimize-autoloader` and include `vendor/`. A tag archive installs a plugin
   with no autoloader.
2. **A single top-level directory named exactly like the plugin folder.** Stage
   into `dist/my-plugin/` and zip *that*. `zip -r my-plugin.zip .` produces an
   archive with no wrapping folder, and WordPress then names the destination
   after the zip file — installing a *second copy* under a different folder name
   instead of upgrading. It looks like it worked.
3. **A plugin header version that matches the release.** WordPress reads the
   installed version from the header. If the header says `1.0.0` while you
   publish `1.0.2`, every site installs 1.0.2, still reports 1.0.0, and is
   offered the update again — forever. Bump the header in the commit you tag.
   (This is a real bug we shipped, not a hypothetical.)

A Bitbucket pipeline doing all three:

```yaml
- composer install --no-dev --optimize-autoloader --no-interaction
- mkdir -p dist/my-plugin
- rsync -a --exclude='.git/' --exclude='dist/' ./ dist/my-plugin/
- (cd dist && zip -r "../my-plugin-${BITBUCKET_TAG}.zip" my-plugin)
```

## How it behaves

- **Cached.** The release lookup is cached in a site transient (6h by default;
  15 minutes after a failure) so a bad minute at the host doesn't mean a request
  on every admin page load.
- **Quiet on failure.** An unreachable host or a bad credential means no update
  is offered. Sources never throw; an update check isn't worth a fatal.
- **`Update URI` matters.** Besides routing the check, it stops wordpress.org
  pushing a same-slug plugin onto your sites. Set it even on WP 5.8+.
- **Version comparison ignores a leading `v`.** `version_compare('v2.0.0',
  '2.0.0')` is `-1` in PHP, so a `v`-prefixed header would otherwise be offered
  the same version forever.

## Namespacing

The namespace carries a major version — `BlackBrickSoftware\WpArtifactUpdater\V1` — and
the layout mirrors it:

```
src/
  V1/   Source.php  Updater.php  BitbucketDownloads.php  GitHubReleases.php
```

PSR-4 maps `BlackBrickSoftware\WpArtifactUpdater\` to `src/`, so a breaking change is a
new `src/V2/` directory and nothing else: no autoload change, and V1 keeps
working for plugins that haven't moved.

Two plugins on one site can each ship their own copy of V1 in `vendor/` and the
first one loaded wins — harmless, because it's the same code. Only an actual
breaking change needs a new major, and then the two coexist.

If a plugin ever vendors *third-party* dependencies into its zip, prefix them at
build time with [PHP-Scoper][scoper] — that's the general fix for WordPress's
one-global-namespace problem, and it's a separate concern from this library.

[scoper]: https://github.com/humbug/php-scoper

## Testing an update without shipping one

Tag a throwaway patch release and watch it appear:

```bash
wp eval 'delete_site_transient("wpu_release_" . md5(plugin_basename(WP_PLUGIN_DIR . "/my-plugin/my-plugin.php") . "|bitbucket.org"));'
wp plugin list --name=my-plugin --fields=name,version,update
```

Clearing the transient is the quickest way to force a fresh check; otherwise
wait out the TTL.

## Licence

MIT.
