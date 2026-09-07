<?php

declare(strict_types=1);

// Fills the gap when a feed provides no <description>/content:encoded at all
// (Hacker News's RSS) or an unusable one (Volkskrant) — pulls the linked
// article's og:description/meta description instead, the same tag sites
// populate for social-link previews, so it works even behind a paywall.
final class ArticleSummaryResolver
{
    private const MAX_HTML_BYTES = 131072; // description meta tags live in <head>, but with more boilerplate than a favicon-only fetch

    /**
     * @return string|null null = fetch failed/timed out (retry later), '' = fetched
     *     fine but no description tag found (nothing to find), non-empty = the snippet.
     */
    public static function resolve(?string $pageUrl): ?string
    {
        if (!$pageUrl) {
            return null;
        }

        $html = self::fetchHtml($pageUrl);
        if ($html === null) {
            return null;
        }

        return self::extractDescription($html);
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

    private static function extractDescription(string $html): string
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        $content = self::firstMetaContent($xpath, '//meta[@property="og:description"]/@content');
        if ($content === null) {
            $content = self::firstMetaContent($xpath, '//meta[@name="description"]/@content');
        }

        return $content ?? '';
    }

    private static function firstMetaContent(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)->nodeValue);
        return $value === '' ? null : $value;
    }
}
