<?php
// One-off migration: re-keys every stored item's `id` using the current
// item_id_for() formula, applied to that item's own stored guid/link/title/
// published. Needed exactly once, immediately after deploying a change to
// item_id_for()'s normalization logic (src/bootstrap.php) — otherwise the
// next refresh recomputes ids that won't match any existing stored item and
// floods every feed with "new" unread duplicates.
//
// Safe to re-run: item_id_for() is a pure function, so once an item's id
// already matches what the current formula produces, re-running is a no-op
// for it. Never deletes an item outright; two items that collide onto the
// same new id (e.g. two link variants of the same article) are merged, with
// the drop reported rather than silently discarded.
//
// Note: bin/package-for-deploy.sh excludes the whole bin/ directory from its
// deploy package, so this file must be uploaded to the server separately.
//
// Usage:
//   php bin/migrate-item-ids.php --dry-run   # report only, no writes
//   php bin/migrate-item-ids.php             # writes, after taking a backup

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

/**
 * Two stored items that collide onto the same new id are, by construction,
 * the same logical article. Keep whichever the user cared about more so
 * nothing gets silently discarded: starred > read > most recently fetched.
 */
function migrate_item_ids_resolve_collision(array $a, array $b): array
{
    if (!empty($a['starred']) !== !empty($b['starred'])) {
        return !empty($a['starred']) ? $a : $b;
    }
    if (!empty($a['read']) !== !empty($b['read'])) {
        return !empty($a['read']) ? $a : $b;
    }
    return ($a['fetched_at'] ?? '') >= ($b['fetched_at'] ?? '') ? $a : $b;
}

$dryRun = in_array('--dry-run', $argv, true);

$files = glob(ITEMS_DIR . '/*.json') ?: [];
if (empty($files)) {
    echo "No item files found in " . ITEMS_DIR . " -- nothing to do.\n";
    exit(0);
}

if (!$dryRun) {
    $backupDir = DATA_DIR . '/items-backup-' . date('Ymd-His');
    mkdir($backupDir, 0775, true);
    foreach ($files as $file) {
        copy($file, $backupDir . '/' . basename($file));
    }
    echo "Backed up " . count($files) . " item file(s) to $backupDir\n\n";
}

$totalChanged = 0;
$totalCollisions = 0;

foreach ($files as $file) {
    $feedId = basename($file, '.json');
    $changed = 0;
    $collisions = 0;

    $mutate = function (array $data) use (&$changed, &$collisions) {
        $byNewId = [];
        foreach ($data['items'] as $item) {
            $newId = item_id_for($item['guid'] ?? null, $item['link'] ?? null, $item['title'] ?? null, $item['published'] ?? null);
            if ($newId !== $item['id']) {
                $changed++;
            }
            $item['id'] = $newId;

            if (isset($byNewId[$newId])) {
                $collisions++;
                $byNewId[$newId] = migrate_item_ids_resolve_collision($byNewId[$newId], $item);
                continue;
            }
            $byNewId[$newId] = $item;
        }
        $data['items'] = array_values($byNewId);
        $data['evicted_ids'] = $data['evicted_ids'] ?? [];
        return $data;
    };

    if ($dryRun) {
        $data = Storage::read($file, ['feed_id' => $feedId, 'items' => []]);
        $mutate($data);
    } else {
        Storage::update($file, ['feed_id' => $feedId, 'items' => []], $mutate);
    }

    echo sprintf("%-40s changed=%-4d collisions=%d\n", basename($file), $changed, $collisions);
    $totalChanged += $changed;
    $totalCollisions += $collisions;
}

echo "\n" . ($dryRun ? "[dry run] " : "") . "Done. $totalChanged item id(s) rekeyed, $totalCollisions collision(s).\n";
if ($totalCollisions > 0) {
    echo "Collisions mean two previously-distinct stored items now normalize to the same id\n";
    echo "(e.g. two link variants of the same article) -- the less useful one (see\n";
    echo "migrate_item_ids_resolve_collision()) was dropped; nothing was silently lost, review the counts above.\n";
}
