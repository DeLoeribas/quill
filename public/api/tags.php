<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
Auth::requireLogin();

if ($method === 'GET') {
    $feedsData = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);

    $counts = [];
    foreach ($feedsData['feeds'] as $feed) {
        $itemsData = read_items_file($feed['id']);
        foreach ($itemsData['items'] as $item) {
            foreach ($item['tags'] ?? [] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
    }

    ksort($counts);
    $tags = [];
    foreach ($counts as $name => $count) {
        $tags[] = ['name' => $name, 'count' => $count];
    }

    json_response(['tags' => $tags]);
}

json_error('Method not allowed', 405);
