# Deployment Guide — cPanel / Shared Hosting

This app is designed for shared hosting. No terminal, SSH, Docker, Node.js,
Redis or Composer is required — only **PHP 8.1+** with the **pdo_sqlite** and
**mbstring** extensions (both standard on virtually every host).

There are two layouts. **Option A is strongly recommended.**

---

## Option A — Document root pointed at `/public` (recommended)

This keeps `app/`, `config/`, `storage/` and `vault/` outside the web root.

### 1. Upload the files
- In cPanel → **File Manager**, upload the whole project (or a ZIP and
  *Extract*) to a folder **above** `public_html`, e.g. `/home/USER/khub`.

### 2. Point the domain at `/public`
- cPanel → **Domains** (or *Addon/Subdomains*) → set the **Document Root** of
  your domain/subdomain to `/home/USER/khub/public`.
- If your host won't let you change the root for the main domain, use a
  subdomain like `notes.yourdomain.com` and set its root to `.../khub/public`.

### 3. Set the PHP version
- cPanel → **Select PHP Version** → choose **8.1, 8.2 or 8.3**.
- Ensure **pdo_sqlite** and **mbstring** are enabled (tick the extensions).

### 4. Permissions
- Make these directories writable by PHP (cPanel File Manager → *Permissions*):
  - `storage/` → `0755` (or `0775`)
  - `vault/`   → `0755`
  - `uploads/` → `0755`
- On most cPanel hosts the default `0755` already works because PHP runs as
  your user.

### 5. Run the installer
- Visit `https://notes.yourdomain.com/install.php`.
- Confirm all requirement checks are green.
- Create your admin username + password → **Install**.

### 6. Lock it down
- **Delete `public/install.php`** (File Manager → right-click → Delete).
- Confirm the site loads at `https://notes.yourdomain.com/`.

---

## Option B — Everything inside `public_html`

Use this only if you cannot change the document root.

### 1. Upload
- Upload the project **into** `public_html` so you have
  `public_html/.htaccess`, `public_html/public/`, `public_html/app/`, etc.

### 2. How it works
- The root `.htaccess` (included) forwards all requests to
  `public_html/public/index.php` and **blocks** direct access to
  `/app`, `/config` and `/storage`. The `storage/`, `config/`, `app/` and
  `vault/` folders also carry their own `Require all denied` `.htaccess`.

### 3. PHP version + permissions
- Same as Option A steps 3–4.

### 4. Install
- Visit `https://yourdomain.com/install.php` (the root `.htaccess` maps it to
  `public/install.php`).
- Complete setup, then **delete `public/install.php`**.

> Option B leaves the source directories on disk under the web root. The
> `.htaccess` rules protect them, but Option A is safer because the sensitive
> folders are physically outside the web root.

---

## Uploading your Obsidian vault

1. Zip your Obsidian vault on your PC.
2. cPanel File Manager → open the app's `vault/` folder → **Upload** the ZIP →
   **Extract**.
3. Log in to the app → **Rescan Vault**.

To sync later: re-upload changed `.md` files and click **Rescan Vault** again.
You can also edit notes directly in the web app — changes are written back to
the original `.md` files.

---

## Backups

- Your notes: download the `/vault` folder.
- Search index / metadata: `/storage/database.sqlite` (regenerable any time via
  **Rescan Vault**, so the vault is the only thing you must back up).

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Blank page / 500 error | Temporarily set `APP_DEBUG = true` in `config/config.php` to see the message. Revert afterwards. |
| "PDO SQLite extension" fails check | Enable `pdo_sqlite` in **Select PHP Version → Extensions**. |
| Cannot write database | Set `storage/` permission to `0775`; check the folder is owned by your cPanel user. |
| CSS/JS missing | Confirm the document root is `/public` (Option A) or that the root `.htaccess` is present (Option B). |
| Styles load but icons/editor don't | The CDN may be blocked; self-host the libraries in `/public/assets/vendor` and update `app/views/layout.php`. |
| Redirected to install repeatedly | The database wasn't created — re-check `storage/` write permission. |

---

## Updating the app

1. Back up `/vault` and `/storage/database.sqlite`.
2. Overwrite the `/app`, `/public`, `/config` folders with the new
   version (leave `/vault`, `/uploads`, `/storage` untouched).
3. Click **Rescan Vault** once after updating.
