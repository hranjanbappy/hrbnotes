<?php
/**
 * Hrb Notes - Application Configuration
 *
 * Central configuration file. All paths are derived from BASE_PATH so the
 * application can be moved or deployed on shared hosting without edits.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
// BASE_PATH = project root (one level above /public).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

define('APP_PATH',     BASE_PATH . '/app');
define('CONFIG_PATH',  BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('VAULT_PATH',   BASE_PATH . '/vault');
define('UPLOAD_PATH',  BASE_PATH . '/uploads');
define('ASSET_PATH',   BASE_PATH . '/public/assets');

define('DB_FILE',      STORAGE_PATH . '/database.sqlite');
define('SCHEMA_FILE',  CONFIG_PATH  . '/schema.sql');

// ---------------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------------
define('APP_NAME', 'Hrb Notes');

/*
 * BASE_URL is the web path the app is served from (the /public folder, or the
 * web root if you point your document root straight at /public).
 *
 * Examples:
 *   - Doc root is /public            => BASE_URL = ''      (empty)
 *   - App lives in a subfolder /khub => BASE_URL = '/khub'
 *
 * It is auto-detected below, but you may hard-code it if detection fails.
 */
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');
    // If the front controller sits inside /public, strip nothing; routing is
    // relative to the directory that holds index.php.
    define('BASE_URL', $scriptDir === '/' ? '' : $scriptDir);
}

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------
define('SESSION_NAME', 'pkh_session');
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours

// Allowed upload extensions and the maximum size (bytes).
define('UPLOAD_ALLOWED_EXT', ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'zip']);
define('UPLOAD_MAX_BYTES', 25 * 1024 * 1024); // 25 MB

// File extensions treated as notes inside the vault.
define('NOTE_EXTENSIONS', ['md', 'markdown']);

// ---------------------------------------------------------------------------
// Error reporting - quiet in production. Flip to true while installing.
// ---------------------------------------------------------------------------
define('APP_DEBUG', false);

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
