<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

const MAX_BACKUP_BYTES = 50 * 1024 * 1024;

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

Auth::requireLogin();

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');

// Sent as a raw request body (not multipart/$_FILES): a backup carries full item
// content, easily several MB once a feed collection has some history, and
// multipart uploads are capped by upload_max_filesize, whose PHP default (2MB)
// is smaller than a great many real backups. A raw body is governed by
// post_max_size instead, whose PHP default (8MB) gives meaningfully more room.
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$raw = file_get_contents('php://input');
if ($raw === false || ($raw === '' && $contentLength > 0)) {
    json_error('Upload was empty or truncated — it likely exceeds this server\'s post_max_size (' . ini_get('post_max_size') . ')');
}
if (strlen($raw) > MAX_BACKUP_BYTES) {
    json_error('Backup file is too large');
}

// backup.php compresses when it can (see there, for why zip is tried before
// gzip), but detect by content rather than trust a client-supplied flag: the
// magic bytes identify the format regardless of what the file was named, and
// this also accepts an older gzip or plain-JSON backup from before this format
// was added.
if (strlen($raw) >= 2 && substr($raw, 0, 2) === 'PK') {
    if (!class_exists('ZipArchive')) {
        json_error('This backup is a zip archive, but this server has no zip support to read it');
    }
    // DATA_DIR, not sys_get_temp_dir() — see backup.php for why.
    $tmpPath = tempnam(DATA_DIR, 'rssrestore');
    if ($tmpPath === false || file_put_contents($tmpPath, $raw) === false) {
        json_error('Unable to write a temporary file to read the uploaded archive');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        unlink($tmpPath);
        json_error('Backup file is corrupt (zip archive could not be opened)');
    }
    $inner = $zip->getFromName('backup.json');
    $zip->close();
    unlink($tmpPath);
    if ($inner === false) {
        json_error('Backup zip archive is missing backup.json');
    }
    $raw = $inner;
} elseif (strlen($raw) >= 2 && substr($raw, 0, 2) === "\x1f\x8b") {
    if (!function_exists('gzdecode')) {
        json_error('This backup is gzip-compressed, but this server\'s PHP build has no zlib support to decompress it');
    }
    $decoded = gzdecode($raw);
    if ($decoded === false) {
        json_error('Backup file is corrupt (gzip data could not be decompressed)');
    }
    $raw = $decoded;
}

$backup = json_decode($raw, true);
if (
    !is_array($backup)
    || ($backup['app'] ?? null) !== 'quill-backup'
    || !is_array($backup['feeds'] ?? null)
    || !is_array($backup['feeds']['folders'] ?? null)
    || !is_array($backup['feeds']['feeds'] ?? null)
) {
    json_error('This does not look like a valid backup file');
}

$restoredFeedsData = $backup['feeds'];
$restoredItems = is_array($backup['items'] ?? null) ? $backup['items'] : [];

$previousFeedIds = [];

// Full replace, same as OpmlImporter::import() — but unlike OPML import
// (which deliberately preserves the existing settings/ui_prefs key), a
// backup restore replaces the *entire* feeds.json document, since
// saved_searches and ui_prefs are themselves part of what's being restored.
Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($restoredFeedsData, &$previousFeedIds) {
    $previousFeedIds = array_column($data['feeds'], 'id');
    return $restoredFeedsData;
});

$restoredFeedIds = array_column($restoredFeedsData['feeds'], 'id');
$itemsRestoredCount = 0;

// Every restored feed's item file is overwritten outright (falling back to
// empty when the backup had none for it) rather than merged, so a feed id
// that happens to persist across the restore can't keep stale items left
// over from before the restore.
foreach ($restoredFeedIds as $feedId) {
    $itemsData = $restoredItems[$feedId] ?? null;
    if (!is_array($itemsData)) {
        $itemsData = ['feed_id' => $feedId, 'items' => []];
    }
    $itemsRestoredCount += count($itemsData['items'] ?? []);

    $path = items_file_path($feedId);
    Storage::update($path, ['feed_id' => $feedId, 'items' => []], function () use ($feedId, $itemsData) {
        return array_merge(['feed_id' => $feedId, 'items' => []], $itemsData);
    });
}

// Any feed that existed before the restore but isn't part of the restored
// set no longer has a matching feed entry, so its stored items would be
// orphaned — same cleanup OpmlImporter::import() does for removed feeds.
$orphanedFeedIds = array_diff($previousFeedIds, $restoredFeedIds);
foreach ($orphanedFeedIds as $feedId) {
    $path = items_file_path($feedId);
    if (is_file($path)) {
        unlink($path);
    }
}

json_response([
    'ok' => true,
    'feeds_restored' => count($restoredFeedsData['feeds']),
    'items_restored' => $itemsRestoredCount,
    'orphaned_removed' => count($orphanedFeedIds),
]);
