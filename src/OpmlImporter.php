<?php

declare(strict_types=1);

final class OpmlImporter
{
    /**
     * Replaces the entire feed/folder set with what's in the OPML file. Any
     * previously existing feed whose id (derived from its URL) does not
     * appear in the new OPML has its stored items deleted; a feed that
     * reappears (e.g. re-importing the same export) keeps its item file and
     * read/starred state untouched.
     *
     * @return array{folders_created:int, feeds_created:int, feeds_skipped:int, feeds_removed:int}
     */
    public static function import(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();

        if (!$ok || strtolower($doc->documentElement->localName ?? '') !== 'opml') {
            throw new InvalidArgumentException('File is not a valid OPML document');
        }

        $xpath = new DOMXPath($doc);
        $body = $xpath->query('/opml/body')->item(0);
        if ($body === null) {
            throw new InvalidArgumentException('OPML file has no <body>');
        }

        $foldersCreated = 0;
        $feedsCreated = 0;
        $feedsSkipped = 0;
        $removedFeedIds = [];

        $savedData = Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($body, &$foldersCreated, &$feedsCreated, &$feedsSkipped, &$removedFeedIds) {
            $removedFeedIds = array_column($data['feeds'], 'id');

            // Wipe existing folders/feeds but keep any other top-level keys (e.g. settings).
            $data['folders'] = [];
            $data['feeds'] = [];

            self::walk($body, null, $data, $foldersCreated, $feedsCreated, $feedsSkipped);
            return $data;
        });

        $newFeedIds = array_column($savedData['feeds'], 'id');
        $actuallyRemovedIds = array_diff($removedFeedIds, $newFeedIds);

        foreach ($actuallyRemovedIds as $feedId) {
            $path = items_file_path($feedId);
            if (is_file($path)) {
                unlink($path);
            }
        }

        return [
            'folders_created' => $foldersCreated,
            'feeds_created' => $feedsCreated,
            'feeds_skipped' => $feedsSkipped,
            'feeds_removed' => count($actuallyRemovedIds),
        ];
    }

    private static function walk(DOMElement $parent, ?string $folderId, array &$data, int &$foldersCreated, int &$feedsCreated, int &$feedsSkipped): void
    {
        foreach ($parent->childNodes as $node) {
            if (!($node instanceof DOMElement) || strtolower($node->tagName) !== 'outline') {
                continue;
            }

            $xmlUrl = trim((string) $node->getAttribute('xmlUrl'));

            if ($xmlUrl !== '') {
                $feedId = feed_id_for_url($xmlUrl);
                $exists = false;
                foreach ($data['feeds'] as $f) {
                    if ($f['id'] === $feedId) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) {
                    $feedsSkipped++;
                    continue;
                }

                $title = trim((string) $node->getAttribute('title')) ?: trim((string) $node->getAttribute('text')) ?: $xmlUrl;

                $data['feeds'][] = [
                    'id' => $feedId,
                    'url' => $xmlUrl,
                    'title' => $title,
                    'site_url' => trim((string) $node->getAttribute('htmlUrl')) ?: null,
                    'favicon_url' => null,
                    'image_url' => null,
                    'folder_id' => $folderId,
                    'enabled' => true,
                    'filters' => [],
                    'refresh_interval_minutes' => default_refresh_interval_from($data),
                    'last_fetched' => null,
                    'last_status' => null,
                    'last_error' => null,
                    'etag' => null,
                    'last_modified' => null,
                    'created_at' => now_iso8601(),
                ];
                $feedsCreated++;
                continue;
            }

            $folderName = trim((string) $node->getAttribute('title')) ?: trim((string) $node->getAttribute('text'));
            if ($folderName === '') {
                // Neither a feed nor a named folder: skip node but still walk children at the current level.
                self::walk($node, $folderId, $data, $foldersCreated, $feedsCreated, $feedsSkipped);
                continue;
            }

            // Only materialize one level of folders: a folder-outline nested inside
            // another folder-outline flattens into that nearest ancestor folder
            // rather than creating a sub-folder.
            if ($folderId !== null) {
                self::walk($node, $folderId, $data, $foldersCreated, $feedsCreated, $feedsSkipped);
                continue;
            }

            $existingFolderId = null;
            foreach ($data['folders'] as $f) {
                if (strcasecmp(trim($f['name']), $folderName) === 0) {
                    $existingFolderId = $f['id'];
                    break;
                }
            }

            if ($existingFolderId === null) {
                $existingFolderId = new_folder_id();
                $data['folders'][] = [
                    'id' => $existingFolderId,
                    'name' => $folderName,
                    'order' => count($data['folders']),
                ];
                $foldersCreated++;
            }

            self::walk($node, $existingFolderId, $data, $foldersCreated, $feedsCreated, $feedsSkipped);
        }
    }
}
