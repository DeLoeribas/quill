<?php

declare(strict_types=1);

// HTTP alternative to cron/refresh.php, for hosts with no shell/SSH cron
// access (common on cheap shared hosting, where the only scheduling option
// is a "URL cron" panel field, or an outside pinger like cron-job.org / a
// scheduled GitHub Actions workflow). Since the caller can't hold a login
// session, this is gated by CRON_TOKEN (see src/config.sample.php) instead
// of Auth::requireLogin() — set CRON_TOKEN and pass it as ?token=...
//
// Disabled by default: returns 403 unless CRON_TOKEN is set in config.php.

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/RefreshService.php';

if (!defined('CRON_TOKEN') || CRON_TOKEN === '') {
    json_error('CRON_TOKEN is not configured', 403);
}

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || !hash_equals(CRON_TOKEN, $token)) {
    json_error('invalid token', 403);
}

set_time_limit(0);

$results = RefreshService::refreshAll(false);

$refreshed = 0;
$skipped = 0;
$disabled = 0;
$errors = 0;

foreach ($results as $outcome) {
    if ($outcome['status'] === 'disabled') {
        $disabled++;
    } elseif ($outcome['status'] === 'skipped') {
        $skipped++;
    } elseif ($outcome['status'] === 'error') {
        $errors++;
    } else {
        $refreshed++;
    }
}

$line = sprintf(
    "[%s] refreshed=%d skipped=%d disabled=%d errors=%d\n",
    now_iso8601(),
    $refreshed,
    $skipped,
    $disabled,
    $errors
);
cap_log_file(CRON_LOG_FILE, CRON_LOG_MAX_BYTES);
file_put_contents(CRON_LOG_FILE, $line, FILE_APPEND | LOCK_EX);

json_response([
    'ok' => true,
    'refreshed' => $refreshed,
    'skipped' => $skipped,
    'disabled' => $disabled,
    'errors' => $errors,
]);
