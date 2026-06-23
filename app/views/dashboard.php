<?php /** Dashboard view. */ ?>
<div class="app-shell">
    <aside class="sidebar">
        <?php require APP_PATH . '/views/partials/_tree.php'; ?>
    </aside>

    <section class="content-area p-3 p-lg-4">
        <h2 class="mb-4">Dashboard</h2>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card"><div class="stat-num"><?= (int)$counts['notes'] ?></div>
                    <div class="stat-label">Notes</div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card"><div class="stat-num"><?= (int)$counts['folders'] ?></div>
                    <div class="stat-label">Folders</div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card"><div class="stat-num"><?= (int)$counts['tags'] ?></div>
                    <div class="stat-label">Tags</div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card stat-action">
                    <button id="btnRescan2" class="btn btn-primary w-100">Rescan Vault</button>
                    <div class="stat-label mt-2" id="rescanStatus">Index from files</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="panel">
                    <h5 class="panel-title">Recently modified</h5>
                    <?php if (empty($recentModified)): ?>
                        <p class="text-muted small">Nothing yet.</p>
                    <?php else: ?>
                        <ul class="list-flush">
                        <?php foreach ($recentModified as $n): ?>
                            <li>
                                <a href="<?= BASE_URL ?>/?route=workspace&path=<?= urlencode($n['path']) ?>">
                                    <?= Security::e($n['title']) ?>
                                </a>
                                <span class="meta"><?= Security::e($n['folder']) ?> · <?= Security::e($n['modified_at']) ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="panel">
                    <h5 class="panel-title">Recently opened</h5>
                    <?php if (empty($recentOpened)): ?>
                        <p class="text-muted small">Open a note to see it here.</p>
                    <?php else: ?>
                        <ul class="list-flush">
                        <?php foreach ($recentOpened as $n): ?>
                            <li>
                                <a href="<?= BASE_URL ?>/?route=workspace&path=<?= urlencode($n['path']) ?>">
                                    <?= Security::e($n['title']) ?>
                                </a>
                                <span class="meta"><?= Security::e($n['opened_at']) ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="panel mt-4">
                    <h5 class="panel-title">Top tags</h5>
                    <div class="tag-cloud">
                        <?php foreach ($topTags as $t): ?>
                            <a class="tag-chip" href="<?= BASE_URL ?>/?route=tag&tag=<?= urlencode($t['tag_name']) ?>">
                                #<?= Security::e($t['tag_name']) ?> <span class="cnt"><?= (int)$t['cnt'] ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($topTags)): ?><span class="text-muted small">No tags.</span><?php endif; ?>
                    </div>
                </div>

                <div class="panel mt-4 border-danger">
                    <h5 class="panel-title text-danger">Danger Zone</h5>
                    <div class="d-flex flex-column gap-3">
                        <div>
                            <button id="btnClearVault" class="btn btn-outline-danger w-100 btn-sm">Delete Entire Vault</button>
                            <div class="small text-muted mt-1" style="font-size: 0.8rem;">Permanently deletes all files/folders in the vault directory and clears search indexes. This cannot be undone.</div>
                        </div>
                        <hr class="my-2 border-secondary opacity-25">
                        <div>
                            <button id="btnClearUploads" class="btn btn-outline-danger w-100 btn-sm">Delete Uploads</button>
                            <div class="small text-muted mt-1" style="font-size: 0.8rem;">Permanently deletes all uploaded images, PDFs, and files. Warning: references in notes will break.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
