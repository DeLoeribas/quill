<?php

declare(strict_types=1);

/** IP-based brute-force protection for the login endpoint. Keyed by IP since there's exactly one valid account. */
final class RateLimiter
{
    private const LOCKOUT_THRESHOLD = 5;
    private const BASE_BACKOFF_SECONDS = 30;
    private const MAX_BACKOFF_SECONDS = 900;
    private const DECAY_SECONDS = 3600;
    private const PRUNE_AFTER_SECONDS = 86400;
    private const MAX_TRACKED_IPS = 1000;

    public static function guardLogin(string $ip): void
    {
        $data = Storage::read(LOGIN_ATTEMPTS_FILE, []);
        $entry = $data[$ip] ?? null;
        if ($entry === null || empty($entry['locked_until'])) {
            return;
        }

        $lockedUntil = new DateTimeImmutable($entry['locked_until']);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($lockedUntil <= $now) {
            return;
        }

        $minutes = (int) ceil(($lockedUntil->getTimestamp() - $now->getTimestamp()) / 60);
        $wait = $minutes >= 1 ? "{$minutes} minute" . ($minutes === 1 ? '' : 's') : 'under a minute';
        json_error("Too many failed attempts. Try again in {$wait}.", 429);
    }

    public static function recordFailure(string $ip): void
    {
        Storage::update(LOGIN_ATTEMPTS_FILE, [], function (array $data) use ($ip) {
            $now = now_iso8601();
            $entry = $data[$ip] ?? null;

            $fresh = $entry === null
                || (!empty($entry['locked_until']) && new DateTimeImmutable($entry['locked_until']) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))
                    && (time() - (new DateTimeImmutable($entry['last_failure_at']))->getTimestamp()) > self::DECAY_SECONDS);

            $failures = $fresh ? 1 : (int) ($entry['failures'] ?? 0) + 1;

            $data[$ip] = [
                'failures' => $failures,
                'first_failure_at' => $fresh ? $now : ($entry['first_failure_at'] ?? $now),
                'last_failure_at' => $now,
                'locked_until' => $failures >= self::LOCKOUT_THRESHOLD
                    ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                        ->modify('+' . self::backoffSeconds($failures) . ' seconds')
                        ->format(DateTimeInterface::ATOM)
                    : null,
            ];

            return self::prune($data);
        });
    }

    public static function recordSuccess(string $ip): void
    {
        Storage::update(LOGIN_ATTEMPTS_FILE, [], function (array $data) use ($ip) {
            unset($data[$ip]);
            return $data;
        });
    }

    private static function backoffSeconds(int $failures): int
    {
        $exponent = $failures - self::LOCKOUT_THRESHOLD;
        return min(self::BASE_BACKOFF_SECONDS * 2 ** $exponent, self::MAX_BACKOFF_SECONDS);
    }

    /** Drops stale, unlocked entries and caps total tracked IPs — protects against botnet-driven file growth. */
    private static function prune(array $data): array
    {
        $now = time();
        $data = array_filter($data, function (array $entry) use ($now) {
            $locked = !empty($entry['locked_until']) && new DateTimeImmutable($entry['locked_until']) > new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $stale = ($now - (new DateTimeImmutable($entry['last_failure_at']))->getTimestamp()) > self::PRUNE_AFTER_SECONDS;
            return $locked || !$stale;
        });

        if (count($data) > self::MAX_TRACKED_IPS) {
            uasort($data, fn ($a, $b) => strcmp($b['last_failure_at'], $a['last_failure_at']));
            $data = array_slice($data, 0, self::MAX_TRACKED_IPS, true);
        }

        return $data;
    }
}
