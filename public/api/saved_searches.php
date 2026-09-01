<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
Auth::requireLogin();

if ($method === 'POST') {
    $body = read_json_body();
    $name = trim((string) ($body['name'] ?? ''));
    $query = trim((string) ($body['query'] ?? ''));
    if ($name === '') {
        json_error('name is required');
    }
    if ($query === '') {
        json_error('query is required');
    }

    $saved = null;
    Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => [], 'saved_searches' => []], function (array $data) use ($name, $query, &$saved) {
        $saved = [
            'id' => new_saved_search_id(),
            'name' => $name,
            'query' => $query,
            'created_at' => now_iso8601(),
        ];
        $data['saved_searches'][] = $saved;
        return $data;
    });

    json_response(['saved_search' => $saved], 201);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('id is required');
    }

    $found = false;
    Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => [], 'saved_searches' => []], function (array $data) use ($id, &$found) {
        $before = count($data['saved_searches'] ?? []);
        $data['saved_searches'] = array_values(array_filter($data['saved_searches'] ?? [], fn ($s) => $s['id'] !== $id));
        $found = count($data['saved_searches']) < $before;
        return $data;
    });

    if (!$found) {
        json_error('saved search not found', 404);
    }

    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
