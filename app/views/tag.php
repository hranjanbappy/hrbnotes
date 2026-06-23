<?php /** Single tag page. */ ?>
<div class="app-shell">
    <aside class="sidebar">
        <?php require APP_PATH . '/views/partials/_tree.php'; ?>
    </aside>
    <section class="content-area p-3 p-lg-4">
        <h2 class="mb-3">#<?= Security::e($tag) ?></h2>
        <p class="text-muted"><?= count($notes) ?> note(s)</p>
        <ul class="list-flush">
            <?php foreach ($notes as $n): ?>
                <li>
                    <a href="<?= BASE_URL ?>/?route=workspace&path=<?= urlencode($n['path']) ?>">
                        <?= Security::e($n['title']) ?>
                    </a>
                    <span class="meta"><?= Security::e($n['folder']) ?> · <?= Security::e($n['modified_at']) ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($notes)): ?><li class="text-muted">No notes with this tag.</li><?php endif; ?>
        </ul>
    </section>
</div>
