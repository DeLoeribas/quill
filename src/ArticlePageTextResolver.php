<?php

declare(strict_types=1);

// Captures the readable text of an article's linked page when the user opens
// it (Show page / Return), so search can later match on the full article and
// not just the feed's teaser. Stored per feed in data/pages/ — see
// page_texts_file_path().
final class ArticlePageTextResolver
{
    private const MAX_HTML_BYTES = 2097152;
    private const MAX_TEXT_BYTES = 102400;

    // Page chrome that isn't part of the article and would only add noise
    // (menus, cookie banners, related-article lists) to search matches.
    private const STRIP_TAGS = ['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'svg', 'iframe', 'template'];

    /**
     * @return string|null null = fetch failed/timed out, '' = fetched fine but
     *     no text found, non-empty = the page text. Only non-empty text is
     *     stored, so the other two are retried on a later open.
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

        return self::extractText($html);
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
            CURLOPT_ENCODING => '',
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
        $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));

        if ($buffer === '' || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        // A link straight to a PDF/image/etc. — nothing to extract, but not worth retrying either.
        if ($contentType !== '' && !str_contains($contentType, 'html') && !str_contains($contentType, 'xml')) {
            return '';
        }

        return substr($buffer, 0, self::MAX_HTML_BYTES);
    }

    private static function extractText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        // Without an encoding hint DOMDocument assumes ISO-8859-1 and mangles
        // UTF-8 pages that only declare their charset via <meta charset>.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        foreach (self::STRIP_TAGS as $tag) {
            $nodes = iterator_to_array($doc->getElementsByTagName($tag));
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = null;
        foreach (['article', 'main', 'body'] as $tag) {
            $root = $doc->getElementsByTagName($tag)->item(0);
            if ($root !== null) {
                break;
            }
        }
        if ($root === null) {
            return '';
        }

        // textContent glues adjacent blocks together ("<td>RSS</td><td>File…" →
        // "RSSFile…"), which would break whole-word search matching.
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('.//p|.//div|.//li|.//td|.//th|.//tr|.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//br|.//dt|.//dd|.//blockquote|.//pre|.//figcaption|.//section', $root) as $block) {
            $block->appendChild($doc->createTextNode(' '));
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $root->textContent));
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            // Byte cut, then drop a possibly split trailing multibyte character (no mbstring dependency).
            $text = (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', substr($text, 0, self::MAX_TEXT_BYTES));
        }

        return $text;
    }
}
