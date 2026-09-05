<?php

declare(strict_types=1);

require_once __DIR__ . '/FeedFetcher.php';
require_once __DIR__ . '/FaviconResolver.php';

final class RefreshService
{
    // Bounded FIFO of ids soft-cap-evicted while read, so a reappearing item
    // is recognized as "already seen" instead of surfacing fresh as unread.
    private const EVICTED_IDS_LIMIT = 200;

    /**
     * Refreshes one feed by id. When $force is false, a feed is skipped
     * (status 'skipped') unless its refresh_interval_minutes has elapsed
     * since last_fetched.
     *
     * @return array{status:string, new_items:int, error:?string}
     */
    public static function refreshFeed(string $feedId, bool $force = false): array
    {
        $feedsData = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
        $feed = self::findFeed($feedsData, $feedId);
        if ($feed === null) {
            return ['status' => 'error', 'new_items' => 0, 'error' => 'feed not found'];
        }

        if (($feed['enabled'] ?? true) === false) {
            return ['status' => 'disabled', 'new_items' => 0, 'error' => null];
        }

        if (!$force && !self::isDue($feed)) {
            return ['status' => 'skipped', 'new_items' => 0, 'error' => null];
        }

        // A forced refresh skips conditional GET too (not just the due-time gate): otherwise
        // an unchanged feed just gets a 304 back, and 304s never re-parse the body, so a
        // publisher-declared <ttl> can never be (re-)detected on a feed that hasn't changed.
        $result = FeedFetcher::fetchRaw(
            $feed['url'],
            $force ? null : ($feed['etag'] ?? null),
            $force ? null : ($feed['last_modified'] ?? null)
        );

        return self::processFetchResult($feed, $result);
    }

