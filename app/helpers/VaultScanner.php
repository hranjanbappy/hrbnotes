<?php
/**
 * VaultScanner - walks the vault, parses every note and (re)builds the SQLite
 * metadata + FTS index. The Markdown files themselves are never modified.
 *
 * Run on demand via the "Rescan Vault" action, or after a save/upload.
 */

declare(strict_types=1);

class VaultScanner
{
    /**
     * Full rescan. Returns a small stats array for the UI.
     */
    public static function rescan(): array
    {
        $pdo = Database::pdo();
        $files = self::collectNoteFiles(VAULT_PATH);

        $seenPaths = [];
        $stats = ['scanned' => 0, 'added' => 0, 'updated' => 0, 'removed' => 0];

        $pdo->beginTransaction();
        try {
            foreach ($files as $abs) {
                $rel = self::relPath($abs);
                $seenPaths[] = $rel;
                $result = self::indexFile($abs, $rel);
                $stats['scanned']++;
                $stats[$result]++;            // 'added' or 'updated'
            }

            // Remove notes whose files no longer exist.
            $stats['removed'] = self::pruneMissing($seenPaths);

            // Second pass: resolve wiki-link targets to note ids.
            self::resolveLinks();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * Index a single file (used by rescan and after individual saves).
     * Returns 'added' or 'updated'.
     */
    public static function indexFile(string $abs, string $rel): string
    {
        $content  = (string) file_get_contents($abs);
        $filename = pathinfo($rel, PATHINFO_FILENAME);
        $parsed   = MarkdownParser::parse($content, $filename);

        $folder   = trim(str_replace('\\', '/', dirname($rel)), '.');
        $folder   = $folder === '' ? '' : $folder;
        $slug     = Security::slug($parsed['title'] ?: $filename);
        $tagsStr  = implode(' ', $parsed['tags']);
        $mtime    = date('Y-m-d H:i:s', (int) filemtime($abs));
        $size     = (int) filesize($abs);

        $existing = Database::one('SELECT id FROM notes WHERE path = ?', [$rel]);

        if ($existing) {
            $noteId = (int) $existing['id'];
            Database::run(
                'UPDATE notes SET title=?, slug=?, folder=?, tags=?, size_bytes=?, modified_at=? WHERE id=?',
                [$parsed['title'], $slug, $folder, $tagsStr, $size, $mtime, $noteId]
            );
            $verb = 'updated';
        } else {
            Database::run(
                'INSERT INTO notes (title, path, slug, folder, tags, size_bytes, created_at, modified_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$parsed['title'], $rel, $slug, $folder, $tagsStr, $size, $mtime, $mtime]
            );
            $noteId = Database::lastId();
            $verb = 'added';
        }

        self::syncTags($noteId, $parsed['tags']);
        self::syncLinks($noteId, $parsed['links']);
        self::syncSearch($noteId, $parsed['title'], $parsed['plain'], $tagsStr);

        return $verb;
    }

    // --- internals ----------------------------------------------------------

    /** Recursively gather all Markdown files under $dir. */
    private static function collectNoteFiles(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, NOTE_EXTENSIONS, true)) {
                $out[] = $file->getPathname();
            }
        }
        return $out;
    }

    private static function relPath(string $abs): string
    {
        $base = realpath(VAULT_PATH);
        $abs  = realpath($abs) ?: $abs;
        $rel  = ltrim(str_replace('\\', '/', substr($abs, strlen((string) $base))), '/');
        return $rel;
    }

    private static function pruneMissing(array $seenPaths): int
    {
        $all = Database::all('SELECT id, path FROM notes');
        $removed = 0;
        foreach ($all as $row) {
            if (!in_array($row['path'], $seenPaths, true)) {
                Database::run('DELETE FROM notes WHERE id = ?', [$row['id']]);
                Database::run('DELETE FROM search_index WHERE note_id = ?', [$row['id']]);
                $removed++;
            }
        }
        return $removed;
    }

    private static function syncTags(int $noteId, array $tags): void
    {
        Database::run('DELETE FROM note_tags WHERE note_id = ?', [$noteId]);
        foreach ($tags as $name) {
            Database::run('INSERT OR IGNORE INTO tags (tag_name) VALUES (?)', [$name]);
            $tagId = Database::scalar('SELECT id FROM tags WHERE tag_name = ?', [$name]);
            Database::run('INSERT OR IGNORE INTO note_tags (note_id, tag_id) VALUES (?, ?)', [$noteId, $tagId]);
        }
    }

    private static function syncLinks(int $noteId, array $links): void
    {
        Database::run('DELETE FROM links WHERE source_note = ?', [$noteId]);
        foreach ($links as $target) {
            Database::run(
                'INSERT INTO links (source_note, target_note, target_title) VALUES (?, NULL, ?)',
                [$noteId, $target]
            );
        }
    }

    private static function syncSearch(int $noteId, string $title, string $plain, string $tags): void
    {
        Database::run('DELETE FROM search_index WHERE note_id = ?', [$noteId]);
        Database::run(
            'INSERT INTO search_index (title, content, tags, note_id) VALUES (?,?,?,?)',
            [$title, $plain, $tags, $noteId]
        );
    }

    /**
     * Resolve target_title -> target_note id by matching note title or slug or
     * basename. Done after all notes are indexed so forward references work.
     */
    private static function resolveLinks(): void
    {
        $links = Database::all(
            'SELECT l.id, l.source_note, l.target_title, n.path AS source_path
             FROM links l
             JOIN notes n ON n.id = l.source_note'
        );
        foreach ($links as $link) {
            $title = $link['target_title'];
            $slug  = Security::slug($title);
            
            // Extract vault name from source note's path
            $parts = explode('/', $link['source_path']);
            $vault = $parts[0] ?? '';
            $vaultLike = $vault !== '' ? $vault . '/%' : '%';
            
            $targetPath1 = $vault !== '' ? $vault . '/' . $title . '.md' : $title . '.md';
            $targetPath2 = $vault !== '' ? $vault . '/' . $title . '.markdown' : $title . '.markdown';
            
            $note = Database::one(
                'SELECT id FROM notes
                 WHERE (path LIKE ?)
                   AND (title = ? COLLATE NOCASE
                        OR slug = ?
                        OR path = ? OR path = ?)
                 LIMIT 1',
                [$vaultLike, $title, $slug, $targetPath1, $targetPath2]
            );
            $targetId = $note['id'] ?? null;
            Database::run('UPDATE links SET target_note = ? WHERE id = ?', [$targetId, $link['id']]);
        }
    }
}
