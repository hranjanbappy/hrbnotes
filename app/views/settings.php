<?php /** Settings view. */ ?>
<div class="container-fluid py-4" style="max-width: 1200px;">
    <h2 class="mb-4">Settings</h2>

    <div class="row g-4">
        <!-- Left Column: App Settings & Password Changes -->
        <div class="col-lg-6">
            <div class="panel">
                <h5 class="panel-title">General Settings</h5>
                <form action="<?= BASE_URL ?>/?route=settings.save_app_name" method="post">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label text-muted small">Application Name</label>
                        <input name="app_name" class="form-control" value="<?= Security::e(Settings::appName()) ?>" 
                               <?= Auth::isAdmin() ? '' : 'disabled' ?> required>
                    </div>
                    <?php if (Auth::isAdmin()): ?>
                        <button class="btn btn-primary btn-sm">Save App Name</button>
                    <?php endif; ?>
                </form>
            </div>

            <div class="panel mt-4">
                <h5 class="panel-title">Change Password</h5>
                <form action="<?= BASE_URL ?>/?route=settings.change_password" method="post">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label text-muted small">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small">New Password</label>
                        <input type="password" name="new_password" class="form-control" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                    </div>
                    <button class="btn btn-primary btn-sm">Change Password</button>
                </form>
            </div>
        </div>

        <!-- Right Column: Admin-only User & Access Management -->
        <?php if (Auth::isAdmin()): ?>
            <div class="col-lg-6">
                <div class="panel">
                    <h5 class="panel-title">Create User / Admin</h5>
                    <form action="<?= BASE_URL ?>/?route=settings.create_user" method="post">
                        <?= Csrf::field() ?>
                        <div class="mb-3">
                            <label class="form-label text-muted small">Username</label>
                            <input name="username" class="form-control" required minlength="3">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small">Password</label>
                            <input type="password" name="password" class="form-control" required minlength="8">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small">Role</label>
                            <select name="role" class="form-select" id="roleSelect">
                                <option value="user">User (Restricted Access)</option>
                                <option value="admin">Admin (Full Access)</option>
                            </select>
                        </div>
                        <div class="mb-3" id="vaultAccessSection">
                            <label class="form-label text-muted small d-block">Vault Folders Access</label>
                            <?php if (empty($allVaults)): ?>
                                <span class="text-muted small">No vault folders exist. Create some folders in your notes first!</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-2 mt-1">
                                    <?php foreach ($allVaults as $v): ?>
                                        <div class="form-check form-check-inline bg-body-tertiary px-3 py-1 rounded border">
                                            <input class="form-check-input" type="checkbox" name="vaults[]" value="<?= Security::e($v) ?>" id="create_vault_<?= Security::e($v) ?>">
                                            <label class="form-check-label small" for="create_vault_<?= Security::e($v) ?>">
                                                <?= Security::e($v) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-success btn-sm">Create User</button>
                    </form>
                </div>

                <div class="panel mt-4">
                    <h5 class="panel-title">User Management</h5>
                    <div class="table-responsive">
                        <table class="table align-middle text-start">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Vault Access / Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><strong><?= Security::e($u['username']) ?></strong></td>
                                        <td>
                                            <span class="badge <?= $u['role'] === 'admin' ? 'bg-primary' : 'bg-secondary' ?>">
                                                <?= Security::e($u['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($u['role'] === 'admin'): ?>
                                                <span class="text-muted small">Full access (Admin)</span>
                                            <?php else: ?>
                                                <form action="<?= BASE_URL ?>/?route=settings.update_user_vaults" method="post" class="d-inline">
                                                    <?= Csrf::field() ?>
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                    <div class="d-flex flex-column gap-1 mb-2">
                                                        <?php foreach ($allVaults as $v): ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="vaults[]" value="<?= Security::e($v) ?>" 
                                                                       id="user_<?= (int)$u['id'] ?>_<?= Security::e($v) ?>"
                                                                       <?= in_array($v, $u['vaults'], true) ? 'checked' : '' ?>>
                                                                <label class="form-check-label small" for="user_<?= (int)$u['id'] ?>_<?= Security::e($v) ?>">
                                                                    <?= Security::e($v) ?>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <button class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.75rem;">Save Access</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($u['id'] !== Auth::user()['id']): ?>
                                                <form action="<?= BASE_URL ?>/?route=settings.delete_user" method="post" class="d-inline float-end"
                                                      onsubmit="return confirm('Are you sure you want to delete user &quot;<?= Security::e($u['username']) ?>&quot;?');">
                                                    <?= Csrf::field() ?>
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                    <button class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.75rem;">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('roleSelect');
    const vaultAccessSection = document.getElementById('vaultAccessSection');
    if (roleSelect && vaultAccessSection) {
        roleSelect.addEventListener('change', function() {
            if (roleSelect.value === 'admin') {
                vaultAccessSection.style.display = 'none';
            } else {
                vaultAccessSection.style.display = 'block';
            }
        });
    }
});
</script>
