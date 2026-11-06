<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

/**
 * Makes Dashboard > Updates > "Check again" really ask the release host.
 *
 * "Check again" (update-core.php?force-check=1) has WordPress check for plugin
 * updates straight away, but the answer for a registered plugin comes from
 * Updater's release cache, which is kept for 6 hours. So a release published
 * minutes ago stays invisible however often the button is pressed. On that
 * request this calls {@see Updater::forceRecheck()} for every registered
 * updater, before WordPress's own check (load-update-core.php, priority 9;
 * core's wp_update_plugins() runs at 10).
 *
 * Only for a user who can update plugins: the flag itself is unauthenticated,
 * as it is for WordPress, but forgetting the cache costs a request to the host.
 *
 * OPTIONAL AND SEPARATE FROM Updater, like {@see InstallFolder}, and for the
 * same reason. The updater it is given may come from an older V1 copy shipped
 * by another plugin, one that predates forceRecheck() (1.3.0), so the method is
 * looked for rather than assumed. Without it nothing is forgotten, and
 * WordPress's own check still runs, answered from the cache.
 */
final class CheckAgain {

  /** @var array<int, object> Registered updaters, keyed by object id. */
  private static array $updaters = [];

  /**
   * Have "Check again" forget this updater's cache. Safe to call on every
   * request, and with the null Updater::register() returns when it is off.
   *
   * @param object|null $updater An Updater, from whichever V1 copy loaded.
   */
  public static function register(?object $updater): void
  {
    if ($updater === null) {
      return;
    }

    self::$updaters[spl_object_id($updater)] = $updater;
    // WordPress keys a [class, method] callback by its name, so adding it again is a no-op.
    add_action('load-update-core.php', [self::class, 'onLoad'], 9, 0);
  }

  /** `load-update-core.php`. */
  public static function onLoad(): void
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the same unauthenticated flag WordPress reads.
    if (empty($_GET['force-check']) || !current_user_can('update_plugins')) {
      return;
    }

    foreach (self::$updaters as $updater) {
      if (method_exists($updater, 'forceRecheck')) {
        $updater->forceRecheck();
      }
    }
  }
}
