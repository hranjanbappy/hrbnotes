<?php
/**
 * Front controller / router for Hrb Notes.
 *
 * All requests enter here. Routing is via ?route=name so the app works on any
 * shared host without URL-rewriting (a friendly .htaccess is provided too).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

// ---------------------------------------------------------------------------
// Send hardening headers on every response.
// ---------------------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data:; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
    . "font-src 'self' https://cdn.jsdelivr.net; "
    . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net");

// ---------------------------------------------------------------------------
// Not installed yet? Send the user to the wizard (except for assets).
// ---------------------------------------------------------------------------
if (!Database::isInstalled()) {
    header('Location: ' . BASE_URL . '/install.php');
    exit;
}

// ---------------------------------------------------------------------------
// Route table: route name => [Controller, method]
// ---------------------------------------------------------------------------
$routes = [
    // Auth
    'login'       => [AuthController::class,      'showLogin'],
    'login.post'  => [AuthController::class,      'login'],
    'logout'      => [AuthController::class,      'logout'],

    // App pages
    'dashboard'   => [DashboardController::class, 'index'],
    'workspace'   => [NoteController::class,      'workspace'],
    'search'      => [SearchController::class,    'page'],
    'tags'        => [TagController::class,       'index'],
    'tag'         => [TagController::class,       'show'],

    // JSON API
    'note.get'    => [NoteController::class,      'apiGet'],
    'note.save'   => [NoteController::class,      'apiSave'],
    'note.create' => [NoteController::class,      'apiCreate'],
    'note.delete' => [NoteController::class,      'apiDelete'],
    'tree'        => [NoteController::class,      'apiTree'],
    'search.api'  => [SearchController::class,    'api'],
    'rescan'        => [VaultController::class,     'rescan'],
    'vault.import'  => [VaultController::class,     'vaultImport'],
    'vault.export'  => [VaultController::class,     'vaultExport'],
    'vault.clear'   => [VaultController::class,     'clear'],
    'vault.delete_folder' => [VaultController::class, 'deleteFolder'],
    'upload'        => [UploadController::class,    'upload'],
    'uploads.clear' => [UploadController::class,    'clear'],

    // Cloud Sync API
    'sync.init'     => [SyncController::class,      'init'],
    'sync.upload'   => [SyncController::class,      'upload'],
    'sync.download' => [SyncController::class,      'download'],
    'sync.done'     => [SyncController::class,      'done'],

    // Settings
    'settings'                    => [SettingsController::class, 'settings'],
    'settings.save_app_name'      => [SettingsController::class, 'saveAppName'],
    'settings.change_password'    => [SettingsController::class, 'changePassword'],
    'settings.create_user'        => [SettingsController::class, 'createUser'],
    'settings.delete_user'        => [SettingsController::class, 'deleteUser'],
    'settings.update_user_vaults' => [SettingsController::class, 'updateUserVaults'],

    // Media
    'media'         => [VaultController::class,     'media'],
];

$route = $_GET['route'] ?? 'dashboard';

// The login form posts to ?route=login with POST -> map to login.post.
if ($route === 'login' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $route = 'login.post';
}

if (!isset($routes[$route])) {
    http_response_code(404);
    echo '404 - Unknown route';
    exit;
}

[$class, $method] = $routes[$route];

try {
    $controller = new $class();
    $controller->$method();
} catch (Throwable $e) {
    if (APP_DEBUG) {
        http_response_code(500);
        echo '<pre>' . Security::e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        http_response_code(500);
        echo 'Internal server error.';
    }
}
