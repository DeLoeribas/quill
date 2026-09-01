<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/RefreshService.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response([
        'configured' => Auth::isConfigured(),
        'authenticated' => Auth::isLoggedIn(),
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = $body['action'] ?? '';
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($action === 'setup') {
        if (Auth::isConfigured()) {
            json_error('Login is already configured', 409);
        }
        if ($username === '' || strlen($password) < 8) {
            json_error('Username is required and password must be at least 8 characters');
        }
        Auth::setup($username, $password);

        foreach (seed_default_feeds_if_empty() as $feedId) {
            try {
                RefreshService::refreshFeed($feedId, true);
            } catch (\Throwable $e) {
                // Best-effort: a network hiccup while seeding shouldn't break account creation.
            }
        }

        json_response(['ok' => true]);
    }

    if ($action === 'login') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::guardLogin($ip);
        if (!Auth::attempt($username, $password)) {
            RateLimiter::recordFailure($ip);
            json_error('Invalid username or password', 401);
        }
        RateLimiter::recordSuccess($ip);
        json_response(['ok' => true]);
    }

    if ($action === 'logout') {
        Auth::logout();
        json_response(['ok' => true]);
    }

    json_error('unknown action');
}

json_error('Method not allowed', 405);
