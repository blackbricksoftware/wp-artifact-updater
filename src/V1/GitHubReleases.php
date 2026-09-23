<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

/**
 * Releases from GitHub **release assets**.
 *
 * Unlike Bitbucket, GitHub has a first-class releases concept, so the build
 * artifact is attached to the release and this installs that asset — never the
 * source zipball, which has no `vendor/`.
 *
 * TWO DIFFERENCES FROM BITBUCKET, both handled here:
 *
 *   1. The VERSION USUALLY LIVES IN THE TAG, not the filename. Plenty of
 *      workflows publish a fixed asset name (`my-plugin.zip`) for every
 *      release. So the artifact pattern only SELECTS the asset; the version
 *      comes from capture group 1 when the pattern provides one, and from the
 *      release's tag_name otherwise.
 *   2. PUBLIC REPOSITORIES NEED NO CREDENTIAL. Construct with $private = false
 *      and the updater will not switch itself off for want of one.
 *
 * A HAZARD NO UPDATER CAN FIX: WordPress installs into the zip's top-level
 * directory, and `zip -r plugin.zip .` produces an archive that has none — the
 * destination then gets derived from the filename instead. Where that differs
 * from the existing plugin folder, the "update" lands beside it as a second
 * copy and looks like it worked. Stage into a directory named exactly like the
 * plugin folder and zip that; see "What your pipeline must produce" in the
 * README. GitHub workflows are likelier to trip on this than Bitbucket ones,
 * which is why it is noted here rather than in the other source.
 */
final class GitHubReleases implements Source {

  private const API = 'https://api.github.com/repos/';

  private string $repo;
  private bool $private;

  /**
   * @param string $repo    "owner/repository".
   * @param bool   $private Whether a credential is required (default false —
   *                        GitHub plugins of ours are public).
   */
  public function __construct(string $repo, bool $private = false)
  {
    $this->repo = trim($repo, '/');
    $this->private = $private;
  }

  public function updateHost(): string
  {
    return 'github.com';
  }

  public function homepage(): string
  {
    return 'https://github.com/' . $this->repo;
  }

  public function ownsUrl(string $url): bool
  {
    return strpos($url, self::API . $this->repo . '/releases/assets/') === 0
      || strpos($url, 'https://github.com/' . $this->repo . '/releases/download/') === 0;
  }

  public function requiresAuthorization(): bool
  {
    return $this->private;
  }

  public function latestRelease(string $authorization, string $artifactPattern): ?array
  {
    $response = wp_safe_remote_get(self::API . $this->repo . '/releases/latest', [
      'timeout' => 15,
      'headers' => array_filter([
        'Accept' => 'application/vnd.github+json',
        'Authorization' => $authorization === '' ? null : $authorization,
      ]),
    ]);

    return self::pickAsset($response, $artifactPattern, $this->private);
  }

  /**
   * The matching asset of the latest release.
   *
   * Public and separate from the HTTP call so it can be tested directly.
   *
   * @param array|\WP_Error $response
   */
  public static function pickAsset($response, string $artifactPattern, bool $private = false): ?array
  {
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
      return null;
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['assets']) || !is_array($body['assets'])) {
      return null;
    }

    $tag = isset($body['tag_name']) ? (string) $body['tag_name'] : '';

    foreach ($body['assets'] as $asset) {
      $name = isset($asset['name']) ? (string) $asset['name'] : '';
      if ($name === '' || !preg_match($artifactPattern, $name, $matches)) {
        continue;
      }

      // Version from the filename when the pattern captured one, else the tag.
      $version = $matches[1] ?? $tag;
      if ($version === '') {
        continue;
      }

      // A private asset must be fetched through the API with an octet-stream
      // Accept header; a public one can be downloaded directly.
      $url = $private
        ? (string) ($asset['url'] ?? '')
        : (string) ($asset['browser_download_url'] ?? $asset['url'] ?? '');
      if ($url === '') {
        continue;
      }

      return ['version' => $version, 'url' => $url];
    }

    return null;
  }

  /**
   * Public assets download straight from their browser URL, so there is nothing
   * to resolve. A private asset's API URL answers with a 302 to signed storage;
   * resolve that ourselves so the credential never reaches the storage host.
   */
  public function resolveDownloadUrl(string $url, string $authorization): ?string
  {
    if (!$this->private || $authorization === '') {
      return $url;
    }

    $response = wp_safe_remote_get($url, [
      'timeout' => 30,
      'redirection' => 0,
      'headers' => [
        'Authorization' => $authorization,
        'Accept' => 'application/octet-stream',
      ],
    ]);

    if (is_wp_error($response)) {
      return null;
    }

    $location = wp_remote_retrieve_header($response, 'location');

    return is_string($location) && $location !== '' ? $location : null;
  }
}
