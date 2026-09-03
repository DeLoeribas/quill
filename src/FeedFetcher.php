<?php

declare(strict_types=1);

final class FeedFetchResult
{
    public function __construct(
        public readonly int $httpCode,
        public readonly ?string $body,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
        public readonly ?string $error = null,
    ) {
    }
}

final class ParsedFeed
{
    /** @param array<int, array{guid:?string, link:?string, title:?string, published:?string, summary:?string, image:?string}> $items */
    public function __construct(
        public readonly ?string $feedTitle,
        public readonly ?string $siteUrl,
        public readonly ?string $feedImage,
        public readonly array $items,
        // The feed's own suggested refresh cadence, in minutes, when it declares
        // one (RSS 2.0 <ttl>, or the Syndication module's updatePeriod/
        // updateFrequency pair). Null when absent or unparseable — Atom and JSON
        // Feed have no equivalent, so this is always null for those formats.
        public readonly ?int $ttlMinutes = null,
    ) {
    }
}

final class FeedFetcher
{
    public static function fetchRaw(string $url, ?string $etag, ?string $lastModified): FeedFetchResult
    {
        $headerSink = new stdClass();
        $headerSink->lines = [];
        $ch = self::buildHandle($url, $etag, $lastModified, $headerSink);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno) {
            return new FeedFetchResult(0, null, null, null, $error);
        }

