<?php

declare(strict_types=1);

final class FaviconResolver
{
    private const MAX_HTML_BYTES = 65536; // favicon <link> tags live in <head>, near the top

    public static function resolve(?string $pageUrl): ?string
    {
        if (!$pageUrl) {
            return null;
        }
        $origin = self::originOf($pageUrl);
        if ($origin === null) {
            return null;
        }

        $html = self::fetchHtml($pageUrl);
        if ($html !== null) {
            $iconHref = self::extractIconHref($html, $pageUrl);
            if ($iconHref !== null) {
                return $iconHref;
            }
        }

        return $origin . '/favicon.ico';
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

    private static function extractIconHref(string $html, string $pageUrl): ?string
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//link[@rel and @href]');

        $best = null;
        foreach ($nodes as $node) {
            $rel = strtolower(trim($node->getAttribute('rel')));
            if (!str_contains($rel, 'icon')) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            if ($best === null || $rel === 'icon' || $rel === 'shortcut icon') {
                $best = $href;
                if ($rel === 'icon' || $rel === 'shortcut icon') {
                    break;
                }
            }
        }

        if ($best === null) {
            return null;
        }

        return self::resolveUrl($best, $pageUrl);
    }

    /** Resolves a possibly-relative href found on $pageUrl, per normal URL resolution rules — a document-relative href (no leading slash) is relative to the page's own directory, not the site root. */
    private static function resolveUrl(string $href, string $pageUrl): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($pageUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $path = $parts['path'] ?? '/';
        $baseDir = str_ends_with($path, '/') ? $path : substr($path, 0, (int) strrpos($path, '/') + 1);
        return $origin . $baseDir . $href;
    }
}
