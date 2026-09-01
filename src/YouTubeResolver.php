<?php

declare(strict_types=1);

final class YouTubeChannelInfo
{
    public function __construct(
        public readonly string $feedUrl,
        public readonly ?string $avatarUrl = null,
    ) {
    }
}

final class YouTubeResolver
{
    // The <head> block carrying the canonical/RSS-alternate/og:image tags sits
    // consistently around ~740KB into a channel page (verified across several
    // real channels of very different sizes) — everything before that is a large
    // fixed bundle of inline scripts/styles, so the cap needs real headroom past it.
    private const MAX_HTML_BYTES = 1048576;

    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

    /** Resolves a YouTube channel/handle/legacy-custom-URL/user URL to its Atom feed URL. Returns null for non-YouTube URLs, already-a-feed URLs, and anything else it doesn't recognize, so callers fall through to normal feed handling. */
    public static function resolve(string $url): ?YouTubeChannelInfo
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (preg_match('#^/feeds/videos\.xml#', $path)) {
            return null;
        }

        if (preg_match('#^/channel/([\w-]+)#', $path, $m)) {
            return new YouTubeChannelInfo(self::feedUrlFor($m[1]));
        }

        if (preg_match('#^/(?:@[\w.-]+|c/[\w.-]+|user/[\w.-]+)#', $path)) {
            $html = self::fetchHtml($url);
            if ($html === null) {
                return null;
            }
            $feedUrl = self::extractFeedUrl($html);
            if ($feedUrl === null) {
                return null;
            }
            return new YouTubeChannelInfo($feedUrl, self::extractAvatar($html));
        }

        return null;
    }

    private static function feedUrlFor(string $channelId): string
    {
        return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId;
    }

    private static function fetchHtml(string $pageUrl): ?string
    {
        $ch = curl_init($pageUrl);
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$buffer) {
                $buffer .= $chunk;
                if (strlen($buffer) >= self::MAX_HTML_BYTES) {
                    return -1;
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($buffer === '' || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        return substr($buffer, 0, self::MAX_HTML_BYTES);
    }

    /**
     * YouTube's own <head> already declares the channel's RSS feed via a
     * standard autodiscovery link, which is both the most authoritative
     * source (it's literally the feed URL, not something we reconstruct)
     * and — unlike the "channelId" values sprinkled through the page's
     * inline JSON for unrelated recommended/suggested channels — guaranteed
     * to refer to the page's own channel. The canonical <link> is a fallback
     * for the rare case that tag is missing.
     */
    private static function extractFeedUrl(string $html): ?string
    {
        if (preg_match('#<link[^>]+rel="alternate"[^>]+type="application/rss\+xml"[^>]+href="(https://www\.youtube\.com/feeds/videos\.xml\?channel_id=[\w-]+)"#', $html, $m)) {
            return htmlspecialchars_decode($m[1]);
        }
        if (preg_match('#<link[^>]+rel="canonical"[^>]+href="https://www\.youtube\.com/channel/(UC[\w-]+)"#', $html, $m)) {
            return self::feedUrlFor($m[1]);
        }
        return null;
    }

    private static function extractAvatar(string $html): ?string
    {
        if (preg_match('#<meta property="og:image" content="([^"]+)"#', $html, $m)) {
            return htmlspecialchars_decode($m[1]);
        }
        return null;
    }
}
