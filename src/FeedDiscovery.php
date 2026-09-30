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

    /** Hosts that only ever serve feeds, so any link to them is worth checking. */
    private const FEED_HOSTS = ['feeds.feedburner.com', 'feeds2.feedburner.com', 'feeds.feedblitz.com', 'feedpress.me', 'rss.app'];

    /** Cap on how many <a> candidates get fetched, so a page full of "feed" links
     *  (e.g. a category page per topic) can't turn one add into dozens of requests. */
    private const MAX_ANCHOR_CANDIDATES = 10;

    /** A feed-directory page ("/rss-feeds") exists to list feeds, so it gets a bigger cap. */
    private const MAX_DIRECTORY_CANDIDATES = 40;

    /** Top-level pages that typically list a site's feeds, e.g. /rss, /feeds, /rss-feeds.
     *  Only a single path segment, so deep pages that merely end in "/rss" (a category
     *  called "RSS", say) aren't mistaken for one. */
    private const DIRECTORY_PATH = '#^/(rss|feeds?|rss[-_]?feeds?)/?$#i';

    /** How many of a root feed's most common sections get probed for their own feed. */
    private const MAX_SECTION_PROBES = 20;

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
            $url = self::resolveUrl($href, $baseUrl);
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
     * not eight) and returns whichever ones turn out to be a real, parseable feed —
     * followed by any per-section feeds found next to it (see expandSectionFeeds()).
     * @return array<int, array{url: string, title: string}>
     */
    public static function probeWellKnownPaths(string $baseUrl, int $concurrency): array
    {
        $origin = self::originOf($baseUrl);
        if ($origin === null) {
            return [];
        }

        $urls = [];
        foreach (self::WELL_KNOWN_PATHS as $path) {
            $urls[] = $origin . $path;
        }

        $feeds = self::fetchAndClassify($urls, $concurrency)['feeds'];
        if ($feeds === []) {
            return [];
        }

        $sections = self::expandSectionFeeds($feeds[0]['url'], $feeds[0]['parsed'], $concurrency);
        return self::withoutParsed(array_merge($feeds, $sections));
    }

    /**
     * Many news sites publish a feed per section next to their main one, following
     * the main feed's own naming: /rss.xml alongside /tech/rss.xml, /binnenland/rss.xml,
     * … The list of those usually lives on an HTML page we may not be allowed to fetch
     * (Volkskrant's /rss-feeds sits behind the same WAF as its homepage), but the main
     * feed's item links reveal which sections exist, so probe <section><suffix> for the
     * most common ones and keep whichever turn out to be real feeds.
     * @return array<int, array{url: string, title: string, parsed: ParsedFeed}>
     */
    private static function expandSectionFeeds(string $feedUrl, ParsedFeed $parsed, int $concurrency): array
    {
        $origin = self::originOf($feedUrl);
        $suffix = parse_url($feedUrl, PHP_URL_PATH) ?: '';
        // Only a feed at the site root (/rss.xml, /feed) has a naming scheme we can
        // transplant under a section; /blog/feed.xml says nothing about /news/.
        if ($origin === null || !preg_match('#^/[^/]+/?$#', $suffix)) {
            return [];
        }
        // "volkskrant.nl/rss.xml" links its articles on www.volkskrant.nl, so a www.
        // prefix on either side still counts as the same site.
        $host = self::siteHost($feedUrl);

        $counts = [];
        foreach ($parsed->items as $item) {
            $link = $item['link'] ?? null;
            if (!is_string($link) || self::siteHost($link) !== $host) {
                continue;
            }
            $segments = explode('/', trim((string) parse_url($link, PHP_URL_PATH), '/'));
            // The first segment is only a section if the article lives below it, and
            // things like file names, "~b7417701"-style ids or years aren't sections.
            if (count($segments) < 2 || !preg_match('#^[a-z][a-z0-9-]*$#i', $segments[0])) {
                continue;
            }
            $counts[strtolower($segments[0])] = ($counts[strtolower($segments[0])] ?? 0) + 1;
        }
        if ($counts === []) {
            return [];
        }
        arsort($counts);

        $urls = [];
        foreach (array_slice(array_keys($counts), 0, self::MAX_SECTION_PROBES) as $segment) {
            $urls[] = $origin . '/' . $segment . $suffix;
        }

        $sections = self::fetchAndClassify($urls, $concurrency)['feeds'];
        usort($sections, fn ($a, $b) => strnatcasecmp($a['title'], $b['title']));
        return $sections;
    }

    /**
     * Fallback for pages that don't declare a <link rel="alternate"> feed but do link
     * to one from an ordinary <a> (typically an "RSS" link in the footer, often pointing
     * off-site to FeedBurner and friends). Anchors are picked by their href, type
     * attribute or link text, then every pick is fetched and parsed so only real feeds
     * come back — plenty of "/rss"-looking links are just HTML pages.
     *
     * One of those HTML pages may be the site's feed directory (/rss-feeds): that one is
     * read too, one hop only, and the feeds it lists are added after the direct ones.
     * @return array<int, array{url: string, title: string}>
     */
    public static function discoverFromAnchors(string $html, string $baseUrl, int $concurrency): array
    {
        if (self::originOf($baseUrl) === null) {
            return [];
        }

        $urls = self::feedLikeAnchors($html, $baseUrl, self::MAX_ANCHOR_CANDIDATES);
        if ($urls === []) {
            return [];
        }
        $result = self::fetchAndClassify($urls, $concurrency);
        $feeds = $result['feeds'];

        foreach ($result['pages'] as $pageUrl => $body) {
            if (!preg_match(self::DIRECTORY_PATH, parse_url($pageUrl, PHP_URL_PATH) ?: '')) {
                continue;
            }
            $pageHtml = substr($body, 0, 262144);
            $listed = array_column(self::discover($pageHtml, $pageUrl), 'url');
            $listed = array_merge($listed, self::feedLikeAnchors($pageHtml, $pageUrl, self::MAX_DIRECTORY_CANDIDATES));
            $known = array_column($feeds, 'url');
            $listed = array_values(array_diff(array_unique($listed), $known));
            $listed = array_slice($listed, 0, self::MAX_DIRECTORY_CANDIDATES);
            if ($listed !== []) {
                $feeds = array_merge($feeds, self::fetchAndClassify($listed, $concurrency)['feeds']);
            }
            break;
        }

        return self::withoutParsed($feeds);
    }

    /** @return array<int, string> resolved URLs of <a> links that look like they point at a feed */
    private static function feedLikeAnchors(string $html, string $baseUrl, int $max): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//a[@href]');

        $urls = [];
        foreach ($nodes as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|javascript|tel|data):#i', $href)) {
                continue;
            }
            $url = self::resolveUrl($href, $baseUrl);
            if (isset($urls[$url]) || !self::looksLikeFeedLink($node, $url)) {
                continue;
            }
            $urls[$url] = true;
            if (count($urls) >= $max) {
                break;
            }
        }

        return array_keys($urls);
    }

    private static function looksLikeFeedLink(DOMElement $node, string $url): bool
    {
        if (in_array(strtolower(trim($node->getAttribute('type'))), self::FEED_TYPES, true)) {
            return true;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, self::FEED_HOSTS, true)) {
            return true;
        }
        $path = $parts['path'] ?? '';
        if (preg_match('#(/feed|/rss|/atom)(/|\.xml)?$|\.(rss|atom|xml)$#i', $path) || preg_match(self::DIRECTORY_PATH, $path)) {
            return true;
        }
        if (preg_match('#(^|&)(feed|format)=(rss|atom)#i', $parts['query'] ?? '')) {
            return true;
        }
        $label = $node->textContent . ' ' . $node->getAttribute('title') . ' ' . $node->getAttribute('aria-label');
        return (bool) preg_match('/\b(rss|atom|feed)\b/i', $label);
    }

    /**
     * Fetches $urls concurrently (via FeedFetcher's curl_multi support) and splits the
     * successful responses into real, parseable feeds (in the given order) and other
     * pages, whose bodies callers may want to look inside.
     * @param array<int, string> $urls
     * @return array{feeds: array<int, array{url: string, title: string, parsed: ParsedFeed}>, pages: array<string, string>}
     */
    private static function fetchAndClassify(array $urls, int $concurrency): array
    {
        $urls = array_values($urls);
        $requests = [];
        foreach ($urls as $i => $url) {
            $requests[$i] = ['url' => $url, 'etag' => null, 'lastModified' => null];
        }

        $results = FeedFetcher::fetchManyRaw($requests, $concurrency);

        $feeds = [];
        $pages = [];
        foreach ($urls as $i => $url) {
            $result = $results[$i] ?? null;
            if ($result === null || $result->error !== null || $result->httpCode < 200 || $result->httpCode >= 300 || $result->body === null) {
                continue;
            }
            $parsed = FeedFetcher::parse($result->body);
            if ($parsed === null) {
                $pages[$url] = $result->body;
                continue;
            }
            $feeds[] = ['url' => $url, 'title' => $parsed->feedTitle ?: $url, 'parsed' => $parsed];
        }

        return ['feeds' => $feeds, 'pages' => $pages];
    }

    /**
     * Drops the parsed feed (only needed internally) and any URL already listed earlier.
     * The public discovery methods run their results through this before returning.
     * @param array<int, array{url: string, title: string, parsed: ParsedFeed}> $feeds
     * @return array<int, array{url: string, title: string}>
     */
    private static function withoutParsed(array $feeds): array
    {
        $out = [];
        foreach ($feeds as $feed) {
            // "/rss" and "/rss/" are the same feed on most sites; offer only the first.
            $out[rtrim($feed['url'], '/')] ??= ['url' => $feed['url'], 'title' => $feed['title']];
        }
        return array_values($out);
    }

    private static function siteHost(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
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

    private static function resolveUrl(string $href, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $origin = self::originOf($baseUrl) ?? '';
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?: '/';
        if (str_starts_with($href, '?')) {
            return $origin . $basePath . $href;
        }
        // Relative to the page's directory: "feed.xml" on /blog/post resolves to /blog/feed.xml.
        $dir = substr($basePath, 0, strrpos($basePath, '/') + 1);
        return $origin . $dir . $href;
    }
}
