<?php

declare(strict_types=1);

final class GithubVersionChecker
{
    private const MAX_RESPONSE_BYTES = 65536;

    /**
     * Returns the latest commit's short SHA if $currentVersion is behind it, or null if
     * it's current, unknown, or GitHub couldn't be reached. $currentVersion is
     * APP_VERSION — either a short commit hash (e.g. "a1b2c3d") stamped at package time,
     * or "dev" (a local unpackaged run).
     */
    public static function updateAvailable(string $currentVersion): ?string
    {
        if ($currentVersion === 'dev') {
            return null;
        }

        $latest = self::latestCommitSha();
        if ($latest === null) {
            return null;
        }

        if (str_starts_with($latest, $currentVersion)) {
            return null;
        }

        return substr($latest, 0, 7);
    }

    private static function latestCommitSha(): ?string
    {
        $cache = Storage::read(GITHUB_VERSION_CACHE_FILE, ['sha' => null, 'checked_at' => null]);
        $checkedAt = $cache['checked_at'] ?? null;
        $stale = $checkedAt === null || (time() - strtotime((string) $checkedAt)) >= GITHUB_VERSION_CACHE_SECONDS;

        if (!$stale) {
            return $cache['sha'];
        }

        $fetched = self::fetchLatestCommitSha();
        // Keep the last-known-good sha if GitHub is unreachable right now, but still
        // bump checked_at so we don't retry on every single request while it's down.
        $sha = $fetched ?? ($cache['sha'] ?? null);

        Storage::update(GITHUB_VERSION_CACHE_FILE, ['sha' => null, 'checked_at' => null], function () use ($sha) {
            return ['sha' => $sha, 'checked_at' => date(DATE_ATOM)];
        });

        return $sha;
    }

    private static function fetchLatestCommitSha(): ?string
    {
        $ch = curl_init('https://api.github.com/repos/' . GITHUB_REPO . '/commits/main');
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$buffer) {
                $buffer .= $chunk;
                if (strlen($buffer) >= self::MAX_RESPONSE_BYTES) {
                    return -1;
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($buffer === '' || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $commit = json_decode($buffer, true);
        $sha = $commit['sha'] ?? null;

        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
    }
}
