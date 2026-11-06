<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

/**
 * Releases from GitHub **release assets**.
 *
 * Unlike Bitbucket, GitHub has a first-class releases concept, so the build
 * artifact is attached to the release and this installs that asset — never the
 * source zipball, which has no `vendor/`.
 *
 * THREE DIFFERENCES FROM BITBUCKET, all handled here:
 *
 *   1. The VERSION USUALLY LIVES IN THE TAG, not the filename. Plenty of
 *      workflows publish a fixed asset name (`my-plugin.zip`) for every
 *      release. So the artifact pattern only SELECTS the asset; the version
 *      comes from capture group 1 when the pattern provides one, and from the
 *      release's tag_name otherwise.
 *   2. PUBLIC REPOSITORIES NEED NO CREDENTIAL. Construct with $private = false
 *      and the updater will not switch itself off for want of one.
 *   3. A PRIVATE ASSET MAY COME BACK AS THE FILE ITSELF. Its API URL usually
 *      answers with a 302 to signed storage, but GitHub documents that it may
 *      stream the file instead ("API clients should handle both a 200 or 302
 *      response"). So this source downloads private assets itself
 *      ({@see download()}) rather than only resolving a URL.
 *
 * THE ZIP NEEDS A FOLDER: WordPress installs into the zip's top-level
 * directory, and `zip -r plugin.zip .` produces an archive that has none, so
 * the destination is derived from the filename instead. A fresh install then
 * lives in a folder named after the zip. Updates are renamed to the installed
 * folder since 1.5.0 ({@see InstallFolder}), and one with no folder inside is
 * refused. Stage into a directory named exactly like the plugin folder and zip
 * that; see "What your pipeline must produce" in the README. GitHub workflows
 * are likelier to trip on this than Bitbucket ones, which is why it is noted
 * here rather than in the other source.
 */
final class GitHubReleases implements Source, DirectDownload {

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

  public function verifyCredential(string $authorization): array
  {
    if ($this->private && $authorization === '') {
      return ['ok' => false, 'status' => 0, 'message' => 'No credential is configured for a private repository.'];
    }

    $response = wp_safe_remote_get(self::API . $this->repo . '/releases/latest', [
      'timeout' => 15,
      'headers' => array_filter([
        'Accept' => 'application/vnd.github+json',
        'Authorization' => $authorization === '' ? null : $authorization,
      ]),
    ]);

    if (is_wp_error($response)) {
      return ['ok' => false, 'status' => 0, 'message' => 'Could not reach GitHub: ' . $response->get_error_message()];
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status === 200) {
      return ['ok' => true, 'status' => 200, 'message' => 'Authenticated; the latest release is readable.'];
    }
    if ($status === 401) {
      return ['ok' => false, 'status' => 401, 'message' => 'GitHub rejected the credential (401).'];
    }
    if ($status === 403) {
      return ['ok' => false, 'status' => 403, 'message' => 'Forbidden (403) — rate limited, or the token cannot read ' . $this->repo . '.'];
    }
    if ($status === 404) {
      return ['ok' => false, 'status' => 404, 'message' => 'No release found, or the repository is not visible (404): ' . $this->repo . '.'];
    }

    return ['ok' => false, 'status' => $status, 'message' => 'Unexpected response from GitHub (HTTP ' . $status . ').'];
  }

  /**
   * Download a release asset. {@see Updater} uses this rather than
   * resolveDownloadUrl(), so it is the path every update takes.
   *
   * Public assets download straight from their browser URL, with no credential:
   * WordPress follows GitHub's redirect itself, exactly as before this method
   * existed.
   *
   * A private asset is asked for ONCE, through the API with the credential and
   * no redirect-following, and either answer GitHub documents is accepted:
   *   - 200: GitHub streamed the file itself. That response IS the download.
   *   - 3xx: a redirect to a signed, short-lived URL on separate storage. It is
   *     followed WITHOUT the credential: the signature is the authentication,
   *     and sending our header there would hand it to a third party.
   * Anything else is a WP_Error naming the status, because the remedies differ:
   * a 401 is the credential, a 404 usually a release whose file was replaced
   * since this site last checked (a new upload gets a new asset id).
   *
   * @return string|\WP_Error
   */
  public function download(string $url, string $authorization)
  {
    if (!$this->private || $authorization === '') {
      return download_url($url);
    }

    $file = wp_tempnam('release-asset');
    $response = wp_safe_remote_get($url, [
      'timeout' => 300,
      'redirection' => 0,
      'stream' => true,
      'filename' => $file,
      'headers' => [
        'Authorization' => $authorization,
        'Accept' => 'application/octet-stream',
      ],
    ]);

    if (is_wp_error($response)) {
      wp_delete_file($file);

      return new \WP_Error('wp_updater_download_unresolved', 'Could not reach GitHub to download the update: ' . $response->get_error_message());
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status === 200) {
      return $file;
    }
    wp_delete_file($file);

    if ($status >= 300 && $status < 400) {
      $location = wp_remote_retrieve_header($response, 'location');
      if (is_string($location) && $location !== '') {
        return download_url($location);
      }
    }

    return new \WP_Error('wp_updater_download_unresolved', self::downloadFailure($status, $this->repo));
  }

  /**
   * What a failed asset download means, by status. Public and separate from
   * the HTTP call so it can be tested directly.
   */
  public static function downloadFailure(int $status, string $repo): string
  {
    if ($status === 401) {
      return 'GitHub rejected the credential (401) when downloading the update.';
    }
    if ($status === 403) {
      return 'GitHub refused the download (403) — rate limited, or the token cannot read ' . $repo . '.';
    }
    if ($status === 404) {
      return 'GitHub has no such release file (404). It was probably replaced or removed since this site last checked for updates.';
    }
    if ($status >= 300 && $status < 400) {
      return 'GitHub redirected the download (HTTP ' . $status . ') without saying where to.';
    }

    return 'Unexpected response from GitHub when downloading the update (HTTP ' . $status . ').';
  }

  /**
   * The URL-only half of {@see download()}, kept because it is part of the
   * Source contract and callers may use it directly: public assets need no
   * resolving, and a private asset's 302 is resolved here so the credential
   * never reaches the storage host. It cannot handle a 200, which is why the
   * updater calls download() instead.
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
