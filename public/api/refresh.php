<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/RefreshService.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

Auth::requireLogin();
set_time_limit(0);

$body = read_json_body();
$force = !empty($body['force']);
$onlyFeedId = $body['feed_id'] ?? null;

if ($onlyFeedId !== null) {
    $outcome = RefreshService::refreshFeed($onlyFeedId, true);
    if ($outcome['status'] === 'error' && $outcome['error'] === 'feed not found') {
        json_error('feed not found', 404);
    }
    $results = [array_merge(['feed_id' => $onlyFeedId], $outcome)];
} else {
    $results = RefreshService::refreshAll($force);
}

json_response(['ok' => true, 'results' => $results]);
