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

Since 1.5.0, `register()` also makes updates install into the folder the plugin
is in, and makes Dashboard › Updates › "Check again" ask the host. If another
plugin on the same site may ship an older copy of this library, register those
two yourself as well (see [Two copies on one site](#namespacing)):

```php
use BlackBrickSoftware\WpArtifactUpdater\V1\{CheckAgain, InstallFolder};

$updater = Updater::register([...]);
InstallFolder::register(__FILE__);
CheckAgain::register($updater);
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

A public source has nothing to switch it off. Sites where a deploy owns the
plugins usually set WordPress's own `DISALLOW_FILE_MODS`, which already removes
the update notice, "Update now" and auto-updates for every plugin; the only
thing left is the update check WP-Cron still runs, cached like any other. To
switch this library off for one plugin as well, pass `enabled` yourself:

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

**Private assets** are downloaded through the API, with the credential, in one
request that does not follow redirects. GitHub documents two answers to it, and
both work: usually a redirect to a short-lived signed URL on its file storage,
which is followed **without** the credential, and sometimes the file itself.
(Up to v1.3.2 only the redirect worked.) A token needs read access to the
repository's contents: for a fine-grained personal access token, **Contents:
Read-only** on that one repository.

## What your pipeline must produce

Three requirements, each of which fails silently if you get it wrong:

1. **A built artifact, not a source archive.** Run `composer install --no-dev
   --optimize-autoloader` and include `vendor/`. A tag archive installs a plugin
   with no autoloader.
2. **A single top-level directory named exactly like the plugin folder.** Stage
   into `dist/my-plugin/` and zip *that*. `zip -r my-plugin.zip .` produces an
   archive with no wrapping folder, and WordPress then names the destination
   after the zip file. On a fresh install that is the folder the plugin lives in
   from then on. On an update, before 1.5.0, it moved the plugin to a folder
   WordPress didn't know was the plugin, deactivating it while reporting
   success. Since 1.5.0 updates are renamed to the installed folder
   (`InstallFolder`), and an update with no folder inside is refused, but fresh
   installs still take the zip's name, so the folder still matters.
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

## Checking a credential

A bad credential fails silently — no release is found, which looks exactly like
being up to date. So verify it rather than waiting to find out:

```php
$result = Updater::verify(new BitbucketDownloads('acme/my-plugin'), $header);
// ['ok' => false, 'status' => 401, 'message' => 'Bitbucket rejected the credential (401). …']
```

One cheap request, never throws, and `status === 0` means the host could not be
reached rather than the credential being wrong. Worth calling when a credential
is saved, and from a "test this token" button — tokens get revoked long after
anyone remembers configuring them.

**Bitbucket token form:** paste a repository access token **on its own**; it is
sent as `Bearer <token>`. The `x-token-auth:<token>` form is for git over HTTPS
and the REST API rejects it with a 401 (verified against a live repository).

## Updates go into the folder the plugin is in

WordPress installs an update into the folder its zip names, not the folder the
plugin is in. When they differ, it removes the old folder, puts the new version
beside it, and the plugin is deactivated ("Plugin file does not exist") while
the update reports success. Folders drift for ordinary reasons: an archive with
no folder inside is installed into one named after the zip (`my_plugin.zip`,
`my_plugin-1.zip` on a second download, and since WordPress 7.1.4 that name
plus 16 random characters), GitHub's source archives use `repo-1.2.3`, and
people rename folders.

`InstallFolder` renames the unpacked update to the installed folder before
WordPress installs it, on every update path (Update now, bulk, auto-updates,
WP-CLI: core names the plugin in `hook_extra['plugin']` on all of them). It
never touches installs, themes or other plugins. An update whose zip has no
folder inside, or a rename that fails, is refused with a `WP_Error`; the
plugin's files are unchanged. Like every hook, it only works in a request that
loaded the plugin, so on multisite the plugin must be network-activated or
active on the main site, where WordPress runs its update checks.

## "Check again"

Dashboard › Updates › "Check again" (`update-core.php?force-check=1`) has
WordPress check straight away, but it would get this library's 6-hour cached
answer. `CheckAgain` calls `forceRecheck()` first, for users who can update
plugins, so the button really asks the host.

## Forcing a re-check

Two caches sit between a published release and the Plugins screen: ours (6
hours) and WordPress's own update transient (it re-asks at most twice a day).
Clearing either one alone achieves nothing — ours gets refilled from a check
WordPress won't run yet, and WordPress's gets refilled from our stale answer.
`forceRecheck()` clears both:

```php
$updater->forceRecheck();   // then wp_update_plugins() really asks the host
```

It drops our cached release, removes **this plugin's** entries from the update
transient, and resets `last_checked` so core's twice-a-day gate reopens. It
deliberately does not delete the whole `update_plugins` transient: that would
throw away what the site knows about every other plugin's updates, and keeping
our own entries around would let an "update available" outlive the credential
that found it.

Call it from a "check for updates now" control, and whenever the credential
changes — a `no release found` cached against the old credential otherwise makes
a good new one look broken too.

## How it behaves

- **Cached.** The release lookup is cached in a site transient (6h by default;
  15 minutes after a failure) so a bad minute at the host doesn't mean a request
  on every admin page load.
- **Quiet on failure.** An unreachable host or a bad credential means no update
  is offered. Sources never throw; an update check isn't worth a fatal.
- **A failed download forgets the cache.** What WordPress tried to download
  came from the cached release, and the usual reason it stops working is that
  the cache is out of date: replacing a GitHub release's zip gives it a new
  asset id, so the cached URL is a 404. The failure calls `forceRecheck()`, so
  the next check asks the host again rather than retrying a dead URL until both
  caches expire. The error says what the host answered, since a rejected
  credential and a missing file are fixed differently.
- **`Update URI` matters.** Besides routing the check, it stops wordpress.org
  pushing a same-slug plugin onto your sites. Set it even on WP 5.8+.
- **Version comparison ignores a leading `v`** in what this library compares and
  hands WordPress. `version_compare('v2.0.0', '2.0.0')` is `-1` in PHP. WordPress
  then compares the release with the plugin's own header as written, so keep the
  `v` out of the header: an installed `Version: v2.1.0` is offered `2.1.0`
  forever.
- **WordPress asks wordpress.org first.** `wp_update_plugins()` sends its
  wordpress.org request before it asks about `Update URI` plugins, and stops if
  that fails. A site that can't reach api.wordpress.org never asks this library.
  Besides it, a site must reach the release host: `api.github.com`, `github.com`
  and GitHub's file storage (`release-assets.githubusercontent.com`), or
  `api.bitbucket.org` and Bitbucket's.
- **Requests carry WordPress's usual user agent,** `WordPress/<version>;
  <site URL>`, as core's own requests to wordpress.org do.
- **No PHP requirement is reported.** The update offered carries no
  `requires_php`, so WordPress's auto-updater can't skip a release that needs a
  newer PHP than the site has. ("Update now" from the Plugins page does check
  the new version's `Requires PHP` header.) Raise a plugin's PHP requirement
  with that in mind.

## Namespacing

The namespace carries a major version — `BlackBrickSoftware\WpArtifactUpdater\V1` — and
the layout mirrors it:

```
src/
  V1/   Source.php  DirectDownload.php  Updater.php  BitbucketDownloads.php  GitHubReleases.php
```

PSR-4 maps `BlackBrickSoftware\WpArtifactUpdater\` to `src/`, so a breaking change is a
new `src/V2/` directory and nothing else: no autoload change, and V1 keeps
working for plugins that haven't moved.

Two plugins on one site can each ship their own copy of V1 in `vendor/`, and
only one copy of each class loads. Composer puts each plugin's autoloader at the
front of the queue as it is registered, so for a class that every copy has, the
copy in the plugin loaded last (WordPress loads plugins in order of their
folder names) supplies it to all of them. That is harmless because releases
within V1 only add: `Source` never gains a method, and anything new is an
optional extra (the `DirectDownload` interface in 1.4.0, the `InstallFolder` and
`CheckAgain` classes in 1.5.0). So copies of different V1 releases work
together, and an older copy simply behaves as it always did.

What an older copy can't do is something added after it. If your plugin calls a
method added in a later release, such as `flush()` or `forceRecheck()` (1.3.0),
the `Updater` it gets back may come from an older copy that lacks it: check with
`method_exists()` first. A class added later is different: it exists only in
the copies that have it, so it always loads from one of them. That is why the
1.5.0 additions are classes you can register yourself, rather than something
only `Updater::register()` does. Only an actual breaking change needs a new
major, and then the two coexist.

**Writing your own source:** implement `Source`. If your host can answer a
download with the file itself, also implement `DirectDownload` and do the
download there; otherwise `Updater` resolves redirects through
`resolveDownloadUrl()` and lets WordPress fetch the result.

If a plugin ever vendors *third-party* dependencies into its zip, prefix them at
build time with [PHP-Scoper][scoper] — that's the general fix for WordPress's
one-global-namespace problem, and it's a separate concern from this library.

[scoper]: https://github.com/humbug/php-scoper

## Testing an update without shipping one

Tag a throwaway patch release and watch it appear:

```bash
wp eval 'delete_site_transient("wpu_release_" . md5(plugin_basename(WP_PLUGIN_DIR . "/my-plugin/my-plugin.php") . "|bitbucket.org"));'
wp eval 'wp_update_plugins();'
wp plugin list --name=my-plugin --fields=name,version,update
```

Both lines are needed: the first clears our cache, and the second only does
anything once WordPress's own twelve-hour gate has passed — which is why a
plugin exposing `forceRecheck()` behind a button is worth the ten lines.

## Licence

MIT.
