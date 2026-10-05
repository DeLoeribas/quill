<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/GithubVersionChecker.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = Auth::requireLogin();
$isAdmin = !empty($user['is_admin']);

if ($method === 'GET') {
    $data = Storage::read(feeds_file(), ['folders' => [], 'feeds' => []]);
    // Updating the app is the admin's job, so only they are told about (and offered) new versions.
    $version = $isAdmin ? GithubVersionChecker::status() : ['version' => APP_VERSION, 'latest' => null, 'notes' => null];
    json_response([
        'username' => $user['username'],
        'is_admin' => $isAdmin,
        'app_version' => $version['version'],
        'latest_version' => $version['latest'],
        'update_notes' => $version['notes'],
        'last_build_date' => last_build_date(),
        'server_name' => server_name(),
        'php_version' => phpversion(),
        'ui_prefs' => $data['settings']['ui_prefs'] ?? [],
    ]);
}

// The manual "Check for updates" in Settings. A POST rather than GET ?check=1 because
// an edge cache that ignores query strings could answer that from a cached GET.
if ($method === 'POST') {
    $body = read_json_body();
    if (($body['action'] ?? null) !== 'check_update') {
        json_error('unknown action');
    }
    if (!$isAdmin) {
        json_error('Forbidden', 403);
    }
    $version = GithubVersionChecker::status(true);
    json_response([
        'app_version' => $version['version'],
        'latest_version' => $version['latest'],
        'update_notes' => $version['notes'],
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

    $result = Storage::update(feeds_file(), ['folders' => [], 'feeds' => []], function (array $data) use ($body) {
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
