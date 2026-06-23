<?php
/**
 * Auth - authentication for the single admin user.
 *
 * Passwords are stored with password_hash() (bcrypt/argon depending on PHP).
 * Login state lives in the session.
 */

declare(strict_types=1);

class Auth
{
    /** Create a user. Used by the install wizard and settings management. */
    public static function createUser(string $username, string $password, string $role = 'user'): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        Database::run(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, $hash, $role]
        );
    }

    /** Whether any user account exists yet. */
    public static function hasUser(): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM users') > 0;
    }

    /** Attempt login. Returns true on success and sets the session. */
    public static function attempt(string $username, string $password): bool
    {
        $user = Database::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Transparently upgrade the hash if the algorithm changed.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $new = password_hash($password, PASSWORD_DEFAULT);
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [$new, $user['id']]);
        }

        session_regenerate_id(true);
        Session::set('user_id', (int) $user['id']);
        Session::set('username', $user['username']);
        Session::set('role', $user['role']);
        return true;
    }

    public static function check(): bool
    {
        return Session::get('user_id') !== null;
    }

    public static function user(): ?array
    {
        $id = Session::get('user_id');
        if ($id === null) {
            return null;
        }
        $role = Session::get('role');
        if ($role === null) {
            try {
                $dbRole = Database::scalar('SELECT role FROM users WHERE id = ?', [$id]);
                if ($dbRole !== false && $dbRole !== null) {
                    $role = (string) $dbRole;
                    Session::set('role', $role);
                } else {
                    $role = 'user';
                }
            } catch (Throwable $e) {
                $role = 'user';
            }
        }
        return [
            'id'       => $id,
            'username' => Session::get('username'),
            'role'     => $role
        ];
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }

    /**
     * Get the vault paths this user is authorized to access.
     * Returns null if the user is an admin (who has full access to all vaults).
     * Returns an array of string paths (e.g. ['research', 'ai']) otherwise.
     */
    public static function allowedVaults(): ?array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }
        if ($user['role'] === 'admin') {
            return null;
        }
        $rows = Database::all('SELECT vault_path FROM user_permissions WHERE user_id = ?', [$user['id']]);
        return array_column($rows, 'vault_path');
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    /** Redirect to login if not authenticated. */
    public static function require(): void
    {
        if (!self::check()) {
            if (self::isAjax()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Authentication required.']);
                exit;
            }
            header('Location: ' . BASE_URL . '/?route=login');
            exit;
        }
    }

    private static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
