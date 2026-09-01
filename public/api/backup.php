<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
// Accepts POST as well as GET: a shared/edge cache that ignores query strings
// entirely when building its cache key (a common "maximize hit rate" tuning)
// can defeat every GET-based cache-busting trick, no matter how unique the
// URL looks — confirmed the hard way on a host where neither Cache-Control
// headers nor a fresh random query string on every request stopped it from
// serving back a stale response. No standard cache implementation caches POST
// responses by default, so the client (app.js) submits this as a POST.
if ($method !== 'GET' && $method !== 'POST') {
    json_error('Method not allowed', 405);
}

Auth::requireLogin();

// This endpoint sets no explicit caching header otherwise, and every "Download
// backup" click hits the exact identical URL — some hosts front PHP with a
// page-cache layer (CDN, LiteSpeed Cache, etc.) that would happily serve back
// whatever response it first captured here regardless of how many times the
// underlying code changes, which is a much likelier explanation for a stuck
// result than the code itself. LiteSpeed Cache in particular has its own
// cache-control convention (X-LiteSpeed-Cache-Control) that some site
// configurations honor instead of — not in addition to — the standard
// Cache-Control header, so both are sent.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');

$feedsData = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);

$items = [];
foreach ($feedsData['feeds'] as $feed) {
    $path = items_file_path($feed['id']);
    if (is_file($path)) {
        $items[$feed['id']] = Storage::read($path, ['feed_id' => $feed['id'], 'items' => []]);
    }
}

$backup = [
    'app' => 'quill-backup',
    'version' => 1,
    'created_at' => now_iso8601(),
    // Everything settings.php/feeds.php/items.php read from: folders, feeds
    // (including enabled/filters/refresh_interval), saved_searches, and
    // settings.ui_prefs, plus each feed's stored items (content + read/
    // starred state). Deliberately excludes auth.json (login credentials)
    // so the backup file itself isn't sensitive and doesn't silently
    // overwrite a login on restore.
    'feeds' => $feedsData,
    'items' => $items,
];

// Not pretty-printed: with full item content included, a backup easily runs into
// several MB, and every byte here counts against post_max_size on restore (see
// backup-restore.php) — this isn't meant to be a human-edited file like the OPML
// export is.
$json = json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ?diagnose=1 reports exactly why the compression fallback chain below lands
// where it does, instead of making that a guessing game from a downloaded
// filename — added after two rounds of that on a host that turned out to
// block gzencode() via disable_functions despite zlib being loaded. Runs the
// zip attempt against this request's *real* $json (not a placeholder), since
// a build seen in production reported success for a tiny test write here
// while silently failing on the actual multi-MB payload.
if (($_GET['diagnose'] ?? '') !== '') {
    $report = [
        'php_version' => PHP_VERSION,
        'disable_functions' => ini_get('disable_functions') ?: '(none)',
        'zip_extension_loaded' => class_exists('ZipArchive'),
        'gzencode_available' => function_exists('gzencode'),
        'data_dir' => DATA_DIR,
        'data_dir_writable' => is_writable(DATA_DIR),
        'json_bytes' => strlen($json),
        'memory_limit' => ini_get('memory_limit'),
        'memory_get_peak_usage' => memory_get_peak_usage(true),
    ];

    if (class_exists('ZipArchive')) {
        $tmpPath = tempnam(DATA_DIR, 'rssdiag');
        $report['tempnam_succeeded'] = $tmpPath !== false;
        if ($tmpPath !== false) {
            $zip = new ZipArchive();
            $openResult = $zip->open($tmpPath, ZipArchive::OVERWRITE);
            $report['zip_open_result'] = $openResult === true ? 'OK' : $openResult;
            if ($openResult === true) {
                $report['zip_add_succeeded'] = $zip->addFromString('backup.json', $json);
                $report['zip_close_succeeded'] = $zip->close();
            }
            clearstatcache(true, $tmpPath);
            $report['tmp_file_exists_after_close'] = is_file($tmpPath);
            $report['tmp_file_size_after_close'] = is_file($tmpPath) ? filesize($tmpPath) : null;
            $firstBytes = is_file($tmpPath) ? substr((string) file_get_contents($tmpPath, false, null, 0, 16), 0, 16) : '';
            $report['tmp_file_first_bytes_hex'] = bin2hex($firstBytes);
            $report['tmp_file_starts_with_PK'] = str_starts_with($firstBytes, 'PK');
            @unlink($tmpPath);
        }
    }

    $report['expected_format'] = $report['zip_extension_loaded'] && ($report['tmp_file_starts_with_PK'] ?? false)
        ? 'zip'
        : ($report['gzencode_available'] ? 'gzip' : 'plain json');

    json_response($report);
}

