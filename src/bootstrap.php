<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RateLimiter.php';

if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function json_response($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_response(['error' => $message], $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function feed_id_for_url(string $url): string
{
    $normalized = strtolower(trim($url));
    $normalized = rtrim($normalized, '/');
    return 'feed_' . substr(md5($normalized), 0, 12);
}

/**
 * Normalizes a URL for item-id hashing so cosmetic/tracking differences
 * between fetches of the same article don't change its id. Deliberately
 * narrow — used only for hashing, not a general URL-cleaning utility.
 */
function normalize_url_for_id(string $url): string
{
    $url = trim($url);
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    $path = $parts['path'] ?? '';
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = substr($path, 0, -1);
    }

    $query = '';
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $params);
        foreach (item_id_tracking_params() as $tracking) {
            unset($params[$tracking]);
        }
        if (!empty($params)) {
            ksort($params);
            $query = '?' . http_build_query($params);
        }
    }

    return $scheme . '://' . $host . $port . $path . $query;
}

/** Curated, explicit list — not a generic tracking-param library. Extend as needed. */
function item_id_tracking_params(): array
{
    return [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid',
        'mc_cid', 'mc_eid', 'igshid', 'ref', 'ref_src', 'spm',
        'yclid', '_ga', '_gl', 'vero_id', 'mkt_tok', 'trk',
        'guccounter', 'guce_referrer', 'guce_referrer_sig',
    ];
}

function normalize_title_for_id(?string $title): string
{
    $title = trim((string) $title);
    return preg_replace('/\s+/', ' ', $title) ?? $title;
}

function item_id_for(?string $guid, ?string $link, ?string $title, ?string $published): string
{
    $guid = $guid !== null ? trim($guid) : null;
    $link = $link !== null ? trim($link) : null;

    // Some feeds put the article URL in <guid> without isPermaLink="true";
    // treat any URL-shaped guid the same as a link so it gets normalized too.
    $guidKey = ($guid !== null && $guid !== '')
        ? (preg_match('#^https?://#i', $guid) ? normalize_url_for_id($guid) : $guid)
        : null;

    $linkKey = ($link !== null && $link !== '' && preg_match('#^https?://#i', $link))
        ? normalize_url_for_id($link)
        : $link;

    $key = $guidKey ?: ($linkKey ?: (normalize_title_for_id($title) . '|' . trim((string) $published)));

    return 'item_' . substr(sha1($key), 0, 12);
}

function new_folder_id(): string
{
    return 'fol_' . bin2hex(random_bytes(6));
}

function new_saved_search_id(): string
{
    return 'srch_' . bin2hex(random_bytes(6));
}

/** Sorts folders by their 'order' field (missing values sort last, ties keep original order). */
function sort_folders(array $folders): array
{
    $indexed = array_values($folders);
    usort($indexed, fn ($a, $b) => ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX));
    return $indexed;
}

function items_file_path(string $feedId): string
{
    return ITEMS_DIR . '/' . $feedId . '.json';
}

