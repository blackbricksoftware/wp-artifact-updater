<?php

namespace BlackBrickSoftware\WpUpdater\V1;

/**
 * Releases from a Bitbucket repository's **Downloads** section.
 *
 * Downloads is where a pipeline puts build output, which is what we want to
 * install. Bitbucket has no equivalent of GitHub Releases, and a tag archive
 * (`/get/<ref>.zip`) is the repository tree — no `vendor/`, no autoloader — so
 * this deliberately never touches one.
 *
 * PRIVATE REPOSITORIES need a credential for both the listing and the download.
 * Pass an Authorization header value; all three of Bitbucket's schemes work,
 * because the header is built by the caller:
 *   - repository access token (recommended — scoped to ONE repo, read-only,
 *     and not tied to a person's account): `Bearer <token>`
 *   - app password: `Basic base64(user:app-password)`
 *
 * Note there is no Downloads-only scope: `repository` (read) is the finest
 * grant Bitbucket offers, which is why a per-repository token beats an
 * account-wide app password — a leaked one reads a single repo whose code the
 * site already has on disk.
 */
final class BitbucketDownloads implements Source {

  private const API = 'https://api.bitbucket.org/2.0/repositories/';

  private string $repo;
  private bool $private;

  /**
   * @param string $repo    "workspace/repository".
   * @param bool   $private Whether a credential is required (default true).
   */
  public function __construct(string $repo, bool $private = true)
  {
    $this->repo = trim($repo, '/');
    $this->private = $private;
  }

  public function updateHost(): string
  {
    return 'bitbucket.org';
  }

  public function homepage(): string
  {
    return 'https://bitbucket.org/' . $this->repo;
  }

  public function ownsUrl(string $url): bool
  {
    return strpos($url, self::API . $this->repo . '/downloads') === 0;
  }

  public function requiresAuthorization(): bool
  {
    return $this->private;
  }

  public function latestRelease(string $authorization, string $artifactPattern): ?array
  {
    $response = wp_safe_remote_get(
      self::API . $this->repo . '/downloads?pagelen=100',
      [
        'timeout' => 15,
        'headers' => $authorization === '' ? [] : ['Authorization' => $authorization],
      ]
    );

    return self::pickNewest($response, $artifactPattern);
  }

  /**
   * Highest version among the artifacts matching the pattern.
   *
   * Public and separate from the HTTP call so it can be tested directly, and
   * because the listing is not ordered by version — sorting by name would make
   * 2.9.0 beat 2.10.0.
   *
   * @param array|\WP_Error $response
   */
  public static function pickNewest($response, string $artifactPattern): ?array
  {
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
      return null;
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['values']) || !is_array($body['values'])) {
      return null;
    }

    $best = null;
    foreach ($body['values'] as $item) {
      $name = isset($item['name']) ? (string) $item['name'] : '';
      $url = $item['links']['self']['href'] ?? '';
      if ($url === '' || !preg_match($artifactPattern, $name, $matches) || !isset($matches[1])) {
        continue;
      }
      if ($best === null || version_compare(ltrim($matches[1], 'vV'), ltrim($best['version'], 'vV'), '>')) {
        $best = ['version' => $matches[1], 'url' => (string) $url];
      }
    }

    return $best;
  }

  /**
   * Bitbucket answers a Downloads href with a 302 to a signed storage URL. Ask
   * for the redirect without following it, then hand back the signed URL, which
   * carries its own authentication — so our credential never reaches the
   * storage host.
   */
  public function resolveDownloadUrl(string $url, string $authorization): ?string
  {
    $response = wp_safe_remote_get($url, [
      'timeout' => 30,
      'redirection' => 0,
      'headers' => $authorization === '' ? [] : ['Authorization' => $authorization],
    ]);

    if (is_wp_error($response)) {
      return null;
    }

    $location = wp_remote_retrieve_header($response, 'location');

    return is_string($location) && $location !== '' ? $location : null;
  }
}
