<?php
/**
 * UploadController - handle image / PDF / ZIP uploads into /uploads.
 *
 * Validates extension + size, generates a safe random filename, and returns a
 * Markdown-ready URL the editor can insert.
 */

declare(strict_types=1);

class UploadController extends Controller
{
    public function upload(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No file or upload error.'], 400);
        }

        $file = $_FILES['file'];
        if ($file['size'] > UPLOAD_MAX_BYTES) {
            $this->json(['error' => 'File too large.'], 413);
        }

        $origName = $file['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
            $this->json(['error' => 'File type not allowed.'], 415);
        }

        if (!is_dir(UPLOAD_PATH)) {
            @mkdir(UPLOAD_PATH, 0775, true);
        }

        $safeBase = Security::slug(pathinfo($origName, PATHINFO_FILENAME)) ?: 'file';
        $name = $safeBase . '-' . substr(Security::token(6), 0, 8) . '.' . $ext;
        $dest = UPLOAD_PATH . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->json(['error' => 'Failed to store file.'], 500);
        }

        $url = $this->url('media', ['path' => $name]);
        $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true);
        $markdown = $isImage ? "![{$safeBase}]({$url})" : "[{$origName}]({$url})";

        $this->json([
            'status'   => 'ok',
            'name'     => $name,
            'url'      => $url,
            'markdown' => $markdown,
        ]);
    }

    /** Serve an uploaded file (images/pdf) - confined to /uploads. */
    public function media(): void
    {
        Auth::require();
        $path = (string) ($_GET['path'] ?? '');
        $abs  = Security::safeUploadPath($path, true);
        if ($abs === null || !is_file($abs)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
            'zip' => 'application/zip', default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=3600');
        readfile($abs);
        exit;
    }

    /** POST: delete all files and subdirectories under the uploads folder, except the root .htaccess */
    public function clear(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        try {
            $uploadsReal = realpath(UPLOAD_PATH);
            if ($uploadsReal === false) {
                throw new Exception('Uploads path does not exist.');
            }
            $this->cleanDir($uploadsReal);
            $this->json(['status' => 'ok']);
        } catch (Throwable $e) {
            $this->json(['error' => 'Failed to clear uploads: ' . $e->getMessage()], 500);
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
}
