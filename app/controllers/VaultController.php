<?php
/**
 * VaultController - rescan action and serving vault-embedded images safely.
 */

declare(strict_types=1);

class VaultController extends Controller
{
    /** POST: rebuild the metadata + search index from the vault files. */
    public function rescan(): void
    {
        Auth::require();
        Csrf::verifyRequest();
        try {
            $stats = VaultScanner::rescan();
            $this->json(['status' => 'ok', 'stats' => $stats]);
        } catch (Throwable $e) {
            $this->json(['error' => 'Rescan failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Serve an image referenced inside a note. Path is confined to the vault.
     * Used by the renderer for ![](relative/path.png) and embedded images.
     */
    public function media(): void
    {
        Auth::require();
        $path = (string) ($_GET['path'] ?? '');
        $abs  = Security::safeVaultPath($path, true);
        if ($abs === null || !is_file($abs)) {
            // Try the uploads directory as a fallback.
            $abs = Security::safeUploadPath($path, true);
        }
        if ($abs === null || !is_file($abs)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $mime = self::mimeFor($abs);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=3600');
        readfile($abs);
        exit;
    }

    /**
     * POST: import an Obsidian vault ZIP — extracts into VAULT_PATH then rescans.
     * Only .md, .markdown, and common image/PDF files are extracted.
     */
    public function vaultImport(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        if (empty($_FILES['vault_zip']) || $_FILES['vault_zip']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No file uploaded or upload error.'], 400);
        }

        $file = $_FILES['vault_zip'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $this->json(['error' => 'Only ZIP files are accepted.'], 415);
        }
        if ($file['size'] > 200 * 1024 * 1024) {
            $this->json(['error' => 'ZIP exceeds 200 MB limit.'], 413);
        }
        if (!class_exists('ZipArchive')) {
            $this->json(['error' => 'ZipArchive PHP extension is not available on this server.'], 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            $this->json(['error' => 'Could not open ZIP file — it may be corrupt.'], 400);
        }

        // Allowed extensions to extract (never execute server-side code from a ZIP).
        $allowed = ['md', 'markdown', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'css', 'json'];

        $vaultReal  = realpath(VAULT_PATH);
        $sep        = DIRECTORY_SEPARATOR;
        $extracted  = 0;
        $skipped    = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) { $skipped++; continue; }

            // Reject traversal / null bytes.
            if (str_contains($name, '..') || str_contains($name, "\0")) { $skipped++; continue; }

            // Skip macOS resource-fork dirs and hidden dot-files.
            $parts = explode('/', $name);
            $bad   = false;
            foreach ($parts as $p) {
                if ($p === '' || str_starts_with($p, '__MACOSX') || ($p !== '' && $p[0] === '.')) {
                    $bad = true; break;
                }
            }
            if ($bad) { $skipped++; continue; }

            // Directory entries — create and move on.
            if (str_ends_with($name, '/')) {
                $dir = $vaultReal . $sep . str_replace('/', $sep, rtrim($name, '/'));
                @mkdir($dir, 0775, true);
                continue;
            }

            // Extension filter.
            $fileExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($fileExt, $allowed, true)) { $skipped++; continue; }

            // Build destination, confined to vault.
            $dest = $vaultReal . $sep . str_replace('/', $sep, $name);

            // Prefix check (no symlink tricks — all path components already verified above).
            if (!str_starts_with($dest . $sep, $vaultReal . $sep) && $dest !== $vaultReal) {
                $skipped++; continue;
            }

            $dir = dirname($dest);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $data = $zip->getFromIndex($i);
            if ($data === false) { $skipped++; continue; }

            if (file_put_contents($dest, $data) !== false) {
                $extracted++;
            } else {
                $skipped++;
            }
        }
        $zip->close();

        $stats = VaultScanner::rescan();
        $this->json([
            'status'    => 'ok',
            'extracted' => $extracted,
            'skipped'   => $skipped,
            'stats'     => $stats,
        ]);
    }

    /**
     * GET: stream the entire vault as a ZIP download.
     */
    public function vaultExport(): void
    {
        Auth::require();

        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo 'ZipArchive PHP extension is not available on this server.';
            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'pkh_export_');
        if ($tmpFile === false) {
            http_response_code(500);
            echo 'Could not create temporary file.';
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Could not create ZIP archive.';
            return;
        }

        $vaultReal = realpath(VAULT_PATH);
        $iter      = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vaultReal, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getRealPath();
            // Confinement check.
            if (!str_starts_with($abs, $vaultReal . DIRECTORY_SEPARATOR)
                && $abs !== $vaultReal) {
                continue;
            }
            $rel = substr($abs, strlen($vaultReal) + 1);
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            $zip->addFile($abs, $rel);
        }

        $zip->close();

        $filename = 'vault-' . date('Y-m-d') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: no-cache, no-store');
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    /** POST: delete all files and subdirectories under the vault, except the root .htaccess */
    public function clear(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        try {
            $vaultReal = realpath(VAULT_PATH);
            if ($vaultReal === false) {
                throw new Exception('Vault path does not exist.');
            }
            $this->cleanDir($vaultReal);
            
            // Re-scan vault (which should now be empty) to clean up the DB tables
            $stats = VaultScanner::rescan();

            $this->json(['status' => 'ok', 'stats' => $stats]);
        } catch (Throwable $e) {
            $this->json(['error' => 'Failed to clear vault: ' . $e->getMessage()], 500);
        }
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = $fileinfo->getRealPath();
            // Preserve the root .htaccess
            $rel = ltrim(substr($todo, strlen($dir)), DIRECTORY_SEPARATOR);
            if ($rel === '.htaccess') {
                continue;
            }
            if ($fileinfo->isDir()) {
                @rmdir($todo);
            } else {
                @unlink($todo);
            }
        }
    }

    /** POST JSON: delete a specific folder and its contents recursively. Only admins. */
    public function deleteFolder(): void
    {
        Auth::require();
        if (!Auth::isAdmin()) {
            $this->json(['error' => 'Unauthorized.'], 403);
        }
        Csrf::verifyRequest();

        $in = $this->jsonInput();
        $folder = trim((string) ($in['folder'] ?? ''));
        if ($folder === '') {
            $this->json(['error' => 'Missing folder.'], 400);
        }

        // Confine folder path to VAULT_PATH
        $abs = Security::safeVaultPath($folder, true);
        if ($abs === null || !is_dir($abs)) {
            $this->json(['error' => 'Folder not found or invalid.'], 404);
        }

        try {
            $this->cleanDir($abs);
            @rmdir($abs); // Remove the directory itself

            // Re-scan vault to clean up database metadata
            $stats = VaultScanner::rescan();

            $this->json(['status' => 'ok', 'stats' => $stats]);
        } catch (Throwable $e) {
            $this->json(['error' => 'Failed to delete folder: ' . $e->getMessage()], 500);
        }
    }

    private static function mimeFor(string $abs): string
    {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        return match ($ext) {
            'png'           => 'image/png',
            'jpg', 'jpeg'   => 'image/jpeg',
            'gif'           => 'image/gif',
            'webp'          => 'image/webp',
            'svg'           => 'image/svg+xml',
            'pdf'           => 'application/pdf',
            default         => 'application/octet-stream',
        };
    }
}
