<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

/**
 * WordPress updates for a plugin that does not live on wordpress.org.
 *
 * WordPress's updater is pluggable: supply update data for your own plugin file
 * and core does the rest — the update nag, the one-click update, and the
 * per-plugin "Enable auto-updates" toggle all start working normally.
 *
 * WHAT THIS INSTALLS is the release artifact your build published, not a git
 * tag archive. That distinction is the whole reason this library exists: a tag
 * archive has no `vendor/`, so installing one gives you a plugin with no
 * autoloader that fatals on activation. {@see Source} implementations point at
 * real build output.
 *
 * WHAT IT DOES NOT DO:
 *   - Force installation. No `auto_update_plugin` filter is registered, so
 *     updates are offered and each site opts in. For plugins that can lock
 *     people out of a site (authentication, access control), an unattended bad
 *     release across every site at once is the failure worth avoiding.
 *   - Know where your credential lives. You pass a callable; read it from an
 *     environment variable, a constant, your own settings — the library never
 *     guesses, because no two of our plugins store configuration the same way.
 *   - Downgrade. Only a strictly newer version is offered.
 *
 * USAGE — see README.md for the full contract:
 *
 *   Updater::register([
 *     'plugin_file'   => __FILE__,
 *     'source'        => new BitbucketDownloads('workspace/repo'),
 *     'artifact'      => '/^my-plugin-v?(\d+\.\d+\.\d+)\.zip$/',
 *     'authorization' => static fn(): string => getenv('MY_UPDATE_AUTH') ?: '',
 *   ]);
 *
 * The plugin must also carry an `Update URI:` header whose host matches
 * $source->updateHost(), which is what routes core's check here — and, just as
 * usefully, stops wordpress.org pushing a same-slug plugin onto your site.
 */
final class Updater {

  /** @var array<string, self> Registered instances, keyed by plugin basename. */
  private static array $instances = [];

  private string $pluginFile;
  private string $basename;
  private string $slug;
  private Source $source;
  private string $artifact;
  /** @var callable(): string */
  private $authorization;
  /** @var callable(): bool */
  private $enabled;
  private int $ttl;

  private function __construct(array $config)
  {
    $this->pluginFile = (string) $config['plugin_file'];
    $this->basename = plugin_basename($this->pluginFile);
    $this->slug = dirname($this->basename);
    $this->source = $config['source'];
    $this->artifact = (string) $config['artifact'];
    $this->authorization = $config['authorization'] ?? static fn(): string => '';
    $this->ttl = (int) ($config['ttl'] ?? 6 * HOUR_IN_SECONDS);

    // Default switch: a private source with no credential can do nothing, so
    // stay completely inert. A public source needs no credential, so it must
    // not be disabled by the absence of one — pass your own 'enabled' to turn
    // updates off where something else owns the plugin version (composer).
    $this->enabled = $config['enabled'] ?? function (): bool {
      return !$this->source->requiresAuthorization() || $this->credential() !== '';
    };
  }

  /**
   * Register update checking for one plugin. Safe to call on every request;
   * cheap, and does nothing at all when disabled.
   *
   * @param array{plugin_file: string, source: Source, artifact: string,
   *              authorization?: callable, enabled?: callable, ttl?: int} $config
   */
  public static function register(array $config): ?self
  {
    foreach (['plugin_file', 'source', 'artifact'] as $required) {
      if (empty($config[$required])) {
        return null;
      }
    }
    if (!($config['source'] instanceof Source)) {
      return null;
    }

    $updater = new self($config);
    if (!($updater->enabled)()) {
      return null;
    }

    self::$instances[$updater->basename] = $updater;
    $updater->addHooks();

    return $updater;
  }

  /**
   * Check a credential against its source, without registering anything.
   *
   * For consumers that want a "test this token" button, and for validating a
   * credential at the moment it is entered rather than discovering months later
   * that updates quietly stopped. Makes one cheap request and never throws.
   *
   * @return array{ok: bool, status: int, message: string}
   */
  public static function verify(Source $source, string $authorization): array
  {
    return $source->verifyCredential(trim($authorization));
  }

  private function addHooks(): void
  {
    // WP 5.8+ asks only about plugins whose Update URI matches this host, so we
    // are never consulted about anyone else's plugin. Older sites get the
    // whole-transient filter instead.
    if (version_compare(get_bloginfo('version'), '5.8', '>=')) {
      add_filter('update_plugins_' . $this->source->updateHost(), [$this, 'filterUpdate'], 10, 3);
    }
    else {
      add_filter('site_transient_update_plugins', [$this, 'filterLegacyTransient']);
    }

    add_filter('upgrader_pre_download', [$this, 'preDownload'], 10, 2);
    add_filter('plugins_api', [$this, 'filterPluginsApi'], 10, 3);
  }

  // --- WordPress integration ---------------------------------------------------

  /**
   * `update_plugins_{$hostname}` — core's per-plugin question on WP 5.8+.
   *
   * @param mixed  $update     false, or another callback's answer.
   * @param array  $pluginData Headers from the plugin file.
   * @param string $pluginFile Plugin basename.
   * @return mixed
   */
  public function filterUpdate($update, $pluginData, $pluginFile)
  {
    if ($pluginFile !== $this->basename) {
      return $update;
    }

    // Return the release even when it is NOT newer. Core compares versions
    // itself (update.php: "if version_compare(new_version, Version, '>')
    // response, else no_update") — and the no_update entry is what makes
    // WordPress consider the plugin updatable at all: without it the Plugins
    // screen shows no "Enable auto-updates" link, because
    // WP_Plugins_List_Table only sets update-supported for plugins present in
    // one of those two lists. Returning false here meant a plugin that was up
    // to date looked like one that could never be updated.
    $release = $this->latestRelease();

    return $release === null ? $update : [
      'slug' => $this->slug,
      'plugin' => $this->basename,
      'version' => $release['version'],
      'url' => $this->source->homepage(),
      'package' => $release['url'],
    ];
  }