// Compressing cuts a typical backup by 70-85% (mostly item HTML/text), which is
// the difference between clearing a host's post_max_size on restore or not —
// worth doing unconditionally. Tried in this order, each with a fallback:
//   1. A real .zip via ZipArchive — the most portable option, and the one most
//      likely to actually be available: some hosts block the gz*() function
//      family (gzencode/gzdeflate/gzinflate) via disable_functions specifically
//      because that family doubles as a classic malware-obfuscation technique,
//      even though the zlib extension itself stays loaded. ZipArchive doesn't
//      trip that.
//   2. gzencode(), for a build with zlib's functions but no zip extension.
//   3. Plain JSON, for a build with neither.
// backup-restore.php auto-detects whichever of the three it receives.
if (class_exists('ZipArchive')) {
    // DATA_DIR, not sys_get_temp_dir(): many hosts scope open_basedir to the
    // account's own directory tree and leave out /tmp, which makes ZipArchive
    // fail silently there. DATA_DIR is guaranteed writable — the app already
    // stores feeds.json/items there — and data/.htaccess denies all direct web
    // access to it, so a temp file left behind by an interrupted request isn't
    // reachable even before cleanup runs.
    $tmpPath = tempnam(DATA_DIR, 'rssbackup');
    $zipBytes = false;
    if ($tmpPath !== false) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('backup.json', $json);
            $zip->close();
        }
        // Read back and verify before touching a single header — a build seen in
        // production sent zip-shaped headers (Content-Disposition: ....zip,
        // Content-Type: application/octet-stream) while the *body* silently ended
        // up as plain JSON: ZipArchive::open()/addFromString()/close() all report
        // success on this host for a small test write (see ?diagnose=1) but the
        // real ~2MB payload apparently doesn't actually land correctly on disk.
        // Never commit to zip headers on the strength of those return values
        // alone — check what's actually sitting in the file afterward instead,
        // so a failure here falls through to gzip/plain cleanly instead of lying
        // about what the response body is.
        $candidate = is_file($tmpPath) ? file_get_contents($tmpPath) : false;
        if ($candidate !== false && strlen($candidate) >= 4 && substr($candidate, 0, 2) === 'PK') {
            $zipBytes = $candidate;
        }
        unlink($tmpPath);
    }

    if ($zipBytes !== false) {
        // Neither application/zip NOR a .zip filename: confirmed in production
        // that Safari's "Open 'safe' files after downloading" auto-unzips a
        // .zip download and silently replaces it with the extracted backup.json
        // — Content-Type alone (application/octet-stream) didn't stop this, so
        // Safari's detection evidently also (or instead) keys off the filename
        // extension in Content-Disposition, independent of what Content-Type
        // says. A made-up, non-archive extension sidesteps that detection
        // entirely — the bytes underneath are still a completely normal zip
        // file (openable by renaming it to .zip, or the app's own restore
        // flow, which detects the real format by content, never by name).
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="quill-backup-' . date('Y-m-d') . '.rssbackup"');
        echo $zipBytes;
        exit;
    }
}

$gz = function_exists('gzencode') ? gzencode($json, 9) : false;
if ($gz !== false) {
    header('Content-Type: application/octet-stream'); // see the zip branch above for why
    header('Content-Disposition: attachment; filename="quill-backup-' . date('Y-m-d') . '.rssbackup"');
    echo $gz;
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="quill-backup-' . date('Y-m-d') . '.json"');
echo $json;
