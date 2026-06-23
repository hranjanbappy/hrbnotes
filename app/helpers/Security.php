<?php
/**
 * Security - path-traversal protection, output escaping, misc helpers.
 *
 * Every filesystem access that involves a user-supplied path MUST go through
 * Security::safeVaultPath() / safeUploadPath(). These resolve the real path
 * and assert it stays inside the intended base directory.
 */

declare(strict_types=1);

class Security
{
    /**
     * Resolve a vault-relative path to an absolute path, guaranteeing it stays
     * inside VAULT_PATH. Returns null on any traversal attempt or bad input.
     *
     * @param string $relative e.g. "research/safir.md"
     * @param bool   $mustExist whether the target file must already exist
     */
    public static function safeVaultPath(string $relative, bool $mustExist = true): ?string
    {
        return self::safePath(VAULT_PATH, $relative, $mustExist);
    }

    /** Same contract as safeVaultPath() but rooted at UPLOAD_PATH. */
    public static function safeUploadPath(string $relative, bool $mustExist = true): ?string
    {
        return self::safePath(UPLOAD_PATH, $relative, $mustExist);
    }

    /**
     * Generic confine-to-base resolver.
     */
    private static function safePath(string $base, string $relative, bool $mustExist): ?string
    {
        // Normalise separators and strip any leading slashes / drive letters.
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');

        // Reject obvious traversal tokens and null bytes outright.
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }

        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }

        $target = $baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if ($mustExist) {
            $real = realpath($target);
            if ($real === false) {
                return null;
            }
        } else {
            // Target may not exist yet (e.g. new note in a new sub-folder).
            // '..' and null bytes are already rejected above, so build the path
            // directly from the verified $baseReal rather than relying on realpath,
            // which fails when intermediate directories haven't been created yet.
            $real = $target;
        }

        // Confinement check using a normalised prefix comparison.
        $prefix = $baseReal . DIRECTORY_SEPARATOR;
        if ($real !== $baseReal && !str_starts_with($real . DIRECTORY_SEPARATOR, $prefix)
            && !str_starts_with($real, $prefix)) {
            return null;
        }

        return $real;
    }

    /** HTML-escape for safe output (XSS protection). */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build a URL-safe slug from a title or filename. */
    public static function slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
        return trim($text, '-');
    }

    /** Generate a cryptographically-strong random token. */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
