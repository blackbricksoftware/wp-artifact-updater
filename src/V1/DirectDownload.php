<?php

namespace BlackBrickSoftware\WpArtifactUpdater\V1;

/**
 * A {@see Source} that downloads its own artifacts, rather than only resolving
 * a URL for WordPress to fetch.
 *
 * OPTIONAL, and deliberately separate from Source, so that implementations
 * written against Source keep working unchanged: {@see Updater} calls
 * download() when a source implements this interface, and
 * Source::resolveDownloadUrl() otherwise.
 *
 * It exists because a release host may answer a download URL with the file
 * itself rather than with a redirect. GitHub's release-asset API documents
 * both ("API clients should handle both a 200 or 302 response"), and a source
 * that can only hand back a URL has no way to deal with the 200.
 *
 * Implementations MUST NOT throw, and must never send the credential anywhere
 * but the release host's own API (see Source::resolveDownloadUrl()). Failures
 * are a WP_Error saying what happened, so the admin who reads it is pointed at
 * the right fix: a rejected credential and a release whose file has gone are
 * remedied differently.
 */
interface DirectDownload {

  /**
   * Download the artifact behind a URL this source produced.
   *
   * @param string $url           A URL for which ownsUrl() is true.
   * @param string $authorization Authorization header value; '' when none.
   * @return string|\WP_Error Path to the downloaded file, or why it could not
   *                          be downloaded.
   */
  public function download(string $url, string $authorization);
}
