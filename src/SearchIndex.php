<?php

declare(strict_types=1);

/**
 * Per-feed search text, so a search doesn't have to parse every full items file,
 * strip its HTML and fold its accents again for every query. For each item it keeps
 * fold_accents(strip_tags(title + summary + note)) plus its folded captured page
 * text, at users/<id>/search-index/<feed>.json. The feed title isn't stored (it can
 * be renamed) and is added at match time instead.
 *
 * Saved searches also cache their per-feed match counts in
 * users/<id>/search-counts/<feed>.json, so the sidebar counts (fetched every
 * minute) only recompute for feeds that changed since.
 *
 * Both files are deleted by Storage::update() on that feed's items or page texts
 * file (see register()), and rebuilt lazily on the next search.
 */
final class SearchIndex
{
    /** @var array<string, array{inode: int, texts: array<string, string>}> Indexes read in this request, keyed by index path. */
    private static array $memo = [];

    public static function register(): void
    {
        Storage::onWrite(function (string $path): void {
            foreach ([self::indexPathFor($path), self::countsPathFor($path)] as $derived) {
                if ($derived === null) {
                    continue;
                }
                unset(self::$memo[$derived]);
                if (is_file($derived)) {
                    unlink($derived);
                }
            }
        });
    }

    /** users/<id>/{items,pages}/<feed>.json → users/<id>/search-index/<feed>.json; null for any other file. */
    public static function indexPathFor(string $sourcePath): ?string
    {
        return self::derivedPath($sourcePath, 'search-index');
    }

    public static function countsPathFor(string $sourcePath): ?string
    {
        return self::derivedPath($sourcePath, 'search-counts');
    }

    private static function derivedPath(string $sourcePath, string $dirName): ?string
    {
        if (!in_array(basename(dirname($sourcePath)), ['items', 'pages'], true)) {
            return null;
        }
        return dirname($sourcePath, 2) . '/' . $dirName . '/' . basename($sourcePath);
    }

    /**
     * Ids of this feed's items matching every pattern from search_query_patterns().
     *
     * @return array<string, true>
     */
    public static function matchingIds(string $itemsPath, string $pagesPath, ?string $feedTitle, array $patterns): array
    {
        $locks = self::lockSources($itemsPath, $pagesPath);
        try {
            $texts = self::texts($itemsPath, $pagesPath);
        } finally {
            self::unlock($locks);
        }
        return self::match($texts, $feedTitle, $patterns);
    }

    /** Number of this feed's items matching $query, cached per query and feed title until the feed's items or page texts change. */
    public static function countMatches(string $itemsPath, string $pagesPath, ?string $feedTitle, string $query, array $patterns): int
    {
        $countsPath = self::countsPathFor($itemsPath);
        $key = md5($query . "\0" . ($feedTitle ?? ''));
        $cached = Storage::read($countsPath, []);
        if (isset($cached[$key]) && is_int($cached[$key])) {
            return $cached[$key];
        }

        // Counted and stored under the sources' shared locks, so a write in between
        // (which deletes the counts file under its exclusive lock) can't leave a
        // stale count behind.
        $locks = self::lockSources($itemsPath, $pagesPath);
        try {
            $signature = self::signature($itemsPath, $pagesPath);
            $count = count(self::match(self::texts($itemsPath, $pagesPath), $feedTitle, $patterns));
            // A page texts file created meanwhile wasn't covered by the locks above.
            if (self::signature($itemsPath, $pagesPath) === $signature) {
                $counts = Storage::read($countsPath, []);
                $counts[$key] = $count;
                Storage::writeAtomic($countsPath, $counts);
            }
        } finally {
            self::unlock($locks);
        }
        return $count;
    }

    /** @return array<string, true> */
    private static function match(array $texts, ?string $feedTitle, array $patterns): array
    {
        $titleText = fold_accents(strip_tags($feedTitle ?? ''));
        $ids = [];
        foreach ($texts as $id => $text) {
            $haystack = $text . ' ' . $titleText;
            foreach ($patterns as $pattern) {
                if (!preg_match($pattern, $haystack)) {
                    continue 2;
                }
            }
            $ids[(string) $id] = true;
        }
        return $ids;
    }

    /**
     * The feed's search texts by item id. Callers hold the sources' shared locks
     * (lockSources()), so the index can't change while it's read or rebuilt.
     *
     * @return array<string, string>
     */
    private static function texts(string $itemsPath, string $pagesPath): array
    {
        if (!is_file($itemsPath)) {
            return [];
        }
        $indexPath = self::indexPathFor($itemsPath);
        $signature = self::signature($itemsPath, $pagesPath);

        clearstatcache(true, $indexPath);
        $inode = is_file($indexPath) ? fileinode($indexPath) : false;
        // Every rebuild renames a new file into place, so a matching inode means
        // the memoized copy is still the current one.
        if ($inode !== false && isset(self::$memo[$indexPath]) && self::$memo[$indexPath]['inode'] === $inode) {
            return self::$memo[$indexPath]['texts'];
        }

        $index = $inode !== false ? Storage::read($indexPath, []) : [];
        if (($index['signature'] ?? null) !== $signature || !is_array($index['texts'] ?? null)) {
            $index = self::rebuild($itemsPath, $pagesPath, $signature);
            Storage::writeAtomic($indexPath, $index);
            clearstatcache(true, $indexPath);
            $inode = fileinode($indexPath);
        }
        if ($inode !== false) {
            self::$memo[$indexPath] = ['inode' => $inode, 'texts' => $index['texts']];
        }
        return $index['texts'];
    }

    private static function rebuild(string $itemsPath, string $pagesPath, array $signature): array
    {
        $items = Storage::read($itemsPath, [])['items'] ?? [];
        $pageTexts = is_file($pagesPath) ? Storage::read($pagesPath, []) : [];
        $texts = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $text = fold_accents(strip_tags(($item['title'] ?? '') . ' ' . ($item['summary'] ?? '') . ' ' . ($item['comment'] ?? '')));
            $pageText = $pageTexts[$item['id']]['text'] ?? '';
            if ($pageText !== '') {
                $text .= ' ' . fold_accents($pageText);
            }
            $texts[$item['id']] = $text;
        }
        return ['signature' => $signature, 'texts' => $texts];
    }

    /** Guards against source files changed outside Storage::update(), which wouldn't trigger register()'s cleanup. */
    private static function signature(string $itemsPath, string $pagesPath): array
    {
        clearstatcache();
        return [
            'items' => is_file($itemsPath) ? [filemtime($itemsPath), filesize($itemsPath)] : null,
            'pages' => is_file($pagesPath) ? [filemtime($pagesPath), filesize($pagesPath)] : null,
        ];
    }

    /**
     * Shared locks on the items file, then the page texts file. No writer holds the
     * page texts lock while waiting for the items lock, so this order can't deadlock.
     *
     * @return resource[]
     */
    private static function lockSources(string $itemsPath, string $pagesPath): array
    {
        $locks = [];
        foreach ([$itemsPath, $pagesPath] as $path) {
            if (is_file($path) && ($fh = fopen($path, 'r')) !== false) {
                flock($fh, LOCK_SH);
                $locks[] = $fh;
            }
        }
        return $locks;
    }

    /** @param resource[] $locks */
    private static function unlock(array $locks): void
    {
        foreach (array_reverse($locks) as $fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
