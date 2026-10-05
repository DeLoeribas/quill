<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

// Account management for admins: list, add, reset password, delete. A new
// account's default feeds are seeded on its first feeds.php GET, like a fresh install.

$method = $_SERVER['REQUEST_METHOD'];
$me = Auth::requireAdmin();

function public_user(array $user): array
{
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'is_admin' => !empty($user['is_admin']),
        'created_at' => $user['created_at'] ?? null,
    ];
}

if ($method === 'GET') {
    json_response(['users' => array_map('public_user', Users::all())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = $body['action'] ?? '';
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            $user = Users::create(
                trim((string) ($body['username'] ?? '')),
                (string) ($body['password'] ?? ''),
                !empty($body['is_admin'])
            );
            json_response(['user' => public_user($user)]);
        }

        if ($action === 'reset_password') {
            Users::setPassword($id, (string) ($body['password'] ?? ''));
            json_response(['ok' => true]);
        }

        if ($action === 'delete') {
            if ($id === $me['id']) {
                json_error('You cannot delete your own account');
            }
            Users::delete($id);
            json_response(['ok' => true]);
        }
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    }

    json_error('unknown action');
}

json_error('Method not allowed', 405);
