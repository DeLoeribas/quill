<?php

declare(strict_types=1);

final class FeedDiscovery
{
    private const FEED_TYPES = ['application/rss+xml', 'application/atom+xml', 'application/feed+json', 'application/json'];

    /** Conventional feed locations, tried as a last resort when a page can't be fetched
     *  (e.g. blocked by the site's own bot/WAF protection) or fetches fine but declares
     *  no <link rel="alternate"> feed of its own. Many sites that guard their HTML pages
     *  still serve the raw feed itself unprotected at one of these well-known paths. */
    private const WELL_KNOWN_PATHS = ['/feed', '/feed/', '/rss', '/rss/', '/rss.xml', '/feed.xml', '/atom.xml', '/index.xml'];

    /** @return array<int, array{url: string, title: string}> */
    public static function discover(string $html, string $baseUrl): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $origin = self::originOf($baseUrl);
        if ($origin === null) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//link[@rel="alternate" and @href and @type]');

        $seen = [];
        $candidates = [];
        foreach ($nodes as $node) {
            $type = strtolower(trim($node->getAttribute('type')));
            if (!in_array($type, self::FEED_TYPES, true)) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $url = self::resolveUrl($href, $origin);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $candidates[] = ['url' => $url, 'title' => trim($node->getAttribute('title')) ?: $url];
        }

        return $candidates;
    }

    /**
     * Probes WELL_KNOWN_PATHS under $baseUrl's origin concurrently (via FeedFetcher's
     * existing curl_multi support, so this costs about one request's worth of wall time,
     * not eight) and returns whichever ones turn out to be a real, parseable feed.
     * @return array<int, array{url: string, title: string}>
     */
    public static function probeWellKnownPaths(string $baseUrl, int $concurrency): array
    {
        $origin = self::originOf($baseUrl);
        if ($origin === null) {
            return [];
        }

        $requests = [];
        foreach (self::WELL_KNOWN_PATHS as $path) {
            $requests[$path] = ['url' => $origin . $path, 'etag' => null, 'lastModified' => null];
        }

        $results = FeedFetcher::fetchManyRaw($requests, $concurrency);

        $candidates = [];
        foreach ($results as $path => $result) {
            if ($result->error !== null || $result->httpCode < 200 || $result->httpCode >= 300 || $result->body === null) {
                continue;
            }
            $parsed = FeedFetcher::parse($result->body);
            if ($parsed === null) {
                continue;
            }
            $candidates[] = ['url' => $origin . $path, 'title' => $parsed->feedTitle ?: ($origin . $path)];
        }

        return $candidates;
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    private static function resolveUrl(string $href, string $origin): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($origin, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        return $origin . '/' . $href;
    }
}
