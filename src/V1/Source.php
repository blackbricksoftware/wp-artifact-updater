<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

/**
 * Where releases come from.
 *
 * Hosts differ in only a few ways — how you list releases, whether the version
 * is in the filename or the metadata, whether a credential is needed, and how
 * the real download URL is reached. Everything after that (comparing versions,
 * telling WordPress, caching) is identical and lives in {@see Updater}.
 *
 * Implementations MUST NOT throw. A release host having a bad minute is normal;
 * it is not worth a fatal on someone's admin screen. Return null and let the
 * site carry on with the version it has.
 */
interface Source {

  /**
   * The newest release available, or null when there is none or the lookup
   * failed.
   *
   * @param string $authorization Authorization header value; '' when none.
   * @param string $artifactPattern PCRE matching the release filename, version
   *                                in capture group 1. Hosts whose filenames
   *                                carry no version take it from metadata and
   *                                use this only to pick the right asset.
   * @return array{version: string, url: string}|null
   */
  public function latestRelease(string $authorization, string $artifactPattern): ?array;

  /**
   * Hostname for the plugin's `Update URI:` header — this is what routes core's
   * update check to us on WP 5.8+ (`update_plugins_{$hostname}`).
   */
  public function updateHost(): string;

  /** Human-facing URL for the "View details" modal. */
  public function homepage(): string;

  /**
   * Is this a URL we produced? Guards the download filter so we never intercept
   * another plugin's package.
   */
  public function ownsUrl(string $url): bool;

  /**
   * Does this source need a credential at all? False for public repositories,
   * which must keep updating without one.
   */
  public function requiresAuthorization(): bool;

  /**
   * Turn a release URL into one WordPress can download, resolving any
   * authenticated redirect first.
   *
   * Release hosts answer an API download URL with a 302 to a signed,
   * short-lived URL on separate storage. Following that with our Authorization
   * header attached would hand the credential to a third party AND be rejected
   * by it (the signature is the auth), so the source resolves its own redirect
   * and returns a URL that needs no credential.
   *
   * @return string|null Null when it could not be resolved.
   */
  public function resolveDownloadUrl(string $url, string $authorization): ?string;
}