/** Keeps a plain-text log from growing without bound: once it exceeds $maxBytes, trims it down to roughly its last $maxBytes, dropping the leading partial line. */
function cap_log_file(string $path, int $maxBytes): void
{
    if (!is_file($path) || filesize($path) <= $maxBytes) {
        return;
    }
    $fh = fopen($path, 'r+');
    if ($fh === false) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        fseek($fh, -$maxBytes, SEEK_END);
        $tail = stream_get_contents($fh);
        $newlinePos = strpos($tail, "\n");
        if ($newlinePos !== false) {
            $tail = substr($tail, $newlinePos + 1);
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $tail);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

function now_iso8601(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
}

/** Newest mtime among the app's own source files — stands in for a "last build date" since this app has no build step and is deployed by uploading files, not git. */
function last_build_date(): string
{
    $roots = [
        dirname(__DIR__) . '/public',
        dirname(__DIR__) . '/public/api',
        __DIR__,
    ];
    $latest = 0;
    foreach ($roots as $dir) {
        foreach (glob($dir . '/*') as $path) {
            if (!is_file($path) || $path === __DIR__ . '/config.php') {
                continue;
            }
            $latest = max($latest, filemtime($path) ?: 0);
        }
    }
    $latest = $latest ?: time();
    return (new DateTimeImmutable('@' . $latest))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
}

function server_name(): string
{
    return $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? (gethostname() ?: 'unknown');
}

// APP_VERSION is stamped into src/version.php by bin/package-for-deploy.sh at package
// time, from the exact commit being archived — deployments are independent snapshots
// per host, not live git checkouts, so this can't be a hand-maintained constant.
// version.php doesn't exist in the repo (gitignored); local dev falls back to 'dev'.
if (file_exists(__DIR__ . '/version.php')) {
    require_once __DIR__ . '/version.php';
} else {
    define('APP_VERSION', 'dev');
}

// Fallback defaults for constants added to config.sample.php after this app's
// first release — an in-place code update never touches the live config.php (see
// config.sample.php), so a config.php predating a feature must not be a fatal error.
if (!defined('GITHUB_REPO')) {
    define('GITHUB_REPO', 'DeLoeribas/quill');
}
if (!defined('GITHUB_VERSION_CACHE_FILE')) {
    define('GITHUB_VERSION_CACHE_FILE', DATA_DIR . '/github_version.json');
}
if (!defined('GITHUB_VERSION_CACHE_SECONDS')) {
    define('GITHUB_VERSION_CACHE_SECONDS', 6 * 3600);
}
if (!defined('FETCH_RETRY_ATTEMPTS')) {
    define('FETCH_RETRY_ATTEMPTS', 3);
}
if (!defined('FETCH_TRANSIENT_TOLERANCE')) {
    define('FETCH_TRANSIENT_TOLERANCE', 3);
}

function read_items_file(string $feedId): array
{
    return Storage::read(items_file_path($feedId), ['feed_id' => $feedId, 'items' => []]);
}

/** Pure helper: extracts the default interval from an already-loaded feeds.json array. Safe to call from inside a Storage::update mutator (no file I/O, so no nested flock on the same file). */
function default_refresh_interval_from(array $data): int
{
    $value = $data['settings']['default_refresh_interval_minutes'] ?? DEFAULT_REFRESH_INTERVAL_MINUTES;
    return is_int($value) && $value > 0 ? $value : DEFAULT_REFRESH_INTERVAL_MINUTES;
}

/**
 * Whitelists and type-checks the sidebar UI preferences (collapsed folders,
 * sort order, last-selected filter) sent from the client, so they survive
 * server-side in feeds.json — browser storage (localStorage) alone isn't
 * reliable for this: Safari in particular can clear it for a site between
 * launches depending on the user's privacy settings. Unknown keys are
 * dropped; only recognized, well-typed keys are kept.
 */
function sanitize_ui_prefs(array $prefs): array
{
    $out = [];

    if (isset($prefs['collapsed_folders']) && is_array($prefs['collapsed_folders'])) {
        $out['collapsed_folders'] = array_values(array_filter(
            $prefs['collapsed_folders'],
            fn ($v) => is_string($v)
        ));
    }

    foreach (['sort_order', 'sort_order_unread'] as $key) {
        if (isset($prefs[$key]) && in_array($prefs[$key], ['asc', 'desc'], true)) {
            $out[$key] = $prefs[$key];
        }
    }

    if (isset($prefs['sort_feeds_alphabetically']) && is_bool($prefs['sort_feeds_alphabetically'])) {
        $out['sort_feeds_alphabetically'] = $prefs['sort_feeds_alphabetically'];
    }

    if (isset($prefs['sidebar_collapsed']) && is_bool($prefs['sidebar_collapsed'])) {
        $out['sidebar_collapsed'] = $prefs['sidebar_collapsed'];
    }

    if (isset($prefs['sidebar_width']) && is_int($prefs['sidebar_width'])
        && $prefs['sidebar_width'] >= 180 && $prefs['sidebar_width'] <= 600) {
        // Keep 180/600 in sync with SIDEBAR_WIDTH_MIN/MAX in app.js.
        $out['sidebar_width'] = $prefs['sidebar_width'];
    }

    if (isset($prefs['item_pane_width']) && is_int($prefs['item_pane_width'])
        && $prefs['item_pane_width'] >= 280 && $prefs['item_pane_width'] <= 800) {
        // Keep 280/800 in sync with ITEM_PANE_WIDTH_MIN/MAX in app.js.
        $out['item_pane_width'] = $prefs['item_pane_width'];
    }

    if (isset($prefs['selected_item_id']) && is_string($prefs['selected_item_id'])) {
        $out['selected_item_id'] = $prefs['selected_item_id'];
    }

    if (array_key_exists('last_filter', $prefs)) {
        $lf = $prefs['last_filter'];
        if ($lf === null) {
            $out['last_filter'] = null;
        } elseif (is_array($lf) && isset($lf['type']) && is_string($lf['type'])) {
            $id = $lf['id'] ?? null;
            $out['last_filter'] = [
                'type' => $lf['type'],
                'id' => (is_string($id) || is_int($id)) ? $id : null,
            ];
        }
    }

    return $out;
}

/** @return string[] */
function normalize_filters($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $seen = [];
    $out = [];
    foreach ($raw as $value) {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            continue;
        }
        $key = strtolower($trimmed);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $trimmed;
    }
    return $out;
}

