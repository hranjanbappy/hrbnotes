<?php
/**
 * Settings - dynamic database configuration options (e.g. app name).
 */

declare(strict_types=1);

class Settings
{
    private static ?string $appName = null;

    /** Retrieve the application name, falling back to constant if not configured. */
    public static function appName(): string
    {
        if (self::$appName !== null) {
            return self::$appName;
        }

        try {
            $val = Database::scalar("SELECT value FROM settings WHERE key = 'app_name'");
            if ($val !== false && $val !== null) {
                self::$appName = (string) $val;
            } else {
                self::$appName = APP_NAME;
            }
        } catch (Throwable $e) {
            self::$appName = APP_NAME;
        }

        return self::$appName;
    }

    /** Set/update a configuration option. */
    public static function set(string $key, string $value): void
    {
        Database::run(
            "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)",
            [$key, $value]
        );

        if ($key === 'app_name') {
            self::$appName = $value;
        }
    }
}
