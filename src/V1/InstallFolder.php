<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

/**
 * Installs every update of a plugin into the folder the plugin is already in.
 *
 * WordPress installs an update into the folder its zip names, not the folder
 * the plugin is in (WP_Upgrader::install_package(): the destination is
 * WP_PLUGIN_DIR . basename($source); core's own Plugin_Upgrader still carries
 * the Trac note "move up the directory for zips which are made a bit
 * differently"). When the two differ, the upgrader backs up and removes the old
 * folder by the plugin's basename, puts the new version in a folder WordPress
 * does not know is that plugin, and the plugin is deactivated with "Plugin file
 * does not exist", while the update reports success. Folders drift for ordinary
 * reasons: an archive with no folder inside it gets one named after the zip
 * file (my_plugin.zip, my_plugin-1.zip after a second download; since
 * WordPress 7.1.4, which gives every unpacked package an unpredictable
 * directory, that name plus 16 random characters), a GitHub source archive's
 * is repo-1.2.3, and people rename folders.
 *
 * This renames the unpacked update to the installed folder on
 * `upgrader_source_selection`, before WordPress installs anything. It acts only
 * on updates of a registered plugin (WordPress names the plugin being updated
 * in hook_extra['plugin'], on every update path: Update now, bulk updates,
 * auto-updates and WP-CLI), never on installs, themes or other plugins.
 *
 * OPTIONAL AND SEPARATE FROM Updater, for the same reason as
 * {@see DirectDownload}: several plugins on one site can each ship a copy of
 * V1, and only one copy's Updater class loads. If that copy predates 1.5.0, its
 * register() knows nothing about this class. A plugin that must have the
 * rename calls InstallFolder::register() itself; the class only exists in
 * copies that have it, so it always loads from one of them. Updater::register()
 * in 1.5.0 and later also calls it, so plugins that don't call it still get it
 * whenever a new enough copy wins. Registering twice is harmless.
 *
 * It only works in a request that has loaded the plugin, as every hook does.
 */
final class InstallFolder {

  /** @var array<string, string> Installed folder, keyed by plugin basename. */
  private static array $folders = [];

  /** Rename this plugin's updates to the folder it is in. Safe to call on every request. */
  public static function register(string $pluginFile): void
  {
    $basename = plugin_basename($pluginFile);
    $folder = dirname($basename);

    // A single-file plugin sits in the plugins folder itself; nothing to keep.
    if ($folder === '.' || $folder === '') {
      return;
    }

    self::$folders[$basename] = $folder;
    // WordPress keys a [class, method] callback by its name, so adding it again is a no-op.
    add_filter('upgrader_source_selection', [self::class, 'filterSource'], 10, 4);
  }

  /**
   * `upgrader_source_selection`. Takes whatever WordPress passes, since another
   * filter may have changed it.
   *
   * @param mixed $source       The unpacked folder WordPress will install, or a WP_Error.
   * @param mixed $remoteSource The working folder the package was unpacked into.
   * @param mixed $upgrader     The WP_Upgrader.
   * @param mixed $hookExtra    Names the plugin being updated, on updates.
   * @return mixed The folder to install from, renamed if need be, or a WP_Error.
   */
  public static function filterSource($source, $remoteSource = null, $upgrader = null, $hookExtra = null)
  {
    global $wp_filesystem;

    if (!is_string($source) || !is_string($remoteSource) || !is_array($hookExtra)) {
      return $source;
    }
    $plugin = $hookExtra['plugin'] ?? null;
    if (!is_string($plugin) || !isset(self::$folders[$plugin])) {
      return $source;
    }

    $folder = self::$folders[$plugin];
    if (basename($source) === $folder) {
      return $source;
    }

    // WordPress deactivates a plugin before this point on some update paths
    // (Plugin_Upgrader::upgrade() outside cron), and only reactivates it after
    // a successful update. Hence the second sentence of each message.
    $notInstalled = 'Its files are unchanged. If WordPress deactivated the plugin for the update, activate it again on the Plugins page.';

    // The archive had no folder inside it, so WordPress would install it into
    // one named after the zip file. Refuse rather than guess.
    if (untrailingslashit($source) === untrailingslashit($remoteSource)) {
      return new \WP_Error(
        'wp_updater_no_folder',
        sprintf('The update for %s was not installed: its zip has no folder inside it, so there is no telling where it belongs. %s', $folder, $notInstalled)
      );
    }

    $target = trailingslashit($remoteSource) . $folder;
    if (!is_object($wp_filesystem) || !$wp_filesystem->move(untrailingslashit($source), $target, true)) {
      return new \WP_Error(
        'wp_updater_rename_failed',
        sprintf('The update for %s was not installed: the unpacked update could not be renamed to %s, the folder the plugin is in. %s', $folder, $folder, $notInstalled)
      );
    }

    return trailingslashit($target);
  }
}