const ACCENT_FOLD_MAP = [
    'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
    'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a', 'Ā' => 'a', 'Ă' => 'a', 'Ą' => 'a',
    'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
    'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'Ē' => 'e', 'Ĕ' => 'e', 'Ė' => 'e', 'Ę' => 'e', 'Ě' => 'e',
    'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i',
    'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i', 'Ī' => 'i', 'Ĭ' => 'i', 'Į' => 'i',
    'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
    'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ø' => 'o', 'Ō' => 'o', 'Ŏ' => 'o', 'Ő' => 'o',
    'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
    'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ū' => 'u', 'Ŭ' => 'u', 'Ů' => 'u', 'Ű' => 'u', 'Ų' => 'u',
    'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'y', 'Ÿ' => 'y',
    'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'Ç' => 'c', 'Ć' => 'c', 'Ĉ' => 'c', 'Ċ' => 'c', 'Č' => 'c',
    'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'Ñ' => 'n', 'Ń' => 'n', 'Ņ' => 'n', 'Ň' => 'n',
    'ß' => 'ss',
];

/** Folds common accented Latin characters to their plain ASCII equivalent (and lowercases), so search matching is accent-insensitive in both directions. */
function fold_accents(string $s): string
{
    return strtolower(strtr($s, ACCENT_FOLD_MAP));
}

/** Builds whole-word, accent-insensitive regex patterns for each word in a search query, for matching against fold_accents()-folded haystacks. */
function search_query_patterns(string $query): array
{
    $normalizedQuery = fold_accents($query);
    $queryWords = $normalizedQuery !== ''
        ? preg_split('/\s+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY)
        : [];
    return array_map(
        fn (string $word) => '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u',
        $queryWords
    );
}

/** True if an item's title/summary plus its feed title match every pattern from search_query_patterns() (vacuously true for an empty pattern list). */
function item_matches_query(array $item, ?string $feedTitle, array $queryPatterns): bool
{
    if (empty($queryPatterns)) {
        return true;
    }
    $haystack = fold_accents(strip_tags(($item['title'] ?? '') . ' ' . ($item['summary'] ?? '') . ' ' . ($item['comment'] ?? '') . ' ' . ($feedTitle ?? '')));
    foreach ($queryPatterns as $pattern) {
        if (!preg_match($pattern, $haystack)) {
            return false;
        }
    }
    return true;
}

/** Counts all items (read or not) across all feeds matching a saved-search query, using the same matching rules as the items.php search endpoint. */
function match_count_for_query(string $query, array $feeds): int
{
    $queryPatterns = search_query_patterns($query);
    $count = 0;
    foreach ($feeds as $feed) {
        $itemsData = read_items_file($feed['id']);
        foreach ($itemsData['items'] as $item) {
            if (item_matches_query($item, $feed['title'] ?? null, $queryPatterns)) {
                $count++;
            }
        }
    }
    return $count;
}

/**
 * Seeds FEEDS_FILE from data/default-feeds.json the first time it's called
 * on an installation that has no feeds file yet (see public/api/auth.php's
 * 'setup' action) — never touches an existing feeds.json, whether real or
 * previously (re)seeded. Returns the ids of the feeds it created so the
 * caller can refresh them (fetching real titles/favicons), or [] if nothing
 * was seeded.
 */
function seed_default_feeds_if_empty(): array
{
    if (file_exists(FEEDS_FILE) || !is_file(DATA_DIR . '/default-feeds.json')) {
        return [];
    }

    $template = json_decode((string) file_get_contents(DATA_DIR . '/default-feeds.json'), true);
    if (!is_array($template) || empty($template['feeds'])) {
        return [];
    }

    $feedIds = [];

    Storage::update(FEEDS_FILE, ['folders' => [], 'feeds' => []], function (array $data) use ($template, &$feedIds) {
        $folderIdsByName = [];
        foreach ((array) ($template['folders'] ?? []) as $order => $name) {
            $id = new_folder_id();
            $folderIdsByName[$name] = $id;
            $data['folders'][] = ['id' => $id, 'name' => $name, 'order' => $order];
        }

        foreach ($template['feeds'] as $entry) {
            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $feedId = feed_id_for_url($url);
            $feedIds[] = $feedId;
            $data['feeds'][] = [
                'id' => $feedId,
                'url' => $url,
                'title' => $url,
                'site_url' => null,
                'favicon_url' => null,
                'image_url' => null,
                'folder_id' => $folderIdsByName[$entry['folder'] ?? ''] ?? null,
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
        }

        return $data;
    });

    return $feedIds;
}

function unread_count_for(string $feedId): int
{
    $itemsData = read_items_file($feedId);
    $count = 0;
    foreach ($itemsData['items'] as $item) {
        if (empty($item['read'])) {
            $count++;
        }
    }
    return $count;
}

function item_count_for(string $feedId): int
{
    return count(read_items_file($feedId)['items']);
}

function starred_count_for(string $feedId): int
{
    $itemsData = read_items_file($feedId);
    $count = 0;
    foreach ($itemsData['items'] as $item) {
        if (!empty($item['starred'])) {
            $count++;
        }
    }
    return $count;
}
