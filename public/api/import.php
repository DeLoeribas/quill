<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/OpmlImporter.php';

const MAX_OPML_BYTES = 2 * 1024 * 1024;

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

Auth::requireLogin();

if (!isset($_FILES['opml_file']) || $_FILES['opml_file']['error'] !== UPLOAD_ERR_OK) {
    json_error('opml_file upload is required');
}

$file = $_FILES['opml_file'];
if ($file['size'] > MAX_OPML_BYTES) {
    json_error('OPML file is too large');
}

$xml = file_get_contents($file['tmp_name']);
if ($xml === false) {
    json_error('Unable to read uploaded file');
}

try {
    $summary = OpmlImporter::import($xml);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage());
}

json_response(array_merge(['ok' => true], $summary));
