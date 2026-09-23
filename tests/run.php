<?php
/**
 * Dependency-free test run: `php tests/run.php`.
 *
 * Stubs only the WordPress functions the library touches. No WordPress, no
 * PHPUnit — this has to stay runnable in a pipeline whose image is bare PHP.
 */

namespace {
  define('HOUR_IN_SECONDS', 3600);
  define('MINUTE_IN_SECONDS', 60);
  define('ABSPATH', '/tmp/');

  class WP_Error { public function __construct(public string $code = '', public string $message = '') {} }
  function is_wp_error($thing) { return $thing instanceof WP_Error; }
  function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
  function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
  function wp_remote_retrieve_header($r, $h) { return $r['headers'][$h] ?? ''; }
  function wp_safe_remote_get($url, $args = []) { $GLOBALS['REQUESTS'][] = ['url' => $url, 'args' => $args]; return ($GLOBALS['HTTP'])($url, $args); }
  function get_site_transient($k) { return $GLOBALS['TRANSIENTS'][$k] ?? false; }
  function set_site_transient($k, $v, $t = 0) { $GLOBALS['TRANSIENTS'][$k] = $v; $GLOBALS['TTL'][$k] = $t; return true; }
  function delete_site_transient($k) { unset($GLOBALS['TRANSIENTS'][$k]); }
  function plugin_basename($f) { return 'my-plugin/my-plugin.php'; }
  function get_bloginfo($what) { return $GLOBALS['WP_VERSION'] ?? '6.4'; }
  function add_filter($hook, $cb, $p = 10, $n = 1) { $GLOBALS['HOOKS'][] = $hook; }
  function get_file_data($file, $fields, $ctx = '') { return $GLOBALS['HEADERS'] ?? ['Name' => 'My Plugin', 'Version' => '1.0.0', 'Author' => 'Acme', 'Description' => 'Does things.']; }
  function download_url($u) { $GLOBALS['DOWNLOADED'] = $u; return '/tmp/pkg.zip'; }
  function __($s, $d = null) { return $s; }
}

namespace BlackBrickSoftware\WpUpdater\V1 {
  require __DIR__ . '/../src/V1/Source.php';
  require __DIR__ . '/../src/V1/Updater.php';
  require __DIR__ . '/../src/V1/BitbucketDownloads.php';
  require __DIR__ . '/../src/V1/GitHubReleases.php';

  $fails = 0;
  function chk(string $label, bool $ok): void { global $fails; if (!$ok) { $fails++; } echo ($ok ? "PASS " : "FAIL ") . $label . "\n"; }
  function reset_state(): void {
    $GLOBALS['TRANSIENTS'] = []; $GLOBALS['REQUESTS'] = []; $GLOBALS['HOOKS'] = [];
    $GLOBALS['HEADERS'] = ['Name' => 'My Plugin', 'Version' => '1.0.0', 'Author' => 'Acme', 'Description' => 'Does things.'];
    $GLOBALS['WP_VERSION'] = '6.4'; unset($GLOBALS['DOWNLOADED']);
  }
  function bb_listing(array $names): array {
    $values = [];
    foreach ($names as $n) { $values[] = ['name' => $n, 'links' => ['self' => ['href' => 'https://api.bitbucket.org/2.0/repositories/acme/my-plugin/downloads/' . $n]]]; }
    return ['response' => ['code' => 200], 'body' => json_encode(['values' => $values])];
  }
  function gh_release(string $tag, array $assets): array {
    $a = [];
    foreach ($assets as $n) { $a[] = ['name' => $n, 'url' => 'https://api.github.com/repos/acme/my-plugin/releases/assets/9', 'browser_download_url' => 'https://github.com/acme/my-plugin/releases/download/' . $tag . '/' . $n]; }
    return ['response' => ['code' => 200], 'body' => json_encode(['tag_name' => $tag, 'assets' => $a])];
  }
  function register(array $over = []) {
    return Updater::register($over + [
      'plugin_file' => '/plugins/my-plugin/my-plugin.php',
      'source' => new BitbucketDownloads('acme/my-plugin'),
      'artifact' => '/^my-plugin-v?(\d+\.\d+\.\d+)\.zip$/',
      'authorization' => static fn(): string => 'Bearer tok',
    ]);
  }
  $PATTERN = '/^my-plugin-v?(\d+\.\d+\.\d+)\.zip$/';

