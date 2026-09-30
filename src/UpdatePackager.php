<?php

declare(strict_types=1);

/**
 * Builds an upload-ready package of the latest version on GitHub, the in-app
 * counterpart of bin/package-for-deploy.sh for a server that has no git checkout
 * to run that script from. It downloads GitHub's zip of the main branch and repacks
 * it with the same exclusions as the script, plus a few more so the result can be
 * uploaded straight over a live install: no src/config.php (the script creates one
 * from the sample; here that would overwrite the server's own) and nothing from
 * data/ except the tracked templates.
 */
final class UpdatePackager
{
    /** The public repo is ~0.6 MB zipped; anything near this is not our archive. */
    private const MAX_DOWNLOAD_BYTES = 50 * 1024 * 1024;

    /** Keep in sync with the git archive pathspecs in bin/package-for-deploy.sh. */
    private const EXCLUDED_PREFIXES = ['bin/', 'site/'];
    private const EXCLUDED_FILES = ['README.md', '.gitignore', 'src/config.php'];

    /** The only data/ entries shipped: everything else there is the install's own data. */
    private const DATA_TEMPLATES = ['data/.htaccess', 'data/default-feeds.json', 'data/items/.gitkeep'];

    /**
     * @return array{path: string, version: string} a temp zip the caller must unlink
     * @throws RuntimeException with a message fit to show the user
     */
    public static function build(): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('This server has no PHP zip extension, so it can\'t build the package. Run bin/package-for-deploy.sh from a local checkout instead.');
        }

        // DATA_DIR, not sys_get_temp_dir(): see the open_basedir note in public/api/backup.php.
        $sourcePath = tempnam(DATA_DIR, 'quillsrc');
        $outPath = tempnam(DATA_DIR, 'quillupd');
        if ($sourcePath === false || $outPath === false) {
            throw new RuntimeException('Could not create a temporary file in the data directory.');
        }

        try {
            self::download('https://codeload.github.com/' . GITHUB_REPO . '/zip/refs/heads/main', $sourcePath);
            $version = self::repack($sourcePath, $outPath);
        } catch (Throwable $e) {
            @unlink($outPath);
            throw $e;
        } finally {
            @unlink($sourcePath);
        }

        return ['path' => $outPath, 'version' => $version];
    }

    private static function download(string $url, string $path): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Could not write the download to the data directory.');
        }
        $written = 0;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use ($fh, &$written) {
                $written += strlen($chunk);
                if ($written > self::MAX_DOWNLOAD_BYTES) {
                    return -1;
                }
                return fwrite($fh, $chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        fclose($fh);

        if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
            $reason = $ok === false ? $error : 'HTTP ' . $httpCode;
            throw new RuntimeException('Could not download the latest version from GitHub (' . $reason . ').');
        }
    }

    /** @return string the packaged version, read from the archive's own src/version.json */
    private static function repack(string $sourcePath, string $outPath): string
    {
        $source = new ZipArchive();
        if ($source->open($sourcePath) !== true) {
            throw new RuntimeException('The download from GitHub is not a valid zip file.');
        }

        // GitHub wraps everything in one top folder ("quill-main/"); find it from the first entry.
        $first = (string) $source->getNameIndex(0);
        $root = substr($first, 0, strpos($first, '/') + 1);

        $version = parse_version_json($source->getFromName($root . 'src/version.json') ?: null)['version'] ?? null;
        if ($version === null || !preg_match('/^[0-9A-Za-z.+-]+$/', $version)) {
            $source->close();
            throw new RuntimeException('The download from GitHub has no readable src/version.json.');
        }
        $top = 'quill-' . $version . '/';

        $out = new ZipArchive();
        if ($out->open($outPath, ZipArchive::OVERWRITE) !== true) {
            $source->close();
            throw new RuntimeException('Could not create the package file.');
        }

        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = (string) $source->getNameIndex($i);
            if ($root === '' || !str_starts_with($name, $root) || str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen($root));
            if (!self::isShipped($relative)) {
                continue;
            }
            $contents = $source->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            $out->addFromString($top . $relative, $contents);
        }
        $source->close();
        $out->close();

        // Check what actually landed on disk rather than trusting ZipArchive's return
        // values, which have reported success on this host for writes that silently
        // didn't happen (see public/api/backup.php).
        $check = new ZipArchive();
        $valid = false;
        if ($check->open($outPath) === true) {
            $valid = $check->locateName($top . 'public/index.html') !== false
                && $check->locateName($top . 'src/version.json') !== false;
            $check->close();
        }
        if (!$valid) {
            throw new RuntimeException('Building the package failed on this server. Run bin/package-for-deploy.sh from a local checkout instead.');
        }

        return $version;
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
