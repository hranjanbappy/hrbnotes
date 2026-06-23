<?php /** Search results page. */ ?>
<div class="app-shell">
    <aside class="sidebar">
        <?php require APP_PATH . '/views/partials/_tree.php'; ?>
    </aside>

    <section class="content-area p-3 p-lg-4">
        <h2 class="mb-3">Search</h2>
        <form method="get" action="<?= BASE_URL ?>/" class="mb-4">
            <input type="hidden" name="route" value="search">
            <div class="input-group">
                <input name="q" class="form-control form-control-lg" value="<?= Security::e($query) ?>"
                       placeholder="Search titles, content, tags…" autofocus>
                <button class="btn btn-primary">Search</button>
            </div>
        </form>

        <?php if ($query !== ''): ?>
            <p class="text-muted"><?= count($results) ?> result(s) for
                &ldquo;<?= Security::e($query) ?>&rdquo;</p>
            <div class="search-results">
                <?php foreach ($results as $r): ?>
                    <a class="search-result" href="<?= BASE_URL ?>/?route=workspace&path=<?= urlencode($r['path']) ?>">
                        <div class="sr-title"><?= Security::e($r['title']) ?></div>
                        <div class="sr-path"><?= Security::e($r['path']) ?> · <?= Security::e($r['modified_at']) ?></div>
                        <div class="sr-snippet"><?= Search::highlight($r['snippet']) ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($results)): ?>
                    <p class="text-muted">No matches.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