  echo "== Bitbucket: picking a release\n";
  chk('highest version wins, not list order', BitbucketDownloads::pickNewest(bb_listing(['my-plugin-v2.9.0.zip', 'my-plugin-v2.10.0.zip', 'my-plugin-v1.0.0.zip']), $PATTERN)['version'] === '2.10.0');
  chk('unrelated files ignored', BitbucketDownloads::pickNewest(bb_listing(['readme.txt', 'other-plugin-v9.9.9.zip', 'my-plugin-v1.2.3.zip']), $PATTERN)['version'] === '1.2.3');
  chk('empty listing -> null', BitbucketDownloads::pickNewest(bb_listing([]), $PATTERN) === null);
  chk('404 -> null', BitbucketDownloads::pickNewest(['response' => ['code' => 404], 'body' => ''], $PATTERN) === null);
  chk('WP_Error -> null', BitbucketDownloads::pickNewest(new \WP_Error('x'), $PATTERN) === null);
  chk('malformed body -> null', BitbucketDownloads::pickNewest(['response' => ['code' => 200], 'body' => 'not json'], $PATTERN) === null);

  echo "== GitHub: picking an asset\n";
  chk('version from the tag when the filename has none', GitHubReleases::pickAsset(gh_release('v2.0.0', ['my_plugin.zip']), '/^my_plugin\.zip$/')['version'] === 'v2.0.0');
  chk('version from the filename when captured', GitHubReleases::pickAsset(gh_release('v2.0.0', ['my-plugin-v2.0.1.zip']), $PATTERN)['version'] === '2.0.1');
  chk('public asset uses the browser URL', strpos(GitHubReleases::pickAsset(gh_release('v1.0.0', ['my_plugin.zip']), '/^my_plugin\.zip$/')['url'], 'https://github.com/') === 0);
  chk('private asset uses the API URL', strpos(GitHubReleases::pickAsset(gh_release('v1.0.0', ['my_plugin.zip']), '/^my_plugin\.zip$/', true)['url'], 'https://api.github.com/') === 0);
  chk('no matching asset -> null', GitHubReleases::pickAsset(gh_release('v1.0.0', ['source.tar.gz']), $PATTERN) === null);

  echo "== offering the update\n";
  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => bb_listing(['my-plugin-v2.0.0.zip']);
  $u = register();
  $offer = $u->filterUpdate(false, ['Version' => '1.0.0'], 'my-plugin/my-plugin.php');
  chk('newer release is offered', is_array($offer) && $offer['version'] === '2.0.0');
  chk('credential sent as a header, verbatim', ($GLOBALS['REQUESTS'][0]['args']['headers']['Authorization'] ?? '') === 'Bearer tok');
  chk('same version -> nothing', $u->filterUpdate(false, ['Version' => '2.0.0'], 'my-plugin/my-plugin.php') === false);
  chk('newer installed -> no downgrade', $u->filterUpdate(false, ['Version' => '3.0.0'], 'my-plugin/my-plugin.php') === false);
  chk('another plugin -> untouched', $u->filterUpdate(false, ['Version' => '0.1'], 'other/other.php') === false);
  chk('one HTTP call for four checks (cached)', count($GLOBALS['REQUESTS']) === 1);
  chk('a "v" prefix does not cause a phantom update', $u->filterUpdate(false, ['Version' => 'v2.0.0'], 'my-plugin/my-plugin.php') === false);

  echo "== hooks and the off switch\n";
  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => bb_listing([]);
  chk('private + no credential -> not registered, no hooks', register(['authorization' => static fn(): string => '']) === null && $GLOBALS['HOOKS'] === []);
  reset_state();
  chk('private + no credential -> no HTTP at all', $GLOBALS['REQUESTS'] === []);
  reset_state();
  chk('public source registers without a credential', register(['source' => new GitHubReleases('acme/my-plugin'), 'authorization' => static fn(): string => '']) !== null);
  reset_state();
  chk('explicit enabled=false wins', register(['enabled' => static fn(): bool => false]) === null);
  reset_state();
  register();
  chk('WP 5.8+ uses the Update URI hook', in_array('update_plugins_bitbucket.org', $GLOBALS['HOOKS'], true));
  reset_state();
  $GLOBALS['WP_VERSION'] = '5.7';
  register();
  chk('WP < 5.8 falls back to the transient filter', in_array('site_transient_update_plugins', $GLOBALS['HOOKS'], true));
  reset_state();
  chk('missing config -> null, no fatal', register(['source' => null]) === null && Updater::register([]) === null);

