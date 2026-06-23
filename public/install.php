<?php
/**
 * Installation wizard.
 *
 * Run once after uploading the app: visit /install.php in the browser. It
 * checks requirements, creates the SQLite database + schema, creates the admin
 * user, and performs the first vault scan. Refuses to run if already installed.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$errors = [];
$done   = false;

// Requirement checks -------------------------------------------------------
$checks = [
    'PHP >= 8.1'           => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO SQLite extension' => extension_loaded('pdo_sqlite'),
    'mbstring extension'   => extension_loaded('mbstring'),
    'storage/ writable'    => is_writable(STORAGE_PATH) || @mkdir(STORAGE_PATH, 0775, true),
    'vault/ writable'      => is_writable(VAULT_PATH)   || @mkdir(VAULT_PATH, 0775, true),
    'uploads/ writable'    => is_writable(UPLOAD_PATH)  || @mkdir(UPLOAD_PATH, 0775, true),
];
$allOk = !in_array(false, $checks, true);

$alreadyInstalled = Database::isInstalled();

// Handle form -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled && $allOk) {
    Csrf::verifyRequest();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if ($username === '' || strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        try {
            Database::migrate();
            if (!Auth::hasUser()) {
                Auth::createUser($username, $password, 'admin');
            }
            // First scan (best-effort; sample vault may be present).
            try { VaultScanner::rescan(); } catch (Throwable $e) { /* ignore */ }
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}

$theme = $_COOKIE['pkh_theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= Security::e($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install · <?= Security::e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/app.css" rel="stylesheet">
</head>
<body class="login-body">
<div class="login-card" style="max-width:520px">
    <div class="login-brand">
        <span class="brand-dot"></span>
        <h1>Install <?= Security::e(APP_NAME) ?></h1>
    </div>

    <?php if ($alreadyInstalled): ?>
        <div class="alert alert-warning">Already installed.
            <a href="<?= BASE_URL ?>/?route=login">Go to login</a>.</div>
        <p class="text-muted small">For security, delete <code>public/install.php</code> from the server.</p>

    <?php elseif ($done): ?>
        <div class="alert alert-success">
            Installation complete! Your admin account is ready.
        </div>
        <p><strong>Important:</strong> delete <code>public/install.php</code> now.</p>
        <a class="btn btn-primary w-100" href="<?= BASE_URL ?>/?route=login">Continue to login</a>

    <?php else: ?>
        <h6 class="panel-title">Requirements</h6>
        <ul class="list-flush mb-3">
            <?php foreach ($checks as $label => $ok): ?>
                <li>
                    <?= $ok ? '<span style="color:#38b000">✔</span>' : '<span style="color:#e5484d">✘</span>' ?>
                    <?= Security::e($label) ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (!$allOk): ?>
            <div class="alert alert-danger">Fix the failing requirements above, then reload.</div>
        <?php endif; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-danger py-2"><?= Security::e($e) ?></div>
        <?php endforeach; ?>

        <form method="post">
            <?= Csrf::field() ?>
            <h6 class="panel-title mt-3">Create admin user</h6>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input name="username" class="form-control" value="<?= Security::e($_POST['username'] ?? 'admin') ?>"
                       <?= $allOk ? '' : 'disabled' ?> required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control"
                       <?= $allOk ? '' : 'disabled' ?> required>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm password</label>
                <input type="password" name="confirm" class="form-control"
                       <?= $allOk ? '' : 'disabled' ?> required>
            </div>
            <button class="btn btn-primary w-100" <?= $allOk ? '' : 'disabled' ?>>Install</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
