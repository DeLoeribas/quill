<?php

// Guards the fast first page load. Run with: php tests/startup_speed_test.php
// Uses only a temporary folder; never touches data/ or the network.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/quill-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/users/u1/items', 0777, true);

define('APP_VERSION', '1.0.0');
define('GITHUB_VERSION_CACHE_FILE', $tmp . '/github_version.json');
define('GITHUB_VERSION_CACHE_SECONDS', 3600);
define('GITHUB_REPO', 'invalid/invalid');
// Any network attempt would fail fast against this, and the test checks it never happens.
define('FETCH_TIMEOUT_SECONDS', 1);
define('FETCH_CONNECT_TIMEOUT_SECONDS', 1);
define('FETCH_USER_AGENT', 'test');

require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/ItemsIndex.php';
require __DIR__ . '/../src/GithubVersionChecker.php';

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

// --- cachedStatus(): page load must not go to GitHub, even with an expired cache ---
$staleCheck = date(DATE_ATOM, time() - 7200);
file_put_contents(GITHUB_VERSION_CACHE_FILE, json_encode(['version' => '1.2.0', 'notes' => 'New stuff', 'checked_at' => $staleCheck]));

$start = microtime(true);
$status = GithubVersionChecker::cachedStatus();
check('cachedStatus reports the cached newer version', $status['latest'] === '1.2.0' && $status['notes'] === 'New stuff');
check('cachedStatus is instant (no network)', microtime(true) - $start < 0.2);
$after = json_decode((string) file_get_contents(GITHUB_VERSION_CACHE_FILE), true);
check('cachedStatus leaves checked_at alone (it did not check)', $after['checked_at'] === $staleCheck);

file_put_contents(GITHUB_VERSION_CACHE_FILE, json_encode(['version' => '0.9.0', 'notes' => null, 'checked_at' => $staleCheck]));
check('cachedStatus: older remote means up to date', GithubVersionChecker::cachedStatus()['latest'] === null);

unlink(GITHUB_VERSION_CACHE_FILE);
check('cachedStatus: no cache yet means up to date', GithubVersionChecker::cachedStatus()['latest'] === null);
check('cachedStatus: no cache file is created', !is_file(GITHUB_VERSION_CACHE_FILE));

// --- ItemsIndex::warm(): a refresh rebuilds the index a write deleted ---
ItemsIndex::register();
$itemsPath = $tmp . '/users/u1/items/feed_a.json';
$indexPath = ItemsIndex::indexPathFor($itemsPath);
Storage::update($itemsPath, ['feed_id' => 'feed_a', 'items' => []], fn () => ['feed_id' => 'feed_a', 'items' => [
    ['id' => 'i1', 'summary' => '<p>long</p>', 'read' => false],
    ['id' => 'i2', 'summary' => '<p>long</p>', 'read' => true],
]]);
check('a write leaves no index behind', !is_file($indexPath));
ItemsIndex::warm($itemsPath, 'feed_a');
$index = json_decode((string) file_get_contents($indexPath), true);
check('warm rebuilds the index', ($index['total'] ?? null) === 2 && ($index['unread'] ?? null) === 1);
check('warm index has no summaries', !array_key_exists('summary', $index['items'][0]));
$mtime = filemtime($indexPath);
touch($indexPath, $mtime - 100);
clearstatcache();
ItemsIndex::warm($itemsPath, 'feed_a');
clearstatcache();
check('warm leaves an existing index alone', filemtime($indexPath) === $mtime - 100);

exec('rm -rf ' . escapeshellarg($tmp));
echo $failures === 0 ? "All tests passed\n" : "$failures test(s) failed\n";
exit($failures === 0 ? 0 : 1);