  echo "== downloading\n";
  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => ['response' => ['code' => 302], 'headers' => ['location' => 'https://bbuseruploads.s3.amazonaws.com/signed?sig=abc'], 'body' => ''];
  $u = register();
  $path = $u->preDownload(false, 'https://api.bitbucket.org/2.0/repositories/acme/my-plugin/downloads/my-plugin-v2.0.0.zip');
  chk('returns a local path', $path === '/tmp/pkg.zip');
  chk('the signed URL is what gets downloaded', ($GLOBALS['DOWNLOADED'] ?? '') === 'https://bbuseruploads.s3.amazonaws.com/signed?sig=abc');
  chk('credential never sent to storage', count($GLOBALS['REQUESTS']) === 1 && strpos($GLOBALS['REQUESTS'][0]['url'], 'https://api.bitbucket.org/') === 0);
  chk('asked without following the redirect', ($GLOBALS['REQUESTS'][0]['args']['redirection'] ?? null) === 0);
  chk('someone else\'s package is left alone', $u->preDownload(false, 'https://example.org/other.zip') === false);
  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => ['response' => ['code' => 200], 'headers' => [], 'body' => ''];
  $u = register();
  chk('no redirect -> WP_Error, not a broken install', $u->preDownload(false, 'https://api.bitbucket.org/2.0/repositories/acme/my-plugin/downloads/x.zip') instanceof \WP_Error);
  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => gh_release('v1.0.0', ['my_plugin.zip']);
  $gh = register(['source' => new GitHubReleases('acme/my-plugin'), 'artifact' => '/^my_plugin\.zip$/']);
  chk('public GitHub download needs no resolving', $gh->preDownload(false, 'https://github.com/acme/my-plugin/releases/download/v1.0.0/my_plugin.zip') === '/tmp/pkg.zip');

  echo "== details modal\n";
  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => bb_listing(['my-plugin-v2.0.0.zip']);
  $u = register();
  $info = $u->filterPluginsApi(false, 'plugin_information', (object) ['slug' => 'my-plugin']);
  chk('answers for our slug', is_object($info) && $info->name === 'My Plugin' && $info->version === '2.0.0');
  chk('leaves other slugs to wordpress.org', $u->filterPluginsApi(false, 'plugin_information', (object) ['slug' => 'akismet']) === false);


  echo "== public/private symmetry on both hosts\n";
  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => bb_listing(['my-plugin-v3.0.0.zip']);
  $pub = register(['source' => new BitbucketDownloads('acme/my-plugin', false), 'authorization' => static fn(): string => '']);
  chk('public Bitbucket registers with no credential', $pub !== null);
  $offer = $pub->filterUpdate(false, ['Version' => '1.0.0'], 'my-plugin/my-plugin.php');
  chk('public Bitbucket still offers updates', is_array($offer) && $offer['version'] === '3.0.0');
  chk('no Authorization header sent when public', !isset($GLOBALS['REQUESTS'][0]['args']['headers']['Authorization']));

  reset_state();
  $GLOBALS['HTTP'] = fn($u, $a) => ['response' => ['code' => 302], 'headers' => ['location' => 'https://objects.githubusercontent.com/signed?sig=z'], 'body' => ''];
  $privGh = register(['source' => new GitHubReleases('acme/my-plugin', true), 'artifact' => '/^my_plugin\.zip$/', 'authorization' => static fn(): string => 'Bearer ghtok']);
  chk('private GitHub registers with a credential', $privGh !== null);
  $path = $privGh->preDownload(false, 'https://api.github.com/repos/acme/my-plugin/releases/assets/9');
  chk('private GitHub resolves its redirect', $path === '/tmp/pkg.zip' && ($GLOBALS['DOWNLOADED'] ?? '') === 'https://objects.githubusercontent.com/signed?sig=z');
  chk('private GitHub asks with octet-stream and no follow', ($GLOBALS['REQUESTS'][0]['args']['headers']['Accept'] ?? '') === 'application/octet-stream' && ($GLOBALS['REQUESTS'][0]['args']['redirection'] ?? null) === 0);
  chk('credential never reaches the storage host', count($GLOBALS['REQUESTS']) === 1);

  reset_state();
  chk('private GitHub with no credential -> inert', register(['source' => new GitHubReleases('acme/my-plugin', true), 'authorization' => static fn(): string => '']) === null);

  echo "\n" . ($fails ? "$fails FAILED" : 'ALL PASS') . "\n";
  exit($fails ? 1 : 0);
}
