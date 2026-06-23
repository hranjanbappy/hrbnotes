<?php
/**
 * Csrf - synchroniser-token CSRF protection.
 *
 * A per-session token is embedded in every state-changing form / AJAX request
 * and verified on POST. Verification uses hash_equals (constant time).
 */

declare(strict_types=1);

class Csrf
{
    private const KEY = '_csrf_token';

    /** Return the current token, creating one if needed. */
    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = Security::token(32);
        }
        return $_SESSION[self::KEY];
    }

    /** Hidden input for HTML forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . Security::e(self::token()) . '">';
    }

    /** Validate a supplied token against the session token. */
    public static function check(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * Guard a state-changing request. Pulls the token from POST body or the
     * X-CSRF-Token header (for fetch() calls). Aborts with 419 on failure.
     */
    public static function verifyRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            return;
        }
        $token = $_POST['csrf_token']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

        if (!self::check($token)) {
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF token mismatch. Refresh and try again.']);
            exit;
        }
    }
}
