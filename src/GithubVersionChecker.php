<?php

declare(strict_types=1);

/**
 * Decides whether a newer version is on GitHub by comparing the deployed code
 * files themselves against the latest commit on main, file by file (by git blob
 * hash). Deliberately independent of src/version.php: updates are often done by
 * hand-copying changed files, which leaves a stale version.php behind and made
 * the old "compare APP_VERSION to the latest sha" check nag forever.
 */
final class GithubVersionChecker
{
    private const MAX_RESPONSE_BYTES = 524288;

    /** Only the app code that gets uploaded is compared — data/, docs and dev tooling can differ freely. */
    private const COMPARED_PREFIXES = ['public/', 'src/', 'cron/'];

    /** Files users are told to edit in place (e.g. enabling Basic Auth in public/.htaccess) — never compared. */
    private const IGNORED_BASENAMES = ['.htaccess'];

    /**
     * Returns ['version' => what the footer should show, 'latest' => short sha of the
     * newer commit, or null if up to date / unknown / GitHub unreachable].
     */
    public static function status(): array
    {
        $root = dirname(__DIR__);

        // A local git working copy nearly always has uncommitted edits, which would
        // make the badge show permanently; there's nothing useful to compare there.
        if (is_dir($root . '/.git')) {
            return ['version' => APP_VERSION, 'latest' => null];
        }

        $latest = self::latestCommit();
        if ($latest === null) {
            return ['version' => APP_VERSION, 'latest' => null];
        }

        $shortSha = substr($latest['sha'], 0, 7);
        if (self::localFilesMatch($root, $latest['files'])) {
            return ['version' => $shortSha, 'latest' => null];
        }

        return ['version' => APP_VERSION, 'latest' => $shortSha];
    }

    /** True if every compared file in the commit exists locally with identical contents. Extra local files (config.php, version.php) are ignored. */
    private static function localFilesMatch(string $root, array $files): bool
    {
        foreach ($files as $path => $blobSha) {
            $contents = @file_get_contents($root . '/' . $path);
            if ($contents === false) {
                return false;
            }
            if (sha1('blob ' . strlen($contents) . "\0" . $contents) !== $blobSha) {
                return false;
            }
        }
        return true;
    }

    /** @return array{sha: string, files: array<string, string>}|null */
    private static function latestCommit(): ?array
    {
        $empty = ['sha' => null, 'files' => null, 'checked_at' => null];
        $cache = Storage::read(GITHUB_VERSION_CACHE_FILE, $empty);
        $checkedAt = $cache['checked_at'] ?? null;
        // A cache written by the old checker has no 'files' — treat it as stale.
        $stale = $checkedAt === null
            || !is_array($cache['files'] ?? null)
            || (time() - strtotime((string) $checkedAt)) >= GITHUB_VERSION_CACHE_SECONDS;

        if (!$stale) {
            return ['sha' => $cache['sha'], 'files' => $cache['files']];
        }

        $fetched = self::fetchLatestCommit();
        // Keep the last-known-good result if GitHub is unreachable right now, but still
        // bump checked_at so we don't retry on every single request while it's down.
        $result = $fetched ?? (is_string($cache['sha'] ?? null) && is_array($cache['files'] ?? null)
            ? ['sha' => $cache['sha'], 'files' => $cache['files']]
            : null);

        Storage::update(GITHUB_VERSION_CACHE_FILE, $empty, function () use ($result) {
            return [
                'sha' => $result['sha'] ?? null,
                'files' => $result['files'] ?? null,
                'checked_at' => date(DATE_ATOM),
            ];
        });

        return $result;
    }

    /** @return array{sha: string, files: array<string, string>}|null */
    private static function fetchLatestCommit(): ?array
    {
        $api = 'https://api.github.com/repos/' . GITHUB_REPO;

        // The .sha media type returns just the 40-char hash, not the full commit + diff.
        $sha = trim((string) self::fetch($api . '/commits/main', 'application/vnd.github.sha'));
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return null;
        }

        $tree = json_decode((string) self::fetch($api . '/git/trees/' . $sha . '?recursive=1', 'application/vnd.github+json'), true);
        if (!is_array($tree['tree'] ?? null) || !empty($tree['truncated'])) {
            return null;
        }

        $files = [];
        foreach ($tree['tree'] as $entry) {
            $path = $entry['path'] ?? '';
            if (($entry['type'] ?? '') !== 'blob' || !is_string($entry['sha'] ?? null)
                || in_array(basename($path), self::IGNORED_BASENAMES, true)) {
                continue;
            }
            foreach (self::COMPARED_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $files[$path] = $entry['sha'];
                    break;
                }
            }
        }

        return $files === [] ? null : ['sha' => $sha, 'files' => $files];
    }

    private static function fetch(string $url, string $accept): ?string
    {
        $ch = curl_init($url);
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
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
