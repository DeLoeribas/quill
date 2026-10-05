<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
Auth::requireLogin();

// Every item that has at least one text highlight — just enough to list them in
// the sidebar's Highlights section (newest first).
if ($method === 'GET') {
    $feedsData = Storage::read(feeds_file(), ['folders' => [], 'feeds' => []]);

    $items = [];
    foreach ($feedsData['feeds'] as $feed) {
        $itemsData = read_items_file($feed['id']);
        foreach ($itemsData['items'] as $item) {
            $count = count($item['highlights'] ?? []);
            if ($count === 0) {
                continue;
            }
            $items[] = [
                'id' => $item['id'],
                'feed_id' => $feed['id'],
                'title' => $item['title'] ?? '(untitled)',
                'published' => $item['published'] ?? null,
                'count' => $count,
            ];
        }
    }

    usort($items, fn ($a, $b) => strcmp((string) $b['published'], (string) $a['published']));

    json_response(['items' => $items]);
}

json_error('Method not allowed', 405);
