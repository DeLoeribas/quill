<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/RefreshService.php';

// Cap the stderr log before this run writes anything to it — safe even though the
// shell may hold data/cron-stderr.log open for append (O_APPEND writes always land
// at the file's current end, so truncating it here doesn't race with that).
cap_log_file(CRON_STDERR_LOG_FILE, CRON_LOG_MAX_BYTES);

$results = RefreshService::refreshAll(false);

$refreshed = 0;
$skipped = 0;
$disabled = 0;
$errors = 0;

foreach ($results as $outcome) {
    if ($outcome['status'] === 'disabled') {
        $disabled++;
        continue;
    }
    if ($outcome['status'] === 'skipped') {
        $skipped++;
        continue;
    }
    if ($outcome['status'] === 'error') {
        $errors++;
        fwrite(STDERR, sprintf("[%s] feed %s error: %s\n", now_iso8601(), $outcome['feed_id'], $outcome['error']));
        continue;
    }
    $refreshed++;
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
fwrite(STDOUT, $line);

exit($errors > 0 && $refreshed === 0 ? 1 : 0);