        return new FeedFetchResult(
            $httpCode,
            $body === false ? null : $body,
            $headerSink->lines['etag'] ?? null,
            $headerSink->lines['last-modified'] ?? null,
        );
    }

    /**
     * Fetches multiple feeds concurrently via curl_multi, at most $concurrency
     * requests in flight at once (a rolling window: as soon as one finishes,
     * the next queued request starts). This is what makes RefreshService's
     * bulk refresh fast — fetching feeds one at a time otherwise means total
     * time scales linearly with feed count regardless of network latency.
     *
     * @param array<int|string, array{url:string, etag:?string, lastModified:?string}> $requests
     * @return array<int|string, FeedFetchResult> keyed the same way as $requests
     */
    public static function fetchManyRaw(array $requests, int $concurrency): array
    {
        $results = [];
        if ($requests === []) {
            return $results;
        }

        $multi = curl_multi_init();
        $pending = $requests;
        $active = [];

        $startNext = function () use (&$pending, &$active, $multi) {
            if ($pending === []) {
                return;
            }
            $key = array_key_first($pending);
            $spec = $pending[$key];
            unset($pending[$key]);

            $headerSink = new stdClass();
            $headerSink->lines = [];
            $ch = self::buildHandle($spec['url'], $spec['etag'], $spec['lastModified'], $headerSink);
            curl_multi_add_handle($multi, $ch);
            $active[spl_object_id($ch)] = ['key' => $key, 'ch' => $ch, 'headerSink' => $headerSink];
        };

        for ($i = 0; $i < $concurrency; $i++) {
            $startNext();
        }

        do {
            do {
                $status = curl_multi_exec($multi, $stillRunning);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($multi)) {
                $ch = $info['handle'];
                $entry = $active[spl_object_id($ch)];
                unset($active[spl_object_id($ch)]);

                $errno = curl_errno($ch);
                if ($errno) {
                    $results[$entry['key']] = new FeedFetchResult(0, null, null, null, curl_error($ch));
                } else {
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $body = curl_multi_getcontent($ch);
                    $results[$entry['key']] = new FeedFetchResult(
                        $httpCode,
                        $body === false || $body === null ? null : $body,
                        $entry['headerSink']->lines['etag'] ?? null,
                        $entry['headerSink']->lines['last-modified'] ?? null,
                    );
                }

                curl_multi_remove_handle($multi, $ch);
                $startNext();
            }

            if ($active !== []) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active !== [] || $pending !== []);

        curl_multi_close($multi);

        return $results;
    }

    private static function buildHandle(string $url, ?string $etag, ?string $lastModified, object $headerSink): \CurlHandle
    {
        $ch = curl_init($url);

        $headers = ['Accept: application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml, text/xml'];
        if ($etag) {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        if ($lastModified) {
            $headers[] = 'If-Modified-Since: ' . $lastModified;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => FETCH_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => FETCH_USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use ($headerSink) {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $headerSink->lines[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($headerLine);
            },
        ]);

        return $ch;
    }

    public static function parse(string $body): ?ParsedFeed
    {
        $trimmed = ltrim($body);
        if ($trimmed !== '' && $trimmed[0] === '{') {
            return self::parseJsonFeed($trimmed);
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($body, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();

        if (!$ok || $doc->documentElement === null) {
            return null;
        }

        $root = strtolower($doc->documentElement->localName);

        return match ($root) {
            'rss' => self::parseRss2($doc),
            'feed' => self::parseAtom($doc),
            default => null,
        };
    }

    /** Parses JSON Feed (https://jsonfeed.org), versions 1 and 1.1. */
    private static function parseJsonFeed(string $body): ?ParsedFeed
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return null;
        }

        $feedTitle = isset($data['title']) ? trim((string) $data['title']) : null;
        $siteUrl = isset($data['home_page_url']) ? trim((string) $data['home_page_url']) : null;
        $feedImage = $data['icon'] ?? $data['favicon'] ?? null;
        $feedImage = is_string($feedImage) ? trim($feedImage) : null;

        $items = [];
        foreach ($data['items'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = isset($entry['id']) ? trim((string) $entry['id']) : null;
            $link = isset($entry['url']) ? trim((string) $entry['url']) : null;
            $title = isset($entry['title']) ? trim((string) $entry['title']) : null;
            $published = self::normalizeDate($entry['date_published'] ?? $entry['date_modified'] ?? null);
            $summary = $entry['content_html'] ?? $entry['content_text'] ?? null;

            $items[] = [
                'guid' => $id ?: null,
                'link' => $link ?: null,
                'title' => $title ?: null,
                'published' => $published,
                'summary' => $summary !== null ? (string) $summary : null,
                'image' => self::jsonFeedImage($entry, $summary !== null ? (string) $summary : null),
            ];
        }

        return new ParsedFeed($feedTitle ?: null, $siteUrl ?: null, $feedImage ?: null, $items);
    }

    private static function jsonFeedImage(array $entry, ?string $htmlBody): ?string
    {
        if (!empty($entry['image']) && is_string($entry['image'])) {
            return trim($entry['image']);
        }

        if (!empty($entry['attachments']) && is_array($entry['attachments'])) {
            foreach ($entry['attachments'] as $attachment) {
                if (
                    is_array($attachment)
                    && !empty($attachment['url'])
                    && !empty($attachment['mime_type'])
                    && str_starts_with((string) $attachment['mime_type'], 'image/')
                ) {
                    return trim((string) $attachment['url']);
                }
            }
        }

        return self::extractFirstImageSrc($htmlBody);
    }

    private static function parseRss2(DOMDocument $doc): ParsedFeed
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');
        $xpath->registerNamespace('sy', 'http://purl.org/rss/1.0/modules/syndication/');
        // extractImage() queries an "a:" (Atom) link as a fallback regardless of feed
        // format; registering it here too (even though RSS2 docs never use it) keeps
        // that query valid instead of failing with an undefined-prefix error.
        $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');

        $channel = $xpath->query('/rss/channel')->item(0);
        $feedTitle = $channel ? self::text($xpath, 'title', $channel) : null;
        $siteUrl = $channel ? self::text($xpath, 'link', $channel) : null;
        $feedImage = $channel ? self::text($xpath, 'image/url', $channel) : null;
        $ttlMinutes = $channel ? self::rssTtlMinutes($xpath, $channel) : null;

        $items = [];
        foreach ($xpath->query('/rss/channel/item') as $itemNode) {
            $guid = self::text($xpath, 'guid', $itemNode);
            $link = self::text($xpath, 'link', $itemNode);
            $title = self::text($xpath, 'title', $itemNode);
            $pubDate = self::text($xpath, 'pubDate', $itemNode);
            $encoded = self::text($xpath, 'content:encoded', $itemNode);
            $description = self::text($xpath, 'description', $itemNode);

            $summary = $encoded && strlen($encoded) > strlen((string) $description) ? $encoded : $description;
            $image = self::extractImage($xpath, $itemNode, $summary);

            $items[] = [
                'guid' => $guid ?: null,
                'link' => $link ?: null,
                'title' => $title ?: null,
                'published' => self::normalizeDate($pubDate),
                'summary' => $summary ?: null,
                'image' => $image,
            ];
        }

        return new ParsedFeed($feedTitle ?: null, $siteUrl ?: null, $feedImage, $items, $ttlMinutes);
    }

    /**
     * RSS 2.0's own suggested refresh cadence for the feed, in minutes: the
     * plain <ttl> element if present, else the Syndication module's
     * <sy:updatePeriod> (hourly/daily/weekly/monthly/yearly) combined with
     * <sy:updateFrequency> (how many times per period; default 1) if that's
     * present instead. Floored at 5 minutes so a malformed or overly eager
     * feed can't force very frequent fetching.
     */
    private static function rssTtlMinutes(DOMXPath $xpath, DOMNode $channel): ?int
    {
        $ttl = self::text($xpath, 'ttl', $channel);
        if ($ttl !== null && ctype_digit($ttl) && (int) $ttl > 0) {
            return max(5, (int) $ttl);
        }

        $period = self::text($xpath, 'sy:updatePeriod', $channel);
        $periodMinutes = match ($period !== null ? strtolower($period) : null) {
            'hourly' => 60,
            'daily' => 1440,
            'weekly' => 10080,
            'monthly' => 43200,
            'yearly' => 525600,
            default => null,
        };
        if ($periodMinutes === null) {
            return null;
        }

        $freqRaw = self::text($xpath, 'sy:updateFrequency', $channel);
        $frequency = ($freqRaw !== null && ctype_digit($freqRaw) && (int) $freqRaw > 0) ? (int) $freqRaw : 1;

        return max(5, (int) round($periodMinutes / $frequency));
    }

    private static function parseAtom(DOMDocument $doc): ParsedFeed
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');
        $xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');

        $feedTitle = self::text($xpath, 'a:title', $doc->documentElement);
        $siteUrl = self::atomLink($xpath, $doc->documentElement);
        $feedImage = self::text($xpath, 'a:logo', $doc->documentElement) ?: self::text($xpath, 'a:icon', $doc->documentElement);

        $items = [];
        foreach ($xpath->query('/a:feed/a:entry') as $entryNode) {
            $id = self::text($xpath, 'a:id', $entryNode);
            $link = self::atomLink($xpath, $entryNode);
            $title = self::text($xpath, 'a:title', $entryNode);
            $published = self::text($xpath, 'a:published', $entryNode) ?: self::text($xpath, 'a:updated', $entryNode);
            $content = self::text($xpath, 'a:content', $entryNode);
            $summary = self::text($xpath, 'a:summary', $entryNode);
            $body = $content ?: $summary ?: self::plainTextWithLineBreaks(self::text($xpath, './/media:description', $entryNode));
            $image = self::extractImage($xpath, $entryNode, $body);

            $items[] = [
                'guid' => $id ?: null,
                'link' => $link ?: null,
                'title' => $title ?: null,
                'published' => self::normalizeDate($published),
                'summary' => $body ?: null,
                'image' => $image,
            ];
        }

        return new ParsedFeed($feedTitle ?: null, $siteUrl ?: null, $feedImage, $items);
    }

    /** Finds a thumbnail for an item: media:thumbnail/media:content, then an image enclosure, then the first <img> in its HTML body. */
    private static function extractImage(DOMXPath $xpath, DOMNode $context, ?string $htmlBody): ?string
    {
        // media:thumbnail/media:content aren't necessarily direct children: the MRSS
        // spec (and YouTube's own feeds) commonly wrap them in a <media:group>, so
        // this searches descendants rather than just the entry/item's immediate children.
        $thumb = self::queryFirst($xpath, './/media:thumbnail/@url', $context);
        if ($thumb) {
            return trim($thumb->textContent);
        }

        $mediaContent = self::queryFirst($xpath, './/media:content[starts-with(@type,"image/") or @medium="image"]/@url', $context);
        if ($mediaContent) {
            return trim($mediaContent->textContent);
        }

        $enclosure = self::queryFirst($xpath, 'enclosure[starts-with(@type,"image/")]/@url', $context);
        if ($enclosure) {
            return trim($enclosure->textContent);
        }

        $atomImageLink = self::queryFirst($xpath, 'a:link[@rel="enclosure" and starts-with(@type,"image/")]/@href', $context);
        if ($atomImageLink) {
            return trim($atomImageLink->textContent);
        }

        return self::extractFirstImageSrc($htmlBody);
    }

    private static function extractFirstImageSrc(?string $html): ?string
    {
        if (!$html || !str_contains($html, '<img')) {
            return null;
        }
        libxml_use_internal_errors(true);
        $fragment = new DOMDocument();
        $fragment->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();

        $img = $fragment->getElementsByTagName('img')->item(0);
        if (!$img) {
            return null;
        }
        $src = trim($img->getAttribute('src'));
        return ($src !== '' && preg_match('#^https?://#i', $src)) ? $src : null;
    }

    /** DOMXPath::query() returns false (not an empty DOMNodeList) on a bad expression, e.g. an
     *  unregistered namespace prefix — calling ->item() on that would be a fatal error, so every
     *  call site goes through this instead of querying directly. */
    private static function queryFirst(DOMXPath $xpath, string $expr, DOMNode $context): ?DOMNode
    {
        $result = $xpath->query($expr, $context);
        return $result !== false ? $result->item(0) : null;
    }

    private static function text(DOMXPath $xpath, string $expr, DOMNode $context): ?string
    {
        $node = self::queryFirst($xpath, $expr, $context);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);
        return $value === '' ? null : $value;
    }

    /**
     * Unlike a:content/a:summary (often HTML), media:description is plain
     * text — escape it so stray `<`/`&` survive the frontend's HTML parser
     * as literal characters, then turn its newlines into <br> so paragraph
     * breaks render instead of collapsing into one line.
     */
    private static function plainTextWithLineBreaks(?string $text): ?string
    {
        return $text !== null ? nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) : null;
    }

    private static function atomLink(DOMXPath $xpath, DOMNode $context): ?string
    {
        $alternate = self::queryFirst($xpath, 'a:link[@rel="alternate"]/@href', $context);
        if ($alternate) {
            return trim($alternate->textContent);
        }
        $any = self::queryFirst($xpath, 'a:link/@href', $context);
        return $any ? trim($any->textContent) : null;
    }

    private static function normalizeDate(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }
}
