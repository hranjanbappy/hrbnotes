<?php /** Tag index. */ ?>
<div class="app-shell">
    <aside class="sidebar">
        <?php require APP_PATH . '/views/partials/_tree.php'; ?>
    </aside>
    <section class="content-area p-3 p-lg-4">
        <h2 class="mb-4">Tags</h2>
        <div class="tag-cloud tag-cloud-lg">
            <?php foreach ($tags as $t): ?>
                <a class="tag-chip" href="<?= BASE_URL ?>/?route=tag&tag=<?= urlencode($t['tag_name']) ?>">
                    #<?= Security::e($t['tag_name']) ?> <span class="cnt"><?= (int)$t['cnt'] ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (empty($tags)): ?><p class="text-muted">No tags found. Add #tags to your notes and rescan.</p><?php endif; ?>
        </div>
    </section>
</div>