  /**
   * `site_transient_update_plugins` — the pre-5.8 path, same decision written
   * into the transient core already built.
   *
   * @param mixed $transient
   * @return mixed
   */
  public function filterLegacyTransient($transient)
  {
    if (!is_object($transient) || !isset($transient->response) || !is_array($transient->response)) {
      return $transient;
    }

    $release = $this->latestRelease();
    if ($release === null) {
      return $transient;
    }

    // Pre-5.8 there is no core placement logic for us, so do it here: newer
    // goes in response, anything else in no_update — which is what keeps the
    // auto-updates toggle visible on an up-to-date site.
    $newer = version_compare(self::normalize($release['version']), self::normalize($this->installedVersion()), '>');
    if (!$newer && !isset($transient->no_update)) {
      $transient->no_update = [];
    }

    $entry = (object) [
      'id' => $this->source->updateHost() . '/' . $this->slug,
      'slug' => $this->slug,
      'plugin' => $this->basename,
      'new_version' => $release['version'],
      'url' => $this->source->homepage(),
      'package' => $release['url'],
    ];

    if ($newer) {
      $transient->response[$this->basename] = $entry;
    }
    else {
      $transient->no_update[$this->basename] = $entry;
    }

    return $transient;
  }

  /**
   * `upgrader_pre_download` — let the source resolve its own authenticated
   * redirect, so a credential is never sent to third-party storage.
   *
   * @param mixed  $reply   false to let WordPress download normally.
   * @param string $package Package URL.
   * @return mixed false, a local file path, or WP_Error.
   */
  public function preDownload($reply, $package)
  {
    if ($reply !== false || !$this->source->ownsUrl((string) $package)) {
      return $reply;
    }

    $resolved = $this->source->resolveDownloadUrl((string) $package, $this->credential());
    if ($resolved === null) {
      return new \WP_Error(
        'wp_updater_download_unresolved',
        sprintf(
          /* translators: %s: plugin name. */
          __('Could not resolve the download for %s. Check its update credential.', 'wp-artifact-updater'),
          $this->slug
        )
      );
    }

    if (!function_exists('download_url')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    return download_url($resolved);
  }

  /**
   * `plugins_api` — answer the "View details" modal ourselves; without this
   * core asks wordpress.org about a plugin it has never heard of.
   *
   * @param mixed  $result
   * @param string $action
   * @param object $args
   * @return mixed
   */
  public function filterPluginsApi($result, $action, $args)
  {
    if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
      return $result;
    }

    $headers = $this->pluginHeaders();
    $release = $this->latestRelease();

    return (object) [
      'name' => $headers['Name'] !== '' ? $headers['Name'] : $this->slug,
      'slug' => $this->slug,
      'version' => $release['version'] ?? $headers['Version'],
      'author' => $headers['Author'],
      'homepage' => $this->source->homepage(),
      'download_link' => $release['url'] ?? '',
      'sections' => [
        'description' => $headers['Description'],
      ],
    ];
  }

  // --- internals ----------------------------------------------------------------

  /**
   * The newest release, cached.
   *
   * Core checks for updates often and this is a network call, so a hit is
   * cached for the configured TTL and a miss for a short while — a release host
   * having a bad minute must not mean a request on every admin page load.
   */
  private function latestRelease(): ?array
  {
    $key = 'wpu_release_' . md5($this->basename . '|' . $this->source->updateHost());

    $cached = get_site_transient($key);
    if (is_array($cached)) {
      return $cached['version'] === '' ? null : $cached;
    }

    $release = $this->source->latestRelease($this->credential(), $this->artifact);
    set_site_transient(
      $key,
      $release ?? ['version' => '', 'url' => ''],
      $release ? $this->ttl : 15 * MINUTE_IN_SECONDS
    );

    return $release;
  }

  /** Authorization header value, or '' when there is none. */
  private function credential(): string
  {
    $value = ($this->authorization)();

    return is_string($value) ? trim($value) : '';
  }

  /**
   * Compare versions without tripping over a leading "v".
   *
   * PHP reads a `v` prefix as a pre-release-ish string part, so
   * version_compare('v2.0.0', '2.0.0') is -1 — a plugin whose header carries
   * the prefix would be offered "2.0.0" forever. Strip it from both sides.
   */
  private static function normalize(string $version): string
  {
    return ltrim(trim($version), 'vV');
  }

  /** Version from the plugin's own header. */
  private function installedVersion(): string
  {
    $version = $this->pluginHeaders()['Version'];

    return $version !== '' ? $version : '0.0.0';
  }

  /**
   * Plugin headers, read with get_file_data rather than get_plugin_data: this
   * runs outside wp-admin too, where that function isn't loaded.
   *
   * @return array{Name: string, Version: string, Author: string, Description: string}
   */
  private function pluginHeaders(): array
  {
    static $cache = [];

    if (!isset($cache[$this->pluginFile])) {
      $headers = get_file_data($this->pluginFile, [
        'Name' => 'Plugin Name',
        'Version' => 'Version',
        'Author' => 'Author',
        'Description' => 'Description',
      ], 'plugin');
      $cache[$this->pluginFile] = array_map(static fn($v) => is_string($v) ? $v : '', $headers);
    }

    return $cache[$this->pluginFile];
  }
}
