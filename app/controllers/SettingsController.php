<?php
/**
 * SettingsController - user management, password updates, app name config.
 */

declare(strict_types=1);

class SettingsController extends Controller
{
    /** Render settings panel. */
    public function settings(): void
    {
        Auth::require();

        // Gather all top-level folders in the vault.
        $allVaults = [];
        if (is_dir(VAULT_PATH)) {
            foreach (new DirectoryIterator(VAULT_PATH) as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                    $name = $fileinfo->getFilename();
                    if ($name !== '' && $name[0] !== '.') {
                        $allVaults[] = $name;
                    }
                }
            }
        }
        sort($allVaults);

        // Gather all users and their allowed vaults if current user is admin.
        $users = [];
        if (Auth::isAdmin()) {
            $users = Database::all('SELECT id, username, role, created_at FROM users ORDER BY username');
            foreach ($users as &$u) {
                $rows = Database::all('SELECT vault_path FROM user_permissions WHERE user_id = ?', [$u['id']]);
                $u['vaults'] = array_column($rows, 'vault_path');
            }
        }

        $this->view('settings', [
            'title'     => 'Settings',
            'allVaults' => $allVaults,
            'users'     => $users,
        ]);
    }

    /** POST: Save a new application name. Admins only. */
    public function saveAppName(): void
    {
        Auth::require();
        if (!Auth::isAdmin()) {
            Session::flash('Unauthorized.', 'danger');
            $this->redirect('settings');
        }
        Csrf::verifyRequest();

        $appName = trim($_POST['app_name'] ?? '');
        if ($appName === '') {
            Session::flash('App name cannot be empty.', 'danger');
            $this->redirect('settings');
        }

        Settings::set('app_name', $appName);
        Session::flash('App name updated successfully.', 'success');
        $this->redirect('settings');
    }

    /** POST: Change current logged in user's password. */
    public function changePassword(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        $currentPw = (string) ($_POST['current_password'] ?? '');
        $newPw     = (string) ($_POST['new_password'] ?? '');
        $confirmPw = (string) ($_POST['confirm_password'] ?? '');

        $userId = Auth::user()['id'];
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);

        if (!password_verify($currentPw, $user['password_hash'])) {
            Session::flash('Current password incorrect.', 'danger');
            $this->redirect('settings');
        }

        if (strlen($newPw) < 8) {
            Session::flash('New password must be at least 8 characters.', 'danger');
            $this->redirect('settings');
        }

        if ($newPw !== $confirmPw) {
            Session::flash('New passwords do not match.', 'danger');
            $this->redirect('settings');
        }

        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $userId]);

        Session::flash('Password changed successfully.', 'success');
        $this->redirect('settings');
    }

    /** POST: Create a new user or administrator. Admins only. */
    public function createUser(): void
    {
        Auth::require();
        if (!Auth::isAdmin()) {
            Session::flash('Unauthorized.', 'danger');
            $this->redirect('settings');
        }
        Csrf::verifyRequest();

        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $role     = trim($_POST['role'] ?? 'user');
        $vaults   = $_POST['vaults'] ?? [];

        if ($username === '' || strlen($username) < 3) {
            Session::flash('Username must be at least 3 characters.', 'danger');
            $this->redirect('settings');
        }

        if (strlen($password) < 8) {
            Session::flash('Password must be at least 8 characters.', 'danger');
            $this->redirect('settings');
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            Session::flash('Invalid role specified.', 'danger');
            $this->redirect('settings');
        }

        $existing = Database::one('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing) {
            Session::flash('Username already exists.', 'danger');
            $this->redirect('settings');
        }

        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            Database::run(
                'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
                [$username, $hash, $role]
            );
            $userId = Database::lastId();

            if ($role === 'user' && is_array($vaults)) {
                foreach ($vaults as $v) {
                    Database::run(
                        'INSERT INTO user_permissions (user_id, vault_path) VALUES (?, ?)',
                        [$userId, $v]
                    );
                }
            }

            Session::flash("User '{$username}' created successfully.", 'success');
        } catch (Throwable $e) {
            Session::flash('Failed to create user: ' . $e->getMessage(), 'danger');
        }

        $this->redirect('settings');
    }

    /** POST: Delete a user. Admins only. Cannot delete own account. */
    public function deleteUser(): void
    {
        Auth::require();
        if (!Auth::isAdmin()) {
            Session::flash('Unauthorized.', 'danger');
            $this->redirect('settings');
        }
        Csrf::verifyRequest();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $currentUserId = Auth::user()['id'];

        if ($userId === $currentUserId) {
            Session::flash('You cannot delete your own account.', 'danger');
            $this->redirect('settings');
        }

        try {
            Database::run('DELETE FROM users WHERE id = ?', [$userId]);
            Session::flash('User deleted successfully.', 'success');
        } catch (Throwable $e) {
            Session::flash('Failed to delete user: ' . $e->getMessage(), 'danger');
        }

        $this->redirect('settings');
    }

    /** POST: Update user-restricted vault permissions. Admins only. */
    public function updateUserVaults(): void
    {
        Auth::require();
        if (!Auth::isAdmin()) {
            Session::flash('Unauthorized.', 'danger');
            $this->redirect('settings');
        }
        Csrf::verifyRequest();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $vaults = $_POST['vaults'] ?? [];

        try {
            Database::run('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);

            if (is_array($vaults)) {
                foreach ($vaults as $v) {
                    Database::run(
                        'INSERT INTO user_permissions (user_id, vault_path) VALUES (?, ?)',
                        [$userId, $v]
                    );
                }
            }
            Session::flash('User access permissions updated successfully.', 'success');
        } catch (Throwable $e) {
            Session::flash('Failed to update access: ' . $e->getMessage(), 'danger');
        }

        $this->redirect('settings');
    }
}
