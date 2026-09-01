<?php
// Copy this file to config.php and adjust values as needed.
// config.php is gitignored so local/deployed secrets never get committed.

define('DATA_DIR', dirname(__DIR__) . '/data');
define('ITEMS_DIR', DATA_DIR . '/items');
define('FEEDS_FILE', DATA_DIR . '/feeds.json');
define('CRON_LOG_FILE', DATA_DIR . '/cron.log');
// Path the README's cron/launchd examples redirect stderr to; capped alongside CRON_LOG_FILE.
define('CRON_STDERR_LOG_FILE', DATA_DIR . '/cron-stderr.log');
// Each cron log file is trimmed to its last CRON_LOG_MAX_BYTES bytes once it grows past that,
// so unattended cron runs (every few minutes, forever) don't fill up the disk.
define('CRON_LOG_MAX_BYTES', 1_000_000);

// HTTP client settings used when fetching feeds.
define('FETCH_TIMEOUT_SECONDS', 15);
define('FETCH_CONNECT_TIMEOUT_SECONDS', 8);
define('FETCH_USER_AGENT', 'Quill/1.0 (+local)');
// How many feeds RefreshService::refreshAll() fetches concurrently via curl_multi.
define('FETCH_CONCURRENCY', 8);

// How many read items to keep per feed before old ones are pruned.
define('MAX_ITEMS_PER_FEED', 1000);

// Absolute safety net: even if a feed accumulates more unread items than
// MAX_ITEMS_PER_FEED alone can prune (nothing read yet to safely drop),
// never let its stored item count exceed this many — oldest items are
// dropped regardless of read status once this is hit.
define('HARD_MAX_ITEMS_PER_FEED', 1000);

// Default refresh interval assigned to newly added feeds (minutes).
define('DEFAULT_REFRESH_INTERVAL_MINUTES', 60);

// Path to the file storing the single login's username + bcrypt password
// hash, created via the in-app "create a login" flow the first time the
// app is used.
define('AUTH_FILE', DATA_DIR . '/auth.json');

// Path to the file tracking failed login attempts per IP, for brute-force
// lockout. Runtime state, not committed.
define('LOGIN_ATTEMPTS_FILE', DATA_DIR . '/login_attempts.json');

// Secret shared with public/cron.php, the HTTP alternative to
// cron/refresh.php for hosts with no shell/SSH cron access — an external
// pinger (your host's "URL cron" feature, cron-job.org, a scheduled GitHub
// Actions workflow, etc.) hits that URL with this token to trigger a
// refresh instead. Leave blank to disable the endpoint entirely (recommended
// unless you actually need it). Generate one with:
//   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
define('CRON_TOKEN', '');
