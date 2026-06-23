<?php
/** Standalone login page (no layout). */
$flash = Session::takeFlash();
$theme = $_COOKIE['pkh_theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= Security::e($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · <?= Security::e(Settings::appName()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/app.css" rel="stylesheet">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <span class="brand-dot"></span>
        <h1><?= Security::e(Settings::appName()) ?></h1>
        <p class="text-muted">Your Obsidian vault, on the web.</p>
    </div>

    <?php foreach ($flash as $f): ?>
        <div class="alert alert-<?= Security::e($f['type']) ?> py-2"><?= Security::e($f['message']) ?></div>
    <?php endforeach; ?>

    <form action="<?= BASE_URL ?>/?route=login" method="post">
        <?= Csrf::field() ?>
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input name="username" class="form-control" autofocus required>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100">Sign in</button>
    </form>
</div>
</body>
</html>
