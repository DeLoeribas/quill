<?php

declare(strict_types=1);

final class Auth
{
    public static function isConfigured(): bool
    {
        return Users::all() !== [];
    }

    /** The logged-in account, or null. Also drops a session whose account was deleted. */
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        // Sessions from before multi-user support only carry 'authenticated'; they
        // belonged to the single login, which the migration turned into the first admin.
        if ($id === null && !empty($_SESSION['authenticated'])) {
            $id = self::firstAdminId();
            if ($id !== null) {
                $_SESSION['user_id'] = $id;
            }
        }
        if (!is_string($id)) {
            return null;
        }
        $user = Users::find($id);
        if ($user === null) {
            $_SESSION = [];
        }
        return $user;
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return !empty(self::user()['is_admin']);
    }

    public static function setup(string $username, string $password): void
    {
        if (self::isConfigured()) {
            throw new RuntimeException('Login is already configured');
        }
        $user = Users::create($username, $password, true);
        self::markLoggedIn($user['id']);
    }

    public static function attempt(string $username, string $password): bool
    {
        $user = Users::findByUsername($username);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        self::markLoggedIn($user['id']);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        // Drop the cookie too, so later requests don't resume (and recreate) an empty session.
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    /** Rejects the request unless logged in, and points all per-user data paths at that account. */
    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            json_error('Unauthorized', 401);
        }
        CurrentUser::set($user['id']);
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (empty($user['is_admin'])) {
            json_error('Forbidden', 403);
        }
        return $user;
    }

    private static function firstAdminId(): ?string
    {
        foreach (Users::all() as $user) {
            if (!empty($user['is_admin'])) {
                return $user['id'];
            }
        }
        return null;
    }

    private static function markLoggedIn(string $userId): void
    {
        // bootstrap.php only resumes existing sessions; logging in is where a new one starts.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $userId;
        CurrentUser::set($userId);
    }
}
