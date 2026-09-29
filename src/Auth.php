<?php

declare(strict_types=1);

final class Auth
{
    public static function isConfigured(): bool
    {
        return is_file(AUTH_FILE);
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    public static function setup(string $username, string $password): void
    {
        if (self::isConfigured()) {
            throw new RuntimeException('Login is already configured');
        }
        Storage::update(AUTH_FILE, [], fn () => [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        self::markLoggedIn();
    }

    public static function attempt(string $username, string $password): bool
    {
        $data = Storage::read(AUTH_FILE, []);
        if (!isset($data['username'], $data['password_hash'])
            || $data['username'] !== $username
            || !password_verify($password, $data['password_hash'])) {
            return false;
        }
        self::markLoggedIn();
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

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            json_error('Unauthorized', 401);
        }
    }

    private static function markLoggedIn(): void
    {
        // bootstrap.php only resumes existing sessions; logging in is where a new one starts.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
    }
}
