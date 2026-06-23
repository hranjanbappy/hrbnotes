<?php
/**
 * Database - thin PDO/SQLite singleton wrapper.
 *
 * Provides one shared connection plus small helpers for the common query
 * patterns used across the app. All statements are prepared, so callers never
 * concatenate user input into SQL (SQL-injection protection).
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    /** Return the shared PDO connection, creating it on first use. */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (!is_dir(STORAGE_PATH)) {
            @mkdir(STORAGE_PATH, 0775, true);
        }

        $dsn = 'sqlite:' . DB_FILE;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        self::runMigrations($pdo);

        self::$pdo = $pdo;
        return $pdo;
    }

    /** True if the schema has been initialised and an admin/user exists. */
    public static function isInstalled(): bool
    {
        if (!is_file(DB_FILE)) {
            return false;
        }
        try {
            $row = self::pdo()->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='users'"
            )->fetch();
            if (!$row) {
                return false;
            }
            $count = (int) self::pdo()->query("SELECT COUNT(*) FROM users")->fetchColumn();
            return $count > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Run the schema.sql file (idempotent - uses IF NOT EXISTS). */
    public static function migrate(): void
    {
        $sql = file_get_contents(SCHEMA_FILE);
        if ($sql === false) {
            throw new RuntimeException('Unable to read schema file.');
        }
        self::pdo()->exec($sql);
    }

    /** Prepare + execute and return the statement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch a single row (or null). */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch all rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Fetch a single scalar value from the first column. */
    public static function scalar(string $sql, array $params = [])
    {
        return self::run($sql, $params)->fetchColumn();
    }

    /** Last inserted row id. */
    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    private static function runMigrations(PDO $pdo): void
    {
        // 1. Create settings table
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );");

        // 2. Create user_permissions table
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
            user_id INTEGER NOT NULL,
            vault_path TEXT NOT NULL,
            PRIMARY KEY (user_id, vault_path),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );");

        // 3. Add role column to users if not exists
        $tableExists = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='users'"
        )->fetch();
        if ($tableExists) {
            $stmt = $pdo->query("PRAGMA table_info(users)");
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasRole = false;
            foreach ($cols as $c) {
                if ($c['name'] === 'role') {
                    $hasRole = true;
                    break;
                }
            }
            if (!$hasRole) {
                $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
                // Set existing users as admin since this is single admin by design prior to this migration
                $pdo->exec("UPDATE users SET role = 'admin'");
            }
        }
    }
}
