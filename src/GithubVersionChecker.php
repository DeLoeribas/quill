<?php

declare(strict_types=1);

final class GithubVersionChecker
{
    private const MAX_RESPONSE_BYTES = 65536;

    /**
     * Returns the latest GitHub tag if $currentVersion is behind it, or null if it's
     * current, unknown, or GitHub couldn't be reached. $currentVersion is APP_VERSION —
     * either a plain tag (e.g. "1.2.0") or "dev" (no tags yet, or a local unpackaged run).
     */
    public static function updateAvailable(string $currentVersion): ?string
    {
        if ($currentVersion === 'dev') {
            return null;
        }

        $latest = self::latestTag();
        if ($latest === null) {
            return null;
        }

        return version_compare($currentVersion, $latest, '<') ? $latest : null;
    }

    private static function latestTag(): ?string
    {
        $cache = Storage::read(GITHUB_VERSION_CACHE_FILE, ['tag' => null, 'checked_at' => null]);
        $checkedAt = $cache['checked_at'] ?? null;
        $stale = $checkedAt === null || (time() - strtotime((string) $checkedAt)) >= GITHUB_VERSION_CACHE_SECONDS;

        if (!$stale) {
            return $cache['tag'];
        }

        $fetched = self::fetchLatestTag();
        // Keep the last-known-good tag if GitHub is unreachable right now, but still
        // bump checked_at so we don't retry on every single request while it's down.
        $tag = $fetched ?? ($cache['tag'] ?? null);

        Storage::update(GITHUB_VERSION_CACHE_FILE, ['tag' => null, 'checked_at' => null], function () use ($tag) {
            return ['tag' => $tag, 'checked_at' => date(DATE_ATOM)];
        });

        return $tag;
    }

    private static function fetchLatestTag(): ?string
    {
        $ch = curl_init('https://api.github.com/repos/' . GITHUB_REPO . '/tags?per_page=30');
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

        $tags = json_decode($buffer, true);
        if (!is_array($tags)) {
            return null;
        }

        // GitHub's tag order isn't a documented semver guarantee, so pick the max
        // ourselves rather than trusting response order.
        $best = null;
        foreach ($tags as $tag) {
            $name = $tag['name'] ?? null;
            if (!is_string($name) || !preg_match('/^\d+(\.\d+){0,2}$/', $name)) {
                continue;
            }
            if ($best === null || version_compare($name, $best, '>')) {
                $best = $name;
            }
        }

        return $best;
    }
}
