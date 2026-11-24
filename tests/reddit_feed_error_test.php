<?php

// Guards the Reddit feed error message. Run with: php tests/reddit_feed_error_test.php
// Pure string checks; never touches data/ or the network.

declare(strict_types=1);

define('FETCH_TIMEOUT_SECONDS', 1);
define('FETCH_CONNECT_TIMEOUT_SECONDS', 1);
define('FETCH_USER_AGENT', 'test');

require __DIR__ . '/../src/FeedFetcher.php';

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

$hint = 'reddit.com/prefs/feeds';

check('Reddit 404 explains the private feed link',
    str_contains(FeedFetcher::httpErrorMessage('https://www.reddit.com/r/fastmail/.rss', 404), $hint));
check('Reddit 429 explains the private feed link',
    str_contains(FeedFetcher::httpErrorMessage('https://www.reddit.com/r/macapps/.rss', 429), $hint));
check('old.reddit.com counts as Reddit',
    str_contains(FeedFetcher::httpErrorMessage('https://old.reddit.com/r/apple/.rss', 404), $hint));
check('message still starts with the status code',
    str_starts_with(FeedFetcher::httpErrorMessage('https://www.reddit.com/r/fastmail/.rss', 404), 'HTTP 404'));
check('a private feed link that fails gets the plain status',
    FeedFetcher::httpErrorMessage('https://www.reddit.com/r/fastmail/.rss?feed=abc123&user=someone', 404) === 'HTTP 404');
check('Reddit server errors get the plain status',
    FeedFetcher::httpErrorMessage('https://www.reddit.com/r/fastmail/.rss', 503) === 'HTTP 503');
check('other sites get the plain status',
    FeedFetcher::httpErrorMessage('https://example.com/feed.xml', 404) === 'HTTP 404');
check('look-alike hosts are not Reddit',
    FeedFetcher::httpErrorMessage('https://notreddit.com/r/x/.rss', 404) === 'HTTP 404');
check('no em dashes in the message',
    !str_contains(FeedFetcher::httpErrorMessage('https://www.reddit.com/r/x/.rss', 404), "\u{2014}"));

echo $failures === 0 ? "All tests passed\n" : "$failures test(s) failed\n";
exit($failures === 0 ? 0 : 1);
