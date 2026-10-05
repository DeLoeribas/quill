<?php
// Copy this file to config.php and adjust values as needed.
// config.php is gitignored so local/deployed secrets never get committed.

define('DATA_DIR', dirname(__DIR__) . '/data');
// Each account's feeds, items and captured page text live in DATA_DIR/users/<user_id>/.
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

// Total attempts (not extra retries) per feed when a fetch fails transiently —
// a connection error, or a 5xx/429/408 from the server. YouTube's feed endpoint
// also 404s at random for valid channels, which counts as transient too. 1
// disables retrying.
define('FETCH_RETRY_ATTEMPTS', 3);

// How many refreshes in a row may fail transiently before the feed is actually
// shown as broken in the sidebar. Below this, the failure is silent and the feed
// stays due, so the next refresh retries it right away instead of waiting out a
// whole refresh interval — YouTube's 404 bursts routinely outlast the in-request
// retries above.
define('FETCH_TRANSIENT_TOLERANCE', 3);

// Repo whose src/version.json the footer compares against the local one to offer updates,
// and how often (seconds) to re-check — cached in GITHUB_VERSION_CACHE_FILE between checks.
define('GITHUB_REPO', 'DeLoeribas/quill');
define('GITHUB_VERSION_CACHE_FILE', DATA_DIR . '/github_version.json');
define('GITHUB_VERSION_CACHE_SECONDS', 3600);

// How many read items to keep per feed before old ones are pruned.
define('MAX_ITEMS_PER_FEED', 1000);

// Absolute safety net: even if a feed accumulates more unread items than
// MAX_ITEMS_PER_FEED alone can prune (nothing read yet to safely drop),
// never let its stored item count exceed this many — oldest items are
// dropped regardless of read status once this is hit.
define('HARD_MAX_ITEMS_PER_FEED', 1000);

// Default refresh interval assigned to newly added feeds (minutes).
define('DEFAULT_REFRESH_INTERVAL_MINUTES', 60);

// Path to the file storing every account's username, bcrypt password hash
// and admin flag. The first (admin) account is created via the in-app
// "create a login" flow the first time the app is used; the admin adds
// the others in Settings → Users.
define('USERS_FILE', DATA_DIR . '/users.json');

// Path to the file tracking failed login attempts per IP, for brute-force
// lockout. Runtime state, not committed.
define('LOGIN_ATTEMPTS_FILE', DATA_DIR . '/login_attempts.json');

// How long a login stays valid without visiting the app (seconds). Every
// visit restarts the countdown. Session files are kept in data/sessions/.
define('SESSION_LIFETIME_SECONDS', 30 * 24 * 3600);

// Secret shared with public/cron.php, the HTTP alternative to
// cron/refresh.php for hosts with no shell/SSH cron access — an external
// pinger (your host's "URL cron" feature, cron-job.org, a scheduled GitHub
// Actions workflow, etc.) hits that URL with this token to trigger a
// refresh instead. Leave blank to disable the endpoint entirely (recommended
// unless you actually need it). Generate one with:
//   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
define('CRON_TOKEN', '');
