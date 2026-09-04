<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
Auth::requireLogin();

if ($method === 'GET') {
    $feedsData = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
    $feedTitleById = array_column($feedsData['feeds'], 'title', 'id');

    $feedId = $_GET['feed_id'] ?? null;
    $folderId = $_GET['folder_id'] ?? null;
    $unreadOnly = !empty($_GET['unread_only']);
    $starredOnly = !empty($_GET['starred_only']);
    $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : null;
    $query = trim((string) ($_GET['q'] ?? ''));
    $queryPatterns = search_query_patterns($query);
    $tag = strtolower(trim((string) ($_GET['tag'] ?? '')));
    $order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

    $feedIds = [];
    if ($query !== '' || $tag !== '') {
        // Global search/tag browse: ignore feed/folder scoping, scan every feed's items.
        $feedIds = array_column($feedsData['feeds'], 'id');
    } elseif ($feedId !== null) {
        $feedIds = [$feedId];
    } elseif ($folderId !== null) {
        foreach ($feedsData['feeds'] as $f) {
            if (($f['folder_id'] ?? null) === $folderId) {
                $feedIds[] = $f['id'];
            }
        }
    } else {
        $feedIds = array_column($feedsData['feeds'], 'id');
    }

    $items = [];
    foreach ($feedIds as $fid) {
        $itemsData = read_items_file($fid);
        foreach ($itemsData['items'] as $item) {
            if ($unreadOnly && !empty($item['read'])) {
                continue;
            }
            if ($starredOnly && empty($item['starred'])) {
                continue;
            }
            if ($tag !== '' && !in_array($tag, $item['tags'] ?? [], true)) {
                continue;
            }
            $feedTitle = $feedTitleById[$fid] ?? null;
            if (!item_matches_query($item, $feedTitle, $queryPatterns)) {
                continue;
            }
            $item['feed_id'] = $fid;
            $item['feed_title'] = $feedTitle;
            $items[] = $item;
        }
    }

    usort($items, fn ($a, $b) => $order === 'asc'
        ? strcmp((string) $a['published'], (string) $b['published'])
        : strcmp((string) $b['published'], (string) $a['published']));

    if ($limit !== null) {
        $items = array_slice($items, 0, $limit);
    }

    json_response(['items' => $items]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = $body['action'] ?? '';

    $feedsData = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);

    if ($action === 'mark_read' || $action === 'mark_unread') {
        $itemIds = $body['item_ids'] ?? [];
        if (!is_array($itemIds) || $itemIds === []) {
            json_error('item_ids is required');
        }
        $readValue = $action === 'mark_read';

        $updated = 0;
        foreach ($feedsData['feeds'] as $feed) {
            $path = items_file_path($feed['id']);
            if (!is_file($path)) {
                continue;
            }
            $changedInThisFeed = 0;
            Storage::update($path, ['feed_id' => $feed['id'], 'items' => []], function (array $data) use ($itemIds, $readValue, &$changedInThisFeed) {
                foreach ($data['items'] as $i => $item) {
                    if (in_array($item['id'], $itemIds, true) && (bool) ($item['read'] ?? false) !== $readValue) {
                        $data['items'][$i]['read'] = $readValue;
                        $changedInThisFeed++;
                    }
                }
                return $data;
            });
            $updated += $changedInThisFeed;
        }

        json_response(['ok' => true, 'updated' => $updated]);
    }

    if ($action === 'star' || $action === 'unstar') {
        $itemIds = $body['item_ids'] ?? [];
        if (!is_array($itemIds) || $itemIds === []) {
            json_error('item_ids is required');
        }
        $starredValue = $action === 'star';

        $updated = 0;
        foreach ($feedsData['feeds'] as $feed) {
            $path = items_file_path($feed['id']);
            if (!is_file($path)) {
                continue;
            }
            $changedInThisFeed = 0;
            Storage::update($path, ['feed_id' => $feed['id'], 'items' => []], function (array $data) use ($itemIds, $starredValue, &$changedInThisFeed) {
                foreach ($data['items'] as $i => $item) {
                    if (in_array($item['id'], $itemIds, true) && (bool) ($item['starred'] ?? false) !== $starredValue) {
                        $data['items'][$i]['starred'] = $starredValue;
                        $changedInThisFeed++;
                    }
                }
                return $data;
            });
            $updated += $changedInThisFeed;
        }

        json_response(['ok' => true, 'updated' => $updated]);
    }

    if ($action === 'set_comment') {
        $feedId = $body['feed_id'] ?? null;
        $itemId = $body['item_id'] ?? null;
        $comment = trim((string) ($body['comment'] ?? ''));
        if (!$feedId || !$itemId) {
            json_error('feed_id and item_id are required');
        }

        $path = items_file_path($feedId);
        if (!is_file($path)) {
            json_error('feed not found', 404);
        }

        $found = false;
        Storage::update($path, ['feed_id' => $feedId, 'items' => []], function (array $data) use ($itemId, $comment, &$found) {
            foreach ($data['items'] as $i => $item) {
                if ($item['id'] === $itemId) {
                    $data['items'][$i]['comment'] = $comment === '' ? null : $comment;
                    $found = true;
                    break;
                }
            }
            return $data;
        });

        if (!$found) {
            json_error('item not found', 404);
        }

        json_response(['ok' => true, 'comment' => $comment === '' ? null : $comment]);
    }

    if ($action === 'set_tags') {
        $feedId = $body['feed_id'] ?? null;
        $itemId = $body['item_id'] ?? null;
        $rawTags = $body['tags'] ?? [];
        if (!$feedId || !$itemId) {
            json_error('feed_id and item_id are required');
        }
        if (!is_array($rawTags)) {
            json_error('tags must be an array');
        }

        $tags = [];
        foreach ($rawTags as $rawTag) {
            $normalized = strtolower(trim((string) $rawTag));
            if ($normalized !== '' && !in_array($normalized, $tags, true)) {
                $tags[] = $normalized;
            }
        }

        $path = items_file_path($feedId);
        if (!is_file($path)) {
            json_error('feed not found', 404);
        }

        $found = false;
        Storage::update($path, ['feed_id' => $feedId, 'items' => []], function (array $data) use ($itemId, $tags, &$found) {
            foreach ($data['items'] as $i => $item) {
                if ($item['id'] === $itemId) {
                    $data['items'][$i]['tags'] = $tags;
                    $found = true;
                    break;
                }
            }
            return $data;
        });

        if (!$found) {
            json_error('item not found', 404);
        }

        json_response(['ok' => true, 'tags' => $tags]);
    }

    if ($action === 'mark_all_read') {
        $feedId = $body['feed_id'] ?? null;
        $folderId = $body['folder_id'] ?? null;

        $targetFeedIds = [];
        if ($feedId !== null) {
            $targetFeedIds = [$feedId];
        } elseif ($folderId !== null) {
            foreach ($feedsData['feeds'] as $f) {
                if (($f['folder_id'] ?? null) === $folderId) {
                    $targetFeedIds[] = $f['id'];
                }
            }
        } else {
            $targetFeedIds = array_column($feedsData['feeds'], 'id');
        }

        $updated = 0;
        foreach ($targetFeedIds as $fid) {
            $path = items_file_path($fid);
            if (!is_file($path)) {
                continue;
            }
            $changedInThisFeed = 0;
            Storage::update($path, ['feed_id' => $fid, 'items' => []], function (array $data) use (&$changedInThisFeed) {
                foreach ($data['items'] as $i => $item) {
                    if (empty($item['read'])) {
                        $data['items'][$i]['read'] = true;
                        $changedInThisFeed++;
                    }
                }
                return $data;
            });
            $updated += $changedInThisFeed;
        }

        json_response(['ok' => true, 'updated' => $updated]);
    }

    json_error('unknown action');
}

json_error('Method not allowed', 405);
