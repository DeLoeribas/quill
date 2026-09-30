<?php

declare(strict_types=1);

require_once __DIR__ . '/FeedFetcher.php';

/**
 * Builds an upload-ready package of the latest version on GitHub, the in-app
 * counterpart of bin/package-for-deploy.sh for a server that has no git checkout
 * to run that script from. It ships the files of main's latest commit with the same
 * exclusions as the script, plus a few more so the result can be uploaded straight
 * over a live install: no src/config.php (the script creates one from the sample;
 * here that would overwrite the server's own) and nothing from data/ except the
 * tracked templates.
 *
 * Deliberately needs nothing but curl: the production host has no zip extension and
 * has been seen blocking the gz*() functions (see public/api/backup.php), so GitHub's
 * compressed archive can't be unpacked there. Instead the file list comes from the
 * git trees API, each file is fetched uncompressed from raw.githubusercontent.com at
 * that exact commit, and the zip is written by hand (deflated if gzdeflate() is
 * available, stored otherwise).
 */
final class UpdatePackager
{
    /** Keep in sync with the git archive pathspecs in bin/package-for-deploy.sh. */
    private const EXCLUDED_PREFIXES = ['bin/', 'site/'];
    private const EXCLUDED_FILES = ['README.md', '.gitignore', 'src/config.php'];

    /** The only data/ entries shipped: everything else there is the install's own data. */
    private const DATA_TEMPLATES = ['data/.htaccess', 'data/default-feeds.json', 'data/items/.gitkeep'];

    /** The repo is well under 1 MB; refuse anything absurd rather than fill the disk. */
    private const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    /**
     * @return array{path: string, version: string} a temp zip the caller must unlink
     * @throws RuntimeException with a message fit to show the user
     */
    public static function build(): array
    {
        $api = 'https://api.github.com/repos/' . GITHUB_REPO;

        // Pin everything to one commit so a push halfway through can't mix two versions.
        $sha = trim((string) self::fetch($api . '/commits/main', 'application/vnd.github.sha'));
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new RuntimeException('Could not find the latest commit on GitHub.');
        }

        $tree = json_decode((string) self::fetch($api . '/git/trees/' . $sha . '?recursive=1', 'application/vnd.github+json'), true);
        if (!is_array($tree) || !is_array($tree['tree'] ?? null) || ($tree['truncated'] ?? false)) {
            throw new RuntimeException('Could not read the file list from GitHub.');
        }

        $requests = [];
        $total = 0;
        foreach ($tree['tree'] as $entry) {
            if (($entry['type'] ?? null) !== 'blob' || !is_string($entry['path'] ?? null) || !self::isShipped($entry['path'])) {
                continue;
            }
            $total += (int) ($entry['size'] ?? 0);
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $entry['path'])));
            $requests[$entry['path']] = [
                'url' => 'https://raw.githubusercontent.com/' . GITHUB_REPO . '/' . $sha . '/' . $encodedPath,
                'etag' => null,
                'lastModified' => null,
            ];
        }
        if (!isset($requests['public/index.html'], $requests['src/version.json']) || $total > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('The file list on GitHub doesn\'t look like this app.');
        }

        $files = [];
        foreach (FeedFetcher::fetchManyRaw($requests, FETCH_CONCURRENCY) as $path => $result) {
            if ($result->error !== null || $result->httpCode !== 200 || $result->body === null) {
                throw new RuntimeException('Could not download ' . $path . ' from GitHub (' . ($result->error ?? 'HTTP ' . $result->httpCode) . ').');
            }
            $files[$path] = $result->body;
        }
        ksort($files);

        $version = parse_version_json($files['src/version.json'])['version'] ?? null;
        if ($version === null || !preg_match('/^[0-9A-Za-z.+-]+$/', $version)) {
            throw new RuntimeException('The latest version on GitHub has no readable src/version.json.');
        }

        // DATA_DIR, not sys_get_temp_dir(): see the open_basedir note in public/api/backup.php.
        $outPath = tempnam(DATA_DIR, 'quillupd');
        if ($outPath === false) {
            throw new RuntimeException('Could not create a temporary file in the data directory.');
        }
        try {
            self::writeZip($outPath, 'quill-' . $version . '/', $files);
        } catch (Throwable $e) {
            @unlink($outPath);
            throw $e;
        }

        return ['path' => $outPath, 'version' => $version];
    }

    private static function fetch(string $url, string $accept): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 403 || $httpCode === 429) {
            // The API allows 60 unauthenticated requests an hour per IP; a build uses two.
            throw new RuntimeException('GitHub\'s rate limit was reached. Try again in an hour.');
        }
        if (!is_string($body) || $httpCode < 200 || $httpCode >= 300) {
            $reason = is_string($body) ? 'HTTP ' . $httpCode : curl_error($ch);
            throw new RuntimeException('Could not reach GitHub (' . $reason . ').');
        }
        return $body;
    }

    /**
     * Minimal zip writer (local headers, central directory, end record): enough for
     * every unzip tool and OS, without needing the zip extension.
     * @param array<string, string> $files relative path => contents
     */
    private static function writeZip(string $path, string $prefix, array $files): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Could not create the package file.');
        }

        $canDeflate = function_exists('gzdeflate');
        [$dosTime, $dosDate] = self::dosDateTime(time());
        $central = '';
        $offset = 0;

        foreach ($files as $relative => $contents) {
            $name = $prefix . $relative;
            $crc = crc32($contents);
            $size = strlen($contents);
            $data = $contents;
            $method = 0; // stored
            if ($canDeflate) {
                $deflated = gzdeflate($contents, 9);
                if ($deflated !== false && strlen($deflated) < $size) {
                    $data = $deflated;
                    $method = 8;
                }
            }
            // Bit 11 = file name is UTF-8.
            $fields = pack('vvvvvVVVvv', 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($data), $size, strlen($name), 0);
            fwrite($fh, "PK\x03\x04" . $fields . $name . $data);

            $central .= "PK\x01\x02" . pack('v', 20) . $fields . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
            $offset += 30 + strlen($name) + strlen($data);
        }

        $end = "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), $offset, 0);
        $ok = fwrite($fh, $central . $end) !== false;
        fclose($fh);

        clearstatcache(true, $path);
        if (!$ok || filesize($path) !== $offset + strlen($central) + strlen($end)) {
            throw new RuntimeException('Writing the package file failed.');
        }
    }

    /** @return array{0: int, 1: int} MS-DOS time and date words, as zip headers store them */
    private static function dosDateTime(int $timestamp): array
    {
        $d = getdate($timestamp);
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = ((max($d['year'], 1980) - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        return [$time, $date];
    }

    private static function isShipped(string $path): bool
    {
        if (in_array($path, self::EXCLUDED_FILES, true)) {
            return false;
        }
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        if (str_starts_with($path, 'data/')) {
            return in_array($path, self::DATA_TEMPLATES, true);
        }
        return true;
    }
}
