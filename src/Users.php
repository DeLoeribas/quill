<?php

declare(strict_types=1);

/**
 * Whose data the current request (or cron loop iteration) works on. Every
 * per-user path — feeds_file(), items_dir(), pages_dir() — goes through
 * this, so it must be set before any of them are used: Auth::requireLogin()
 * does it for API requests, the cron scripts do it per user.
 */
final class CurrentUser
{
    private static ?string $id = null;

    public static function set(?string $id): void
    {
        self::$id = $id;
    }

    public static function id(): string
    {
        if (self::$id === null) {
            throw new LogicException('No current user set');
        }
        return self::$id;
    }

    public static function isSet(): bool
    {
        return self::$id !== null;
    }
}

/**
 * The accounts in USERS_FILE: { "users": [ {id, username, password_hash, is_admin, created_at} ] }.
 * Each user's feeds, items and page texts live in their own data/users/<id>/ directory.
 */
final class Users
{
    /** @return array<int, array> */
    public static function all(): array
    {
        return Storage::read(USERS_FILE, ['users' => []])['users'] ?? [];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        return null;
    }

    public static function findByUsername(string $username): ?array
    {
        foreach (self::all() as $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                return $user;
            }
        }
        return null;
    }

    /** Throws InvalidArgumentException with a user-facing message on bad input. */
    public static function create(string $username, string $password, bool $isAdmin): array
    {
        self::validate($username, $password);

        $user = null;
        Storage::update(USERS_FILE, ['users' => []], function (array $data) use ($username, $password, $isAdmin, &$user) {
            foreach ($data['users'] as $existing) {
                if (strcasecmp($existing['username'], $username) === 0) {
                    throw new InvalidArgumentException('That username is already taken');
                }
            }
            $user = [
                'id' => 'usr_' . bin2hex(random_bytes(6)),
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'is_admin' => $isAdmin,
                'created_at' => now_iso8601(),
            ];
            $data['users'][] = $user;
            return $data;
        });

        return $user;
    }

    public static function setPassword(string $id, string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters');
        }
        $found = false;
        Storage::update(USERS_FILE, ['users' => []], function (array $data) use ($id, $password, &$found) {
            foreach ($data['users'] as &$user) {
                if ($user['id'] === $id) {
                    $user['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
                    $found = true;
                }
            }
            unset($user);
            return $data;
        });
        if (!$found) {
            throw new InvalidArgumentException('User not found');
        }
    }

    /** Removes the account and all of its data. Refuses to remove the last admin. */
    public static function delete(string $id): void
    {
        $found = false;
        Storage::update(USERS_FILE, ['users' => []], function (array $data) use ($id, &$found) {
            $remaining = [];
            foreach ($data['users'] as $user) {
                if ($user['id'] === $id) {
                    $found = true;
                    continue;
                }
                $remaining[] = $user;
            }
            if ($found && !array_filter($remaining, fn ($u) => !empty($u['is_admin']))) {
                throw new InvalidArgumentException('Cannot delete the last admin');
            }
            $data['users'] = $remaining;
            return $data;
        });
        if (!$found) {
            throw new InvalidArgumentException('User not found');
        }
        self::removeDir(user_data_dir($id));
    }

    private static function validate(string $username, string $password): void
    {
        if ($username === '' || strlen($password) < 8) {
            throw new InvalidArgumentException('Username is required and password must be at least 8 characters');
        }
        if (mb_strlen($username) > 64) {
            throw new InvalidArgumentException('Username is too long');
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($dir);
    }

    /**
     * One-time upgrade from the single-login layout (data/auth.json + data/feeds.json,
     * data/items/, data/pages/) to users.json + data/users/<id>/. The old login becomes
     * the admin and keeps all its data. auth.json is renamed, not deleted.
     */
    public static function migrateLegacyIfNeeded(): void
    {
        $legacyAuth = DATA_DIR . '/auth.json';
        if (is_file(USERS_FILE) || !is_file($legacyAuth)) {
            return;
        }

        $lock = fopen(DATA_DIR . '/.migrate.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('could not create data/.migrate.lock — is data/ writable by the web server?');
        }
        flock($lock, LOCK_EX);
        try {
            // Another request may have finished the migration while this one waited.
            if (is_file(USERS_FILE) || !is_file($legacyAuth)) {
                return;
            }
            $auth = Storage::read($legacyAuth, []);
            if (!isset($auth['username'], $auth['password_hash'])) {
                return;
            }

            $id = 'usr_' . bin2hex(random_bytes(6));
            $dir = user_data_dir($id);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                throw new RuntimeException("could not create {$dir} — is data/ writable by the web server?");
            }

            $moves = [
                defined('FEEDS_FILE') ? FEEDS_FILE : DATA_DIR . '/feeds.json' => $dir . '/feeds.json',
                defined('ITEMS_DIR') ? ITEMS_DIR : DATA_DIR . '/items' => $dir . '/items',
                defined('PAGES_DIR') ? PAGES_DIR : DATA_DIR . '/pages' => $dir . '/pages',
            ];
            // All or nothing: if any move fails, put back what was moved and stop, rather than
            // creating the admin account with its data left behind (it would look empty).
            $moved = [];
            foreach ($moves as $from => $to) {
                if (!file_exists($from)) {
                    continue;
                }
                if (!@rename($from, $to)) {
                    foreach (array_reverse($moved, true) as $back => $at) {
                        @rename($at, $back);
                    }
                    @rmdir($dir);
                    throw new RuntimeException("could not move {$from} — is data/ writable by the web server?");
                }
                $moved[$from] = $to;
            }
            Storage::update(USERS_FILE, ['users' => []], fn () => ['users' => [[
                'id' => $id,
                'username' => $auth['username'],
                'password_hash' => $auth['password_hash'],
                'is_admin' => true,
                'created_at' => now_iso8601(),
            ]]]);
            rename($legacyAuth, $legacyAuth . '.migrated');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
