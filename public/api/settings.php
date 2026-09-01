<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
Auth::requireLogin();

if ($method === 'GET') {
    $data = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);
    json_response([
        'last_build_date' => last_build_date(),
        'server_name' => server_name(),
        'php_version' => phpversion(),
        'ui_prefs' => $data['settings']['ui_prefs'] ?? [],
    ]);
}

if ($method === 'PATCH') {
    $body = read_json_body();
    $hasUiPrefs = array_key_exists('ui_prefs', $body);

    if (!$hasUiPrefs) {
        json_error('nothing to update');
    }

    if (!is_array($body['ui_prefs'])) {
        json_error('ui_prefs must be an object');
    }

    $result = Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($body) {
        $data['settings'] = $data['settings'] ?? [];

        $current = $data['settings']['ui_prefs'] ?? [];
        $data['settings']['ui_prefs'] = array_merge($current, sanitize_ui_prefs($body['ui_prefs']));

        return $data;
    });

    json_response([
        'ui_prefs' => $result['settings']['ui_prefs'] ?? [],
    ]);
}

json_error('Method not allowed', 405);
