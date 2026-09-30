<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/UpdatePackager.php';

// POST as well as GET for the same reason as backup.php: a host's edge cache that
// ignores query strings could otherwise hand back a stale package. The client POSTs.
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    json_error('Method not allowed', 405);
}

Auth::requireLogin();

try {
    $package = UpdatePackager::build();
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 502);
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="quill-' . $package['version'] . '.zip"');
header('Content-Length: ' . filesize($package['path']));
header('Cache-Control: no-store');
readfile($package['path']);
unlink($package['path']);