    private static function findFeed(array $feedsData, string $feedId): ?array
    {
        foreach ($feedsData['feeds'] as $f) {
            if ($f['id'] === $feedId) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Everything that happens once a feed's raw HTTP response is in hand:
     * error/not-modified/HTTP-error handling, parsing, merging new items,
     * and updating the feed's stored metadata. Shared by refreshFeed()
     * (single feed, fetched synchronously) and refreshAll() (many feeds,
     * fetched concurrently up front, then each processed through here).
     *
     * @return array{status:string, new_items:int, error:?string}
     */
    private static function processFetchResult(array $feed, FeedFetchResult $result): array
    {
        $feedId = $feed['id'];

        if ($result->error !== null) {
            return self::recordFailure($feed, $result, $result->error);
        }

        if ($result->httpCode === 304) {
            $faviconUrl = null;
            if (empty($feed['favicon_url'])) {
                $faviconUrl = FaviconResolver::resolve($feed['site_url'] ?: $feed['url']);
            }
            self::updateFeedMeta($feedId, function (array $f) use ($faviconUrl) {
                $f['last_fetched'] = now_iso8601();
                $f['last_status'] = 'ok';
                $f['last_error'] = null;
                $f['consecutive_failures'] = 0;
                if ($faviconUrl !== null && empty($f['favicon_url'])) {
                    $f['favicon_url'] = $faviconUrl;
                }
                return $f;
            });
            return ['status' => 'not_modified', 'new_items' => 0, 'error' => null];
        }

        if ($result->httpCode < 200 || $result->httpCode >= 300 || $result->body === null) {
            return self::recordFailure($feed, $result, 'HTTP ' . $result->httpCode);
        }

        $parsed = FeedFetcher::parse($result->body);
        if ($parsed === null) {
            $error = 'Unable to parse feed (not valid RSS2 or Atom)';
            self::updateFeedMeta($feedId, function (array $f) use ($error) {
                $f['last_fetched'] = now_iso8601();
                $f['last_status'] = 'error';
                $f['last_error'] = $error;
                return $f;
            });
            return ['status' => 'error', 'new_items' => 0, 'error' => $error];
        }

        $newItemCount = self::mergeItems($feedId, $parsed->items, $feed['filters'] ?? []);

        $faviconUrl = null;
        if (empty($feed['favicon_url'])) {
            $faviconUrl = FaviconResolver::resolve($feed['site_url'] ?: $parsed->siteUrl ?: $feed['url']);
        }

        self::updateFeedMeta($feedId, function (array $f) use ($result, $parsed, $faviconUrl) {
            $f['last_fetched'] = now_iso8601();
            $f['last_status'] = 'ok';
            $f['last_error'] = null;
            $f['consecutive_failures'] = 0;
            $f['etag'] = $result->etag;
            $f['last_modified'] = $result->lastModified;

            if (($f['title'] === '' || $f['title'] === $f['url']) && $parsed->feedTitle) {
                $f['title'] = $parsed->feedTitle;
            }
            if (empty($f['site_url']) && $parsed->siteUrl) {
                $f['site_url'] = $parsed->siteUrl;
            }
            if (empty($f['image_url']) && $parsed->feedImage) {
                $f['image_url'] = $parsed->feedImage;
            }
            if ($faviconUrl !== null && empty($f['favicon_url'])) {
                $f['favicon_url'] = $faviconUrl;
            }
            // A feed that declares its own suggested cadence (RSS <ttl> or the
            // Syndication module) takes precedence over the global default —
            // re-applied on every successful fetch so it stays in sync if the
            // publisher changes it. Feeds that don't declare one (Atom, JSON
            // Feed, plain RSS) keep whatever was last assigned.
            $f['refresh_interval_locked'] = $parsed->ttlMinutes !== null;
            if ($parsed->ttlMinutes !== null) {
                $f['refresh_interval_minutes'] = $parsed->ttlMinutes;
            }
            return $f;
        });

        return ['status' => 'ok', 'new_items' => $newItemCount, 'error' => null];
    }

    /**
     * Records a failed fetch against the feed.
     *
     * A transient failure (a connection error, a 5xx, or one of YouTube's
     * random 404s — see FeedFetcher::isTransientFailure) is absorbed rather
     * than surfaced for the first FETCH_TRANSIENT_TOLERANCE attempts in a row:
     * the feed keeps its previous status and, crucially, keeps its previous
     * last_fetched, so isDue() still considers it due and the very next refresh
     * retries it instead of waiting out a whole refresh interval. YouTube's 404
     * bursts last minutes, well past what FeedFetcher's in-request retries can
     * ride out, but they clear long before the next hourly refresh would.
     *
     * Anything else — and any transient failure that keeps happening — is a
     * real error: it's shown in the sidebar and the feed goes back to its
     * normal schedule so a permanently broken host isn't retried every tick.
     *
     * @return array{status:string, new_items:int, error:?string}
     */
    private static function recordFailure(array $feed, FeedFetchResult $result, string $error): array
    {
        $failures = (int) ($feed['consecutive_failures'] ?? 0) + 1;
        $soft = FeedFetcher::isTransientFailure($result, $feed['url'])
            && $failures < FETCH_TRANSIENT_TOLERANCE;

        self::updateFeedMeta($feed['id'], function (array $f) use ($error, $failures, $soft) {
            $f['consecutive_failures'] = $failures;
            if ($soft) {
                return $f;
            }
            $f['last_fetched'] = now_iso8601();
            $f['last_status'] = 'error';
            $f['last_error'] = $error;
            return $f;
        });

        return [
            'status' => $soft ? 'transient_error' : 'error',
            'new_items' => 0,
            'error' => $error,
        ];
    }

    /**
     * Refreshes every feed. Feeds that are disabled or not yet due (unless
     * $force) are resolved immediately without a network call; the rest are
     * fetched concurrently via FeedFetcher::fetchManyRaw (capped at
     * FETCH_CONCURRENCY in flight at once) instead of one at a time, so
     * total wall-clock time is governed by the slowest feed rather than the
     * sum of all of them. Results are returned in the original feed order.
     *
     * @return array<int, array{feed_id:string, status:string, new_items:int, error:?string}>
     */
    public static function refreshAll(bool $force = false): array
    {
        $feedsData = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
        $feeds = $feedsData['feeds'];

        $results = [];
        $toFetch = [];
        foreach ($feeds as $i => $feed) {
            if (($feed['enabled'] ?? true) === false) {
                $results[$i] = ['feed_id' => $feed['id'], 'status' => 'disabled', 'new_items' => 0, 'error' => null];
                continue;
            }
            if (!$force && !self::isDue($feed)) {
                $results[$i] = ['feed_id' => $feed['id'], 'status' => 'skipped', 'new_items' => 0, 'error' => null];
                continue;
            }
            // See the comment in refreshFeed(): a forced refresh also skips conditional GET,
            // so a 304 can't hide a feed's <ttl> from ever being (re-)detected.
            $toFetch[$i] = [
                'url' => $feed['url'],
                'etag' => $force ? null : ($feed['etag'] ?? null),
                'lastModified' => $force ? null : ($feed['last_modified'] ?? null),
            ];
        }

        $fetchResults = FeedFetcher::fetchManyRaw($toFetch, FETCH_CONCURRENCY);

        foreach (array_keys($toFetch) as $i) {
            $feed = $feeds[$i];
            try {
                $outcome = self::processFetchResult($feed, $fetchResults[$i]);
            } catch (Throwable $e) {
                $outcome = ['status' => 'error', 'new_items' => 0, 'error' => $e->getMessage()];
            }
            $results[$i] = array_merge(['feed_id' => $feed['id']], $outcome);
        }

        ksort($results);
        return array_values($results);
    }

    private static function isDue(array $feed): bool
    {
        if (empty($feed['last_fetched'])) {
            return true;
        }
        $interval = (int) ($feed['refresh_interval_minutes'] ?? DEFAULT_REFRESH_INTERVAL_MINUTES);
        $lastFetched = strtotime($feed['last_fetched']);
        if ($lastFetched === false) {
            return true;
        }
        return (time() - $lastFetched) >= ($interval * 60);
    }

    private static function updateFeedMeta(string $feedId, callable $mutator): void
    {
        Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($feedId, $mutator) {
            foreach ($data['feeds'] as $i => $f) {
                if ($f['id'] === $feedId) {
                    $data['feeds'][$i] = $mutator($f);
                    break;
                }
            }
            return $data;
        });
    }

    /**
     * @param array<int, array{guid:?string,link:?string,title:?string,published:?string,summary:?string,image:?string}> $parsedItems
     * @param string[] $filters
     */
    private static function mergeItems(string $feedId, array $parsedItems, array $filters = []): int
    {
        $newCount = 0;
        $path = items_file_path($feedId);
        $default = ['feed_id' => $feedId, 'items' => [], 'evicted_ids' => []];

        Storage::update($path, $default, function (array $data) use ($parsedItems, $filters, &$newCount) {
            $byId = [];
            foreach ($data['items'] as $item) {
                $byId[$item['id']] = $item;
            }
            $evictedSet = array_flip($data['evicted_ids'] ?? []);

            foreach ($parsedItems as $parsed) {
                $id = item_id_for($parsed['guid'], $parsed['link'], $parsed['title'], $parsed['published']);
                if (isset($byId[$id])) {
                    // Backfill a thumbnail if the stored item doesn't have one yet but
                    // this fetch found one (e.g. the source added an image after the
                    // item was first published) — everything else about the stored
                    // item is left untouched.
                    if (empty($byId[$id]['image']) && !empty($parsed['image'])) {
                        $byId[$id]['image'] = $parsed['image'];
                    }
                    // Unlike the thumbnail above, summary is kept in sync on every
                    // refresh rather than only backfilled once: it's cheap to
                    // re-derive, feeds can legitimately edit it, and it lets a
                    // parser fix (e.g. reading YouTube's media:description) reach
                    // already-stored items without a separate migration script.
                    if (!empty($parsed['summary']) && $parsed['summary'] !== $byId[$id]['summary']) {
                        $byId[$id]['summary'] = $parsed['summary'];
                    }
                    continue;
                }
                if (self::matchesAnyFilter($parsed['title'] ?? '', $parsed['summary'] ?? '', $filters)) {
                    continue;
                }

                // If this id was soft-cap-evicted while read (see below), it's
                // reappearing rather than being new — don't resurface it as unread.
                $wasEvicted = isset($evictedSet[$id]);

                $byId[$id] = [
                    'id' => $id,
                    'guid' => $parsed['guid'],
                    'link' => $parsed['link'],
                    'title' => $parsed['title'] ?? '(untitled)',
                    'published' => $parsed['published'],
                    'summary' => $parsed['summary'],
                    'image' => $parsed['image'] ?? null,
                    'read' => $wasEvicted,
                    'fetched_at' => now_iso8601(),
                ];

                if ($wasEvicted) {
                    unset($evictedSet[$id]);
                } else {
                    $newCount++;
                }
            }

            $items = array_values($byId);

            if (count($items) > MAX_ITEMS_PER_FEED) {
                usort($items, fn ($a, $b) => strcmp((string) $a['published'], (string) $b['published']));

                // Soft cap: drop oldest *read* items first, keeping every unread item.
                $newlyEvicted = [];
                $overflow = count($items) - MAX_ITEMS_PER_FEED;
                foreach ($items as $i => $item) {
                    if ($overflow <= 0) {
                        break;
                    }
                    if (!empty($item['read'])) {
                        $newlyEvicted[] = $item['id'];
                        unset($items[$i]);
                        $overflow--;
                    }
                }
                $items = array_values($items);
                $evictedSet = array_flip(array_slice(
                    array_values(array_unique(array_merge(array_keys($evictedSet), $newlyEvicted))),
                    -self::EVICTED_IDS_LIMIT
                ));

                // Hard cap: absolute safety net if a feed accumulates more unread
                // items than the soft cap alone can prune (nothing left is safe to
                // drop) — items are already sorted oldest-first at this point.
                // Not tombstoned: hard-cap drops can include unread items, so a
                // reappearance surfacing as unread again later is correct here.
                if (count($items) > HARD_MAX_ITEMS_PER_FEED) {
                    $items = array_slice($items, count($items) - HARD_MAX_ITEMS_PER_FEED);
                }
            }

            $data['items'] = $items;
            $data['evicted_ids'] = array_keys($evictedSet);
            return $data;
        });

        return $newCount;
    }

    /** @param string[] $filters */
    private static function matchesAnyFilter(string $title, string $summary, array $filters): bool
    {
        if (empty($filters)) {
            return false;
        }
        $haystack = strtolower(strip_tags($title . ' ' . $summary));
        foreach ($filters as $filter) {
            $needle = strtolower(trim((string) $filter));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
