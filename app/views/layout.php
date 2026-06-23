<?php
/** Main HTML layout. Expects $content (rendered view) and $title. */
$user  = Auth::user();
$flash = Session::takeFlash();
$theme = $_COOKIE['pkh_theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= Security::e($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <meta name="base-url" content="<?= Security::e(BASE_URL) ?>">
    <meta name="csrf-token" content="<?= Security::e(Csrf::token()) ?>">
    <title><?= Security::e(($title ?? 'Home') . ' · ' . Settings::appName()) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <link href="<?= BASE_URL ?>/assets/css/app.css?v=1.2.3" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg app-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= BASE_URL ?>/?route=dashboard">
            <span class="brand-dot"></span><?= Security::e(Settings::appName()) ?>
        </a>

        <form class="d-flex flex-grow-1 mx-lg-4 navbar-search" role="search"
              action="<?= BASE_URL ?>/" method="get">
            <input type="hidden" name="route" value="search">
            <input id="globalSearch" class="form-control" type="search" name="q"
                   placeholder="Search notes…" autocomplete="off"
                   value="<?= Security::e($query ?? '') ?>">
            <div id="searchDropdown" class="search-dropdown"></div>
        </form>

        <div class="navbar-nav-right d-flex align-items-center gap-2">
            <a class="nav-link" href="<?= BASE_URL ?>/?route=workspace">Notes</a>
            <a class="nav-link" href="<?= BASE_URL ?>/?route=tags">Tags</a>
            <a class="nav-link" href="<?= BASE_URL ?>/?route=settings" title="Settings">
                <i class="fas fa-cog"></i>
            </a>
            <a id="navNewNote" class="nav-link" href="<?= BASE_URL ?>/?route=workspace&new=1" title="New Note">
                <i class="fas fa-plus"></i>
            </a>
            <button id="themeToggle" class="btn btn-sm btn-outline-secondary" title="Toggle theme">&#9681;</button>
            <?php if ($user): ?>
                <span class="navbar-text small d-none d-md-inline">
                    <?= Security::e($user['username']) ?>
                </span>
                <form action="<?= BASE_URL ?>/?route=logout" method="post" class="m-0">
                    <?= Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-danger">Logout</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php if ($flash): ?>
    <div class="container-fluid pt-2">
        <?php foreach ($flash as $f): ?>
            <div class="alert alert-<?= Security::e($f['type']) ?> alert-dismissible fade show py-2" role="alert">
                <?= Security::e($f['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<main><?= $content ?></main>

<?php if ($user): ?>
<!-- Mobile bottom nav — global, hidden on desktop (lg+) -->
<nav class="mobile-bottom-nav d-lg-none" id="mobileBottomNav">
    <button class="mbn-btn" id="mbnVault" title="Vault files">
        <span class="mbn-icon">&#128193;</span>
        <span class="mbn-label">Files</span>
    </button>
    <button class="mbn-btn" id="mbnSearch" title="Search">
        <span class="mbn-icon">&#128269;</span>
        <span class="mbn-label">Search</span>
    </button>
    <button class="mbn-btn" id="mbnNewBtn" title="New Note">
        <span class="mbn-icon">&#10133;</span>
        <span class="mbn-label">New</span>
    </button>
    <button class="mbn-btn" id="mbnModeBtn" title="Toggle edit/view">
        <span class="mbn-icon" id="mbnModeIcon">&#9998;</span>
        <span class="mbn-label" id="mbnModeLabel">Edit</span>
    </button>
    <button class="mbn-btn" id="mbnSaveBtn" title="Save note" disabled>
        <span class="mbn-icon">&#128190;</span>
        <span class="mbn-label">Save</span>
    </button>
</nav>
<!-- New Note Modal -->
<div class="modal fade" id="newNoteModal" tabindex="-1" aria-labelledby="newNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--pkh-panel); border: 1px solid var(--pkh-border); border-radius: var(--pkh-radius);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title" id="newNoteModalLabel" style="font-family: var(--font-heading); font-weight: 700; color: var(--pkh-accent);">Create New Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: var(--bs-theme-light-btn-close-filter);"></button>
            </div>
            <div class="modal-body py-3">
                <div class="mb-3">
                    <label for="newNoteModalPath" class="form-label small text-muted text-uppercase" style="font-weight: 600; letter-spacing: 0.05em;">Note Path</label>
                    <input type="text" class="form-control" id="newNoteModalPath" placeholder="e.g. ideas/my-new-note" style="background: var(--pkh-panel-2); border: 1px solid var(--pkh-border-subtle); color: var(--pkh-text); border-radius: 8px;">
                    <div class="form-text text-muted small mt-1">Folders are created automatically. Extension (.md) is optional.</div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" style="min-height: unset; padding: 0.35rem 0.95rem;">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="btnNewNoteModalConfirm" style="min-height: unset; padding: 0.35rem 0.95rem;">Create</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Markdown rendering + editor (loaded from CDN; safe to self-host for offline use) -->
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=1.2.3"></script>
</body>
</html>
