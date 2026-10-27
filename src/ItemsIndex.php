<?php

declare(strict_types=1);

/**
 * A lightweight copy of a feed's items file without each item's `summary` (often
 * many KB of article HTML), plus precomputed counts. The sidebar counts, the tag,
 * highlight and note lists, and non-search item lists only need this, and parsing
 * it is far cheaper than the full items file once a user has thousands of items.
 *
 * Stored at users/<id>/items-index/<feed>.json and rebuilt lazily: Storage::update()
 * on an items file deletes its index (see register()), and a recorded mtime/size
 * also catches items files changed outside Storage (e.g. bin/ scripts).
 */
final class ItemsIndex
{
    /** @var array<string, array> Indexes already read in this request, keyed by items file path. */
    private static array $memo = [];

    public static function register(): void
    {
        Storage::onWrite(function (string $path): void {
            $indexPath = self::indexPathFor($path);
            if ($indexPath === null) {
                return;
            }
            unset(self::$memo[$path]);
            if (is_file($indexPath)) {
                unlink($indexPath);
            }
        });
    }

    /** users/<id>/items/<feed>.json → users/<id>/items-index/<feed>.json; null for any other file. */
    public static function indexPathFor(string $itemsPath): ?string
    {
        if (basename(dirname($itemsPath)) !== 'items') {
            return null;
        }
        return dirname($itemsPath, 2) . '/items-index/' . basename($itemsPath);
    }

    /** @return array{items: array, total: int, unread: int, starred: int} */
    public static function read(string $itemsPath, string $feedId): array
    {
        if (isset(self::$memo[$itemsPath])) {
            return self::$memo[$itemsPath];
        }
        $empty = ['items' => [], 'total' => 0, 'unread' => 0, 'starred' => 0];
        $indexPath = self::indexPathFor($itemsPath);
        if ($indexPath === null || !is_file($itemsPath)) {
            return $empty;
        }

        clearstatcache(true, $itemsPath);
        $mtime = filemtime($itemsPath);
        $size = filesize($itemsPath);

        $index = Storage::read($indexPath, []);
        if (($index['source_mtime'] ?? null) === $mtime && ($index['source_size'] ?? null) === $size && isset($index['items'])) {
            return self::$memo[$itemsPath] = $index;
        }

        $index = self::rebuild($itemsPath, $indexPath, $feedId);
        return self::$memo[$itemsPath] = $index ?? $empty;
    }

    private static function rebuild(string $itemsPath, string $indexPath, string $feedId): ?array
    {
        $fh = fopen($itemsPath, 'r');
        if ($fh === false) {
            return null;
        }
        // Held until the index is written, so Storage::update() can't change the items
        // file (and delete the index) in between and leave a stale index behind.
        flock($fh, LOCK_SH);
        clearstatcache(true, $itemsPath);
        $stat = fstat($fh);
        $data = json_decode((string) stream_get_contents($fh), true);
        $items = is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : [];

        $index = [
            'feed_id' => $feedId,
            'source_mtime' => $stat['mtime'],
            'source_size' => $stat['size'],
            'total' => count($items),
            'unread' => 0,
            'starred' => 0,
            'items' => [],
        ];
        foreach ($items as $item) {
            unset($item['summary']);
            if (empty($item['read'])) {
                $index['unread']++;
            }
            if (!empty($item['starred'])) {
                $index['starred']++;
            }
            $index['items'][] = $item;
        }

        $dir = dirname($indexPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Write-then-rename so a concurrent reader never sees a half-written index.
        $tmp = $indexPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $indexPath);
        } else {
            @unlink($tmp);
        }

        flock($fh, LOCK_UN);
        fclose($fh);
        return $index;
    }
}
