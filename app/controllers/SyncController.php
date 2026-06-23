<?php
/**
 * SyncController - Cloud Sync API for local Obsidian vault synchronization.
 */

declare(strict_types=1);

class SyncController extends Controller
{
    /**
     * Stateless authentication helper. Verifies username/password and role.
     */
    private function verifyAdmin(array $input): void
    {
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->json(['error' => 'Missing credentials.'], 401);
        }

        $user = Database::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->json(['error' => 'Invalid username or password.'], 401);
        }

        if ($user['role'] !== 'admin') {
            $this->json(['error' => 'Only administrators can synchronize vaults.'], 403);
        }
    }

    /**
     * POST: Handshake and initialize sync (three-way delta comparison).
     * Returns files to upload, files to download, and files to delete locally.
     */
    public function init(): void
    {
        $in = $this->jsonInput();
        $this->verifyAdmin($in);

        $vault = trim((string) ($in['vault'] ?? ''));
        if ($vault === '' || str_contains($vault, '..') || str_contains($vault, "\0")) {
            $this->json(['error' => 'Invalid vault name.'], 400);
        }

        $targetDir = VAULT_PATH . '/' . $vault;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $abs = realpath($targetDir);
        $vaultReal = realpath(VAULT_PATH);
        if ($abs === false || !str_starts_with($abs . DIRECTORY_SEPARATOR, $vaultReal . DIRECTORY_SEPARATOR)) {
            $this->json(['error' => 'Invalid target vault path.'], 400);
        }

        $clientFiles = $in['files'] ?? []; // current local files on client
        if (!is_array($clientFiles)) {
            $this->json(['error' => 'Invalid files map.'], 400);
        }

        $syncedFiles = $in['synced_files'] ?? []; // files from the last sync cycle

        // Scan current target directory on server
        $serverFiles = [];
        if (is_dir($abs)) {
            $dirIter = new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS);
            $iter = new RecursiveIteratorIterator($dirIter);

            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $filePath = $file->getRealPath();
                    $rel = ltrim(substr($filePath, strlen($abs)), DIRECTORY_SEPARATOR);
                    $rel = str_replace('\\', '/', $rel);

                    // Skip hidden files/directories and root .htaccess
                    if (str_starts_with($rel, '.') || str_contains($rel, '/.') || $rel === '.htaccess') {
                        continue;
                    }
                    $serverFiles[$rel] = $file->getMTime();
                }
            }
        }

        $toUpload = [];
        $toDownload = [];
        $conflicts = [];
        $toDeleteOnServer = [];
        $toDeleteOnClient = [];

        // 1. Check server files
        foreach ($serverFiles as $rel => $serverMtime) {
            if (!isset($clientFiles[$rel])) {
                // File exists on server but not on client
                if (isset($syncedFiles[$rel])) {
                    // It was synced before, but now the client doesn't have it -> client deleted it locally.
                    $toDeleteOnServer[] = $rel;
                } else {
                    // It was created on the server (online)
                    $toDownload[] = $rel;
                }
            } else {
                // File exists on both server and client
                $clientMtime = (int) $clientFiles[$rel];
                $syncedMtime = isset($syncedFiles[$rel]) ? (int) $syncedFiles[$rel] : null;

                if ($syncedMtime !== null) {
                    $clientChanged = ($clientMtime !== $syncedMtime);
                    $serverChanged = ($serverMtime !== $syncedMtime);

                    if ($clientChanged && !$serverChanged) {
                        $toUpload[] = $rel;
                    } elseif ($serverChanged && !$clientChanged) {
                        $toDownload[] = $rel;
                    } elseif ($clientChanged && $serverChanged) {
                        // Conflict: both modified since last sync.
                        $conflicts[] = $rel;
                    }
                } else {
                    // Not in synced_files. Fall back to comparing mtimes directly.
                    if ($clientMtime > $serverMtime) {
                        $toUpload[] = $rel;
                    } elseif ($serverMtime > $clientMtime) {
                        $toDownload[] = $rel;
                    }
                }
            }
        }

        // 2. Check client files to find local modifications/creations or online deletions
        foreach ($clientFiles as $rel => $clientMtime) {
            if (str_contains($rel, '..') || str_contains($rel, "\0")) {
                continue;
            }
            if (!isset($serverFiles[$rel])) {
                // File exists on client but not on server
                if (isset($syncedFiles[$rel])) {
                    // It was synced before, but now the server doesn't have it -> deleted online.
                    $toDeleteOnClient[] = $rel;
                } else {
                    // Not in synced_files and not on server -> created locally (e.g. copy-pasted or new file)
                    $toUpload[] = $rel;
                }
            }
        }

        // 3. Perform server deletions immediately
        $deletedOnServer = [];
        foreach ($toDeleteOnServer as $rel) {
            $fileAbs = $abs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($fileAbs)) {
                @unlink($fileAbs);
                $deletedOnServer[] = $rel;
            }
        }

        // 4. Prune empty directories
        $this->pruneEmptyDirs($abs);

        $this->json([
            'status' => 'ok',
            'deleted_on_server' => $deletedOnServer,
            'to_upload' => $toUpload,
            'to_download' => $toDownload,
            'conflicts' => $conflicts,
            'to_delete_on_client' => $toDeleteOnClient
        ]);
    }

    /**
     * POST: Download a single file from the server.
     */
    public function download(): void
    {
        $in = $this->jsonInput();
        $this->verifyAdmin($in);

        $vault = trim((string) ($in['vault'] ?? ''));
        $path = trim((string) ($in['path'] ?? ''));

        if ($vault === '' || str_contains($vault, '..') || str_contains($vault, "\0")) {
            $this->json(['error' => 'Invalid vault name.'], 400);
        }
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            $this->json(['error' => 'Invalid file path.'], 400);
        }

        $targetDir = VAULT_PATH . '/' . $vault;
        $abs = realpath($targetDir);
        $vaultReal = realpath(VAULT_PATH);
        if ($abs === false || !str_starts_with($abs . DIRECTORY_SEPARATOR, $vaultReal . DIRECTORY_SEPARATOR)) {
            $this->json(['error' => 'Invalid vault path.'], 400);
        }

        $fileAbs = $abs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!str_starts_with($fileAbs, $abs . DIRECTORY_SEPARATOR) || !is_file($fileAbs)) {
            $this->json(['error' => 'File not found.'], 404);
        }

        $data = file_get_contents($fileAbs);
        if ($data !== false) {
            $this->json([
                'status' => 'ok',
                'content' => base64_encode($data),
                'mtime' => filemtime($fileAbs)
            ]);
        } else {
            $this->json(['error' => 'Failed to read file on server.'], 500);
        }
    }

    /**
     * POST: Upload a single file.
     */
    public function upload(): void
    {
        $in = $this->jsonInput();
        $this->verifyAdmin($in);

        $vault = trim((string) ($in['vault'] ?? ''));
        $path = trim((string) ($in['path'] ?? ''));
        $contentBase64 = (string) ($in['content'] ?? '');
        $mtime = (int) ($in['mtime'] ?? 0);

        if ($vault === '' || str_contains($vault, '..') || str_contains($vault, "\0")) {
            $this->json(['error' => 'Invalid vault name.'], 400);
        }
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            $this->json(['error' => 'Invalid file path.'], 400);
        }

        $targetDir = VAULT_PATH . '/' . $vault;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }
        $abs = realpath($targetDir);
        $vaultReal = realpath(VAULT_PATH);
        if ($abs === false || !str_starts_with($abs . DIRECTORY_SEPARATOR, $vaultReal . DIRECTORY_SEPARATOR)) {
            $this->json(['error' => 'Invalid vault path.'], 400);
        }

        $fileAbs = $abs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!str_starts_with($fileAbs, $abs . DIRECTORY_SEPARATOR)) {
            $this->json(['error' => 'Path traversal detected.'], 400);
        }

        // Ensure intermediate directories exist
        @mkdir(dirname($fileAbs), 0775, true);

        $data = base64_decode($contentBase64);
        if (file_put_contents($fileAbs, $data) !== false) {
            if ($mtime > 0) {
                @touch($fileAbs, $mtime);
            }
            $this->json(['status' => 'ok']);
        } else {
            $this->json(['error' => 'Failed to write file on server.'], 500);
        }
    }

    /**
     * POST: Sync completion. Rebuilds the search index.
     */
    public function done(): void
    {
        $in = $this->jsonInput();
        $this->verifyAdmin($in);

        try {
            $stats = VaultScanner::rescan();
            $this->json(['status' => 'ok', 'stats' => $stats]);
        } catch (Throwable $e) {
            $this->json(['error' => 'Sync database indexing failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Recursively delete empty subdirectories.
     */
    private function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            if ($file->isDir()) {
                $path = $file->getRealPath();
                $files = scandir($path);
                if ($files !== false && count($files) <= 2) {
                    @rmdir($path);
                }
            }
        }
    }
}
