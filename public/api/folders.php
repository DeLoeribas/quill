<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
Auth::requireLogin();

if ($method === 'GET') {
    $data = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
    json_response(['folders' => sort_folders($data['folders'])]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        json_error('name is required');
    }

    $folder = null;
    $data = Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($name, &$folder) {
        foreach ($data['folders'] as $existing) {
            if (strcasecmp(trim($existing['name']), $name) === 0) {
                $folder = $existing;
                return $data;
            }
        }
        $folder = [
            'id' => new_folder_id(),
            'name' => $name,
            'order' => count($data['folders']),
        ];
        $data['folders'][] = $folder;
        return $data;
    });

    json_response(['folder' => $folder], 201);
}

if ($method === 'PATCH') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('id is required');
    }
    $body = read_json_body();
    $direction = $body['direction'] ?? null;

    if ($direction === 'up' || $direction === 'down') {
        $found = false;
        $data = Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($id, $direction, &$found) {
            $sorted = sort_folders($data['folders']);
            foreach ($sorted as $i => $f) {
                $sorted[$i]['order'] = $i;
            }

            $index = null;
            foreach ($sorted as $i => $f) {
                if ($f['id'] === $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                return $data;
            }
            $found = true;

            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
            if ($swapWith >= 0 && $swapWith < count($sorted)) {
                $tmp = $sorted[$index]['order'];
                $sorted[$index]['order'] = $sorted[$swapWith]['order'];
                $sorted[$swapWith]['order'] = $tmp;
            }

            $data['folders'] = $sorted;
            return $data;
        });

        if (!$found) {
            json_error('folder not found', 404);
        }
        json_response(['folders' => sort_folders($data['folders'])]);
    }

    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        json_error('name is required');
    }

    $folder = null;
    $found = false;
    $data = Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($id, $name, &$folder, &$found) {
        foreach ($data['folders'] as $i => $existing) {
            if ($existing['id'] === $id) {
                $data['folders'][$i]['name'] = $name;
                $folder = $data['folders'][$i];
                $found = true;
                break;
            }
        }
        return $data;
    });

    if (!$found) {
        json_error('folder not found', 404);
    }
    json_response(['folder' => $folder]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_error('id is required');
    }
    $cascade = !empty($_GET['cascade']);

    $found = false;
    $removedFeedIds = [];
    Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($id, $cascade, &$found, &$removedFeedIds) {
        $before = count($data['folders']);
        $data['folders'] = array_values(array_filter($data['folders'], fn ($f) => $f['id'] !== $id));
        $found = count($data['folders']) < $before;

        if ($cascade) {
            foreach ($data['feeds'] as $feed) {
                if (($feed['folder_id'] ?? null) === $id) {
                    $removedFeedIds[] = $feed['id'];
                }
            }
            $data['feeds'] = array_values(array_filter($data['feeds'], fn ($f) => ($f['folder_id'] ?? null) !== $id));
        } else {
            foreach ($data['feeds'] as $i => $feed) {
                if (($feed['folder_id'] ?? null) === $id) {
                    $data['feeds'][$i]['folder_id'] = null;
                }
            }
        }

        return $data;
    });

    if (!$found) {
        json_error('folder not found', 404);
    }

    foreach ($removedFeedIds as $feedId) {
        $itemsPath = items_file_path($feedId);
        if (is_file($itemsPath)) {
            unlink($itemsPath);
        }
    }

    json_response(['ok' => true, 'feeds_removed' => count($removedFeedIds)]);
}

json_error('Method not allowed', 405);
