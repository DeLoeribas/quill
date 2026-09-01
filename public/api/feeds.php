<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/RefreshService.php';
require_once __DIR__ . '/../../src/FeedDiscovery.php';
require_once __DIR__ . '/../../src/YouTubeResolver.php';

$method = $_SERVER['REQUEST_METHOD'];
Auth::requireLogin();

if ($method === 'GET') {
    // Fallback safety net: seed_default_feeds_if_empty() is a no-op once FEEDS_FILE
    // exists, so this only ever does anything on an install whose account was created
    // before FEEDS_FILE existed (e.g. auth.json shipped from an earlier deploy that
    // predated data/default-feeds.json, so the one-time seed in auth.php never ran).
    foreach (seed_default_feeds_if_empty() as $feedId) {
        try {
            RefreshService::refreshFeed($feedId, true);
        } catch (\Throwable $e) {
            // Best-effort: a network hiccup here shouldn't break loading the feed list.
        }
    }

    $data = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => [], 'saved_searches' => []]);
    $feeds = array_map(function ($feed) {
        $feed['unread_count'] = unread_count_for($feed['id']);
        $feed['item_count'] = item_count_for($feed['id']);
        $feed['starred_count'] = starred_count_for($feed['id']);
        return $feed;
    }, $data['feeds']);
    $savedSearches = array_map(function ($ss) use ($feeds) {
        $ss['match_count'] = match_count_for_query($ss['query'], $feeds);
        return $ss;
    }, $data['saved_searches'] ?? []);
    json_response(['folders' => sort_folders($data['folders']), 'feeds' => $feeds, 'saved_searches' => $savedSearches]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $url = trim((string) ($body['url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        json_error('a valid url is required');
    }
    $folderId = $body['folder_id'] ?? null;

    // If this is a YouTube channel page URL (handle, /channel/, /c/, or /user/),
    // resolve it to the channel's actual Atom feed URL so users can paste the
    // channel page they have instead of needing to know the feeds.xml pattern.
    $youtube = YouTubeResolver::resolve($url);
    if ($youtube !== null) {
        $url = $youtube->feedUrl;
    }

    $feedId = feed_id_for_url($url);

    // Fast-path dupe check (read-only) before any network fetch, so
    // re-submitting an already-tracked (possibly errored) feed's URL still
    // gets the expected 409 instead of running into the discovery flow
    // below. The atomic check inside Storage::update further down remains
    // the real authority against a race between two concurrent adds.
    $existing = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
    foreach ($existing['feeds'] as $f) {
        if ($f['id'] === $feedId) {
            json_error('a feed with this url already exists', 409);
        }
    }

    // If this URL isn't already a feed, see if the page declares one via
    // <link rel="alternate"> so we can offer it instead of creating a feed
    // that will just sit in an error state forever.
    $probe = FeedFetcher::fetchRaw($url, null, null);
    if ($probe->error !== null || $probe->httpCode < 200 || $probe->httpCode >= 300 || $probe->body === null) {
        // A curl-level error (DNS failure, connection refused, timeout) means the whole
        // host is unreachable — trying well-known paths on it too would just add several
        // more timeouts for nothing, so only fall back to those on an HTTP-level failure
        // (we did reach a server, it just wouldn't serve us this particular page).
        if ($probe->error !== null) {
            json_error('Could not reach this URL: ' . $probe->error);
        }
        $wellKnown = FeedDiscovery::probeWellKnownPaths($url, FETCH_CONCURRENCY);
        if ($wellKnown !== []) {
            json_response(['candidates' => $wellKnown]);
        }
        // 401/403/429 on the page itself (as opposed to the feed URL, which is often
        // exempt) usually means the site's bot/WAF protection is blocking this fetch —
        // that's not something we can retry our way past, so point the user at the one
        // thing that reliably works: the feed's own direct URL, if they have it.
        $hint = in_array($probe->httpCode, [401, 403, 429], true)
            ? ' — this site may be blocking automated requests to this page. If you know the direct feed URL (often ending in .xml, or /feed, /rss), try entering that instead.'
            : '';
        json_error('This page returned HTTP ' . $probe->httpCode . $hint);
    }
    if (FeedFetcher::parse($probe->body) === null) {
        $candidates = FeedDiscovery::discover(substr($probe->body, 0, 262144), $url);
        if ($candidates === []) {
            $candidates = FeedDiscovery::probeWellKnownPaths($url, FETCH_CONCURRENCY);
        }
        if ($candidates !== []) {
            json_response(['candidates' => $candidates]);
        }
        json_error('This URL is not a feed, and no feed links were found on the page');
    }

    $alreadyExists = false;

    $data = Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($feedId, $url, $folderId, $youtube, &$alreadyExists) {
        foreach ($data['feeds'] as $f) {
            if ($f['id'] === $feedId) {
                $alreadyExists = true;
                return $data;
            }
        }

        if ($folderId !== null && !in_array($folderId, array_column($data['folders'], 'id'), true)) {
            $folderId = null;
        }

        $data['feeds'][] = [
            'id' => $feedId,
            'url' => $url,
            'title' => $url,
            'site_url' => null,
            'favicon_url' => $youtube->avatarUrl ?? null,
            'image_url' => null,
            'folder_id' => $folderId,
            'enabled' => true,
            'filters' => [],
            'refresh_interval_minutes' => default_refresh_interval_from($data),
            'last_fetched' => null,
            'last_status' => null,
            'last_error' => null,
            'etag' => null,
            'last_modified' => null,
            'created_at' => now_iso8601(),
        ];
        return $data;
    });

    if ($alreadyExists) {
        json_error('a feed with this url already exists', 409);
    }

    RefreshService::refreshFeed($feedId, true);

    $refreshed = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
    $feed = null;
    foreach ($refreshed['feeds'] as $f) {
        if ($f['id'] === $feedId) {
            $feed = $f;
            $feed['unread_count'] = unread_count_for($feedId);
            $feed['item_count'] = item_count_for($feedId);
            $feed['starred_count'] = starred_count_for($feedId);
            break;
        }
    }

    json_response(['feed' => $feed], 201);
}

if ($method === 'PATCH') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('id is required');
    }
    $body = read_json_body();

    $found = false;
    $feed = null;
    $data = Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($id, $body, &$found, &$feed) {
        foreach ($data['feeds'] as $i => $f) {
            if ($f['id'] === $id) {
                if (array_key_exists('folder_id', $body)) {
                    $folderId = $body['folder_id'];
                    if ($folderId !== null && !in_array($folderId, array_column($data['folders'], 'id'), true)) {
                        json_error('unknown folder_id');
                    }
                    $data['feeds'][$i]['folder_id'] = $folderId;
                }
                if (array_key_exists('title', $body) && trim((string) $body['title']) !== '') {
                    $data['feeds'][$i]['title'] = trim((string) $body['title']);
                }
                if (array_key_exists('enabled', $body)) {
                    $data['feeds'][$i]['enabled'] = (bool) $body['enabled'];
                }
                if (array_key_exists('filters', $body)) {
                    $data['feeds'][$i]['filters'] = normalize_filters($body['filters']);
                }
                if (array_key_exists('refresh_interval_minutes', $body) && empty($data['feeds'][$i]['refresh_interval_locked'])) {
                    $minutes = (int) $body['refresh_interval_minutes'];
                    if ($minutes > 0) {
                        $data['feeds'][$i]['refresh_interval_minutes'] = $minutes;
                    }
                }
                $found = true;
                $feed = $data['feeds'][$i];
                break;
            }
        }
        return $data;
    });

    if (!$found) {
        json_error('feed not found', 404);
    }
    $feed['unread_count'] = unread_count_for($id);
    $feed['item_count'] = item_count_for($id);
    $feed['starred_count'] = starred_count_for($id);
    json_response(['feed' => $feed]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('id is required');
    }

    $found = false;
    Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($id, &$found) {
        $before = count($data['feeds']);
        $data['feeds'] = array_values(array_filter($data['feeds'], fn ($f) => $f['id'] !== $id));
        $found = count($data['feeds']) < $before;
        return $data;
    });

    if (!$found) {
        json_error('feed not found', 404);
    }

    $itemsPath = items_file_path($id);
    if (is_file($itemsPath)) {
        unlink($itemsPath);
    }

    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
