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
    // Cache only: going to GitHub here would hold up the whole page load whenever the
    // hourly cache has expired. The client asks for a fresh check (refresh_version) after rendering.
    $version = $isAdmin ? GithubVersionChecker::cachedStatus() : ['version' => APP_VERSION, 'latest' => null, 'notes' => null];
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
    $action = $body['action'] ?? null;
    // refresh_version: the background check after page load and every 15 minutes, which
    // respects the hourly cache. check_update: the manual button, which skips it.
    if ($action !== 'check_update' && $action !== 'refresh_version') {
        json_error('unknown action');
    }
    if (!$isAdmin) {
        if ($action === 'refresh_version') {
            json_response(['app_version' => APP_VERSION, 'latest_version' => null, 'update_notes' => null, 'dismissed_update_version' => null]);
        }
        json_error('Forbidden', 403);
    }
    $version = GithubVersionChecker::status($action === 'check_update');
    $prefs = Storage::read(feeds_file(), ['folders' => [], 'feeds' => []])['settings']['ui_prefs'] ?? [];
    json_response([
        'app_version' => $version['version'],
        'latest_version' => $version['latest'],
        'update_notes' => $version['notes'],
        // So a notice dismissed in another tab stays dismissed here too.
        'dismissed_update_version' => $prefs['dismissed_update_version'] ?? null,
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
