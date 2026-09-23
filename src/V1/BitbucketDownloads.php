<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

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
   * Ask Bitbucket for one download entry and report what came back.
   *
   * NOTE ON TOKEN FORM: a repository access token goes in as
   * `Bearer <token>`. The `x-token-auth:<token>` form is for git over HTTPS,
   * NOT the REST API — verified against a live repository, where Bearer
   * returns 200 and Basic base64("x-token-auth:<token>") returns 401.
   */
  public function verifyCredential(string $authorization): array
  {
    if ($this->private && $authorization === '') {
      return ['ok' => false, 'status' => 0, 'message' => 'No credential is configured for a private repository.'];
    }

    $response = wp_safe_remote_get(
      self::API . $this->repo . '/downloads?pagelen=1',
      [
        'timeout' => 15,
        'headers' => $authorization === '' ? [] : ['Authorization' => $authorization],
      ]
    );

    return self::describe($response, $this->repo);
  }

  /**
   * Turn a response into a verdict a human can act on. Public and static so it
   * can be tested without the network.
   *
   * @param array|\WP_Error $response
   * @return array{ok: bool, status: int, message: string}
   */
  public static function describe($response, string $repo): array
  {
    if (is_wp_error($response)) {
      return ['ok' => false, 'status' => 0, 'message' => 'Could not reach Bitbucket: ' . $response->get_error_message()];
    }

    $status = (int) wp_remote_retrieve_response_code($response);

    if ($status === 200) {
      return ['ok' => true, 'status' => 200, 'message' => 'Authenticated; the repository Downloads are readable.'];
    }
    if ($status === 401) {
      return ['ok' => false, 'status' => 401, 'message' => 'Bitbucket rejected the credential (401). If this is a repository access token, paste the token on its own — the x-token-auth: form is for git, not the API.'];
    }
    if ($status === 403) {
      return ['ok' => false, 'status' => 403, 'message' => 'Authenticated, but not allowed to read this repository (403). The token needs "repository" read on ' . $repo . '.'];
    }
    if ($status === 404) {
      return ['ok' => false, 'status' => 404, 'message' => 'Repository not found (404): ' . $repo . '. Check the workspace/repository name, or the token may not see it.'];
    }

    return ['ok' => false, 'status' => $status, 'message' => 'Unexpected response from Bitbucket (HTTP ' . $status . ').'];
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
