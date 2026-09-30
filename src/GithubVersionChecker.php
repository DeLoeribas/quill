<?php

declare(strict_types=1);

/**
 * Decides whether a newer release is available by comparing the local
 * src/version.json to the same file on GitHub's main branch. Only a higher version
 * number counts: an install that is equal to or ahead of GitHub (e.g. deployed before
 * pushing) never gets the notice. Earlier versions compared commits and then file
 * hashes, which could only tell "different", not "newer", and so kept offering
 * updates that weren't.
 *
 * Fetched from raw.githubusercontent.com: one small file, not subject to the API rate limit.
 * A manual "Check for updates" asks the contents API instead (see remoteVersion()).
 */
final class GithubVersionChecker
{
    private const MAX_RESPONSE_BYTES = 16384;

    /**
     * Returns ['version' => the running version, 'latest' => the newer version on
     * GitHub or null if up to date / unknown / GitHub unreachable, 'notes' => that
     * newer version's one-line release notes, if any]. $force skips the cache, for
     * the manual check in Settings.
     */
    public static function status(bool $force = false): array
    {
        $upToDate = ['version' => APP_VERSION, 'latest' => null, 'notes' => null];

        $remote = self::remoteVersion($force);
        if ($remote === null || $remote['version'] === null || APP_VERSION === 'unknown') {
            return $upToDate;
        }

        if (version_compare($remote['version'], APP_VERSION, '>')) {
            return ['version' => APP_VERSION, 'latest' => $remote['version'], 'notes' => $remote['notes']];
        }

        return $upToDate;
    }

    /** @return array{version: ?string, notes: ?string}|null */
    private static function remoteVersion(bool $force): ?array
    {
        $empty = ['version' => null, 'notes' => null, 'checked_at' => null];
        $cache = Storage::read(GITHUB_VERSION_CACHE_FILE, $empty);
        $checkedAt = $cache['checked_at'] ?? null;
        // A cache written by the old commit-based checker has no 'version' key — treat it as stale.
        $stale = $force
            || $checkedAt === null
            || !array_key_exists('version', $cache)
            || (time() - strtotime((string) $checkedAt)) >= GITHUB_VERSION_CACHE_SECONDS;

        if (!$stale) {
            return ['version' => $cache['version'], 'notes' => $cache['notes'] ?? null];
        }

        $raw = null;
        if ($force) {
            // raw.githubusercontent.com sits behind a CDN that serves a file up to 5 minutes
            // old, which defeats a manual check right after a push. The contents API is fresh
            // within a minute but rate-limited (60/hour per IP), so only a manual check uses it,
            // falling back to raw if it's refused.
            $raw = self::fetch(
                'https://api.github.com/repos/' . GITHUB_REPO . '/contents/src/version.json?ref=main',
                ['Accept: application/vnd.github.raw']
            );
        }
        $raw ??= self::fetch('https://raw.githubusercontent.com/' . GITHUB_REPO . '/main/src/version.json');
        // Keep the last-known-good result if GitHub is unreachable right now, but still
        // bump checked_at so we don't retry on every single request while it's down.
        $result = $raw !== null
            ? parse_version_json($raw)
            : ['version' => $cache['version'] ?? null, 'notes' => $cache['notes'] ?? null];

        Storage::update(GITHUB_VERSION_CACHE_FILE, $empty, fn () => $result + ['checked_at' => date(DATE_ATOM)]);

        return $result;
    }

    /** @param array<int, string> $headers */
    private static function fetch(string $url, array $headers = []): ?string
    {
        $ch = curl_init($url);
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$buffer) {
                $buffer .= $chunk;
                if (strlen($buffer) >= self::MAX_RESPONSE_BYTES) {
                    return -1;
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($ok === false || $buffer === '' || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        return $buffer;
    }
}
