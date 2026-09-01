<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    json_error('Method not allowed', 405);
}

Auth::requireLogin();

$data = Storage::read(FEEDS_FILE, ['folders' => [], 'feeds' => []]);

$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;

$opml = $doc->createElement('opml');
$opml->setAttribute('version', '2.0');
$doc->appendChild($opml);

$head = $doc->createElement('head');
$title = $doc->createElement('title');
$title->appendChild($doc->createTextNode('Quill feeds'));
$head->appendChild($title);
$opml->appendChild($head);

$body = $doc->createElement('body');
$opml->appendChild($body);

$folderOutlines = [];
foreach ($data['folders'] as $folder) {
    $outline = $doc->createElement('outline');
    $outline->setAttribute('text', $folder['name']);
    $outline->setAttribute('title', $folder['name']);
    $body->appendChild($outline);
    $folderOutlines[$folder['id']] = $outline;
}

foreach ($data['feeds'] as $feed) {
    $outline = $doc->createElement('outline');
    $outline->setAttribute('text', $feed['title']);
    $outline->setAttribute('title', $feed['title']);
    $outline->setAttribute('type', 'rss');
    $outline->setAttribute('xmlUrl', $feed['url']);
    if (!empty($feed['site_url'])) {
        $outline->setAttribute('htmlUrl', $feed['site_url']);
    }

    $parent = $body;
    if ($feed['folder_id'] !== null && isset($folderOutlines[$feed['folder_id']])) {
        $parent = $folderOutlines[$feed['folder_id']];
    }
    $parent->appendChild($outline);
}

header('Content-Type: text/x-opml+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="feeds.opml"');
echo $doc->saveXML();
