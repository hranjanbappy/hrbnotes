<?php
/**
 * Note model - reads note metadata from SQLite and note content from the
 * vault files. Provides the tree, viewer data, save and search operations.
 */

declare(strict_types=1);

class Note
{
    /** Count of notes / distinct folders for the dashboard. */
    public static function counts(): array
    {
        [$sql, $params] = self::allowedVaultsSql('');
        $notes   = (int) Database::scalar("SELECT COUNT(*) FROM notes WHERE 1=1 {$sql}", $params);
        $folders = (int) Database::scalar(
            "SELECT COUNT(DISTINCT folder) FROM notes WHERE folder <> '' {$sql}",
            $params
        );
        $tagsSql = self::allowedVaultsSql('n');
        $tags    = (int) Database::scalar(
            "SELECT COUNT(DISTINCT nt.tag_id) FROM note_tags nt
             JOIN notes n ON n.id = nt.note_id
             WHERE 1=1 " . $tagsSql[0],
            $tagsSql[1]
        );
        return ['notes' => $notes, 'folders' => $folders, 'tags' => $tags];
    }

    /** Recently modified notes. */
    public static function recentModified(int $limit = 10): array
    {
        [$sql, $params] = self::allowedVaultsSql();
        $params[] = $limit;
        return Database::all(
            "SELECT id, title, path, folder, modified_at
             FROM notes WHERE 1=1 {$sql} ORDER BY modified_at DESC LIMIT ?",
            $params
        );
    }

    /** Recently opened notes. */
    public static function recentOpened(int $limit = 10): array
    {
        [$sql, $params] = self::allowedVaultsSql();
        $params[] = $limit;
        return Database::all(
            "SELECT id, title, path, folder, opened_at
             FROM notes WHERE opened_at IS NOT NULL {$sql}
             ORDER BY opened_at DESC LIMIT ?",
            $params
        );
    }

    public static function findByPath(string $path): ?array
    {
        $note = Database::one('SELECT * FROM notes WHERE path = ?', [$path]);
        if ($note && !self::isPathAllowed($path)) {
            return null;
        }
        return $note;
    }

    public static function findById(int $id): ?array
    {
        $note = Database::one('SELECT * FROM notes WHERE id = ?', [$id]);
        if ($note && !self::isPathAllowed($note['path'])) {
            return null;
        }
        return $note;
    }

    /**
     * Build a nested folder tree of all notes for the sidebar.
     * Returns an array of nodes: ['name','type'=>'folder|note','path','children'].
     */
    public static function tree(): array
    {
        [$sql, $params] = self::allowedVaultsSql();
        $rows = Database::all("SELECT id, title, path, folder FROM notes WHERE 1=1 {$sql} ORDER BY path", $params);
        $root = ['name' => '', 'type' => 'folder', 'path' => '', 'children' => []];

        foreach ($rows as $row) {
            $parts = explode('/', $row['path']);
            $node = &$root;
            $acc = [];
            // Folder segments (all but last part).
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $acc[] = $parts[$i];
                $folderPath = implode('/', $acc);
                $found = null;
                foreach ($node['children'] as &$child) {
                    if ($child['type'] === 'folder' && $child['path'] === $folderPath) {
                        $found = &$child;
                        break;
                    }
                    unset($child);
                }
                if ($found === null) {
                    $node['children'][] = [
                        'name' => $parts[$i], 'type' => 'folder',
                        'path' => $folderPath, 'children' => [],
                    ];
                    $found = &$node['children'][count($node['children']) - 1];
                }
                $node = &$found;
                unset($found);
            }
            // Leaf note.
            $node['children'][] = [
                'name'  => $parts[count($parts) - 1],
                'type'  => 'note',
                'title' => $row['title'],
                'path'  => $row['path'],
                'id'    => (int) $row['id'],
            ];
            unset($node);
        }

        self::sortTree($root);
        return $root['children'];
    }

    private static function sortTree(array &$node): void
    {
        if (empty($node['children'])) {
            return;
        }
        usort($node['children'], function ($a, $b) {
            // Folders first, then alphabetical.
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        foreach ($node['children'] as &$child) {
            if ($child['type'] === 'folder') {
                self::sortTree($child);
            }
        }
    }

    /** Read raw Markdown content for a note (path-safe). */
    public static function readContent(string $path): ?string
    {
        if (!self::isPathAllowed($path)) {
            return null;
        }
        $abs = Security::safeVaultPath($path, true);
        if ($abs === null) {
            return null;
        }
        $data = file_get_contents($abs);
        return $data === false ? null : $data;
    }

    /**
     * Save content back to the vault file with optimistic file locking.
     * $expectedMtime is the modified timestamp the client last saw; if the file
     * changed underneath, the save is rejected (returns 'conflict').
     *
     * Returns: 'ok' | 'conflict' | 'error'.
     */
    public static function save(string $path, string $content, ?int $expectedMtime = null): string
    {
        if (!self::isPathAllowed($path)) {
            return 'error';
        }
        $abs = Security::safeVaultPath($path, true);
        if ($abs === null) {
            return 'error';
        }

        clearstatcache(true, $abs);
        if ($expectedMtime !== null && (int) filemtime($abs) !== $expectedMtime) {
            return 'conflict';
        }

        // Lock + write atomically.
        $fp = fopen($abs, 'c+');
        if ($fp === false) {
            return 'error';
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return 'error';
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $content);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        // Re-index just this note.
        clearstatcache(true, $abs);
        VaultScanner::indexFile($abs, $path);
        // Links from other notes may now resolve; cheap targeted refresh.
        self::touchLinkResolution();

        return 'ok';
    }

    /** Create a new empty (or templated) note file. Returns rel path or null. */
    public static function create(string $relPath, string $content = ''): ?string
    {
        if (!self::isPathAllowed($relPath)) {
            return null;
        }
        // Ensure .md extension.
        if (!preg_match('/\.(md|markdown)$/i', $relPath)) {
            $relPath .= '.md';
        }
        $abs = Security::safeVaultPath($relPath, false);
        if ($abs === null || file_exists($abs)) {
            return null;
        }
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }
        if (file_put_contents($abs, $content) === false) {
            return null;
        }
        VaultScanner::indexFile($abs, $relPath);
        return $relPath;
    }

    /** Rename a note file on disk and update its path reference in database. */
    public static function rename(string $oldPath, string $newPath): bool
    {
        if (!self::isPathAllowed($oldPath) || !self::isPathAllowed($newPath)) {
            return false;
        }

        // Ensure .md or .markdown extension on newPath.
        if (!preg_match('/\.(md|markdown)$/i', $newPath)) {
            $newPath .= '.md';
        }

        $oldAbs = Security::safeVaultPath($oldPath, true);
        $newAbs = Security::safeVaultPath($newPath, false);

        if ($oldAbs === null || $newAbs === null) {
            return false;
        }

        if (strcasecmp($oldPath, $newPath) !== 0 && file_exists($newAbs)) {
            return false;
        }

        if (!is_dir(dirname($newAbs))) {
            @mkdir(dirname($newAbs), 0775, true);
        }

        if (!@rename($oldAbs, $newAbs)) {
            return false;
        }

        // Update database path
        $note = self::findByPath($oldPath);
        if ($note) {
            Database::run('UPDATE notes SET path = ? WHERE id = ?', [$newPath, (int)$note['id']]);
        }

        // Re-index at the new path
        clearstatcache(true, $newAbs);
        VaultScanner::indexFile($newAbs, $newPath);

        // Re-resolve link targets
        self::touchLinkResolution();

        return true;
    }

    public static function markOpened(int $id): void
    {
        Database::run('UPDATE notes SET opened_at = datetime("now") WHERE id = ?', [$id]);
    }

    public static function tagsFor(int $noteId): array
    {
        return array_column(Database::all(
            'SELECT t.tag_name FROM tags t
             JOIN note_tags nt ON nt.tag_id = t.id
             WHERE nt.note_id = ? ORDER BY t.tag_name',
            [$noteId]
        ), 'tag_name');
    }

    /** Notes that link TO this note (backlinks), scoped to same vault. */
    public static function backlinks(int $noteId): array
    {
        $note = self::findById($noteId);
        if (!$note) return [];

        // Extract vault from path (e.g., "vault/sub/note.md" -> "vault").
        $parts = explode('/', $note['path']);
        $vault = $parts[0] ?? '';

        [$sql, $params] = self::allowedVaultsSql('n');
        $query = 'SELECT DISTINCT n.id, n.title, n.path
                  FROM links l JOIN notes n ON n.id = l.source_note
                  WHERE l.target_note = ?' . $sql;
        $allParams = array_merge([$noteId], $params);

        // If vault is non-empty, only include backlinks from same vault.
        if ($vault) {
            $query .= ' AND n.path LIKE ?';
            $allParams[] = $vault . '/%';
        }

        $query .= ' ORDER BY n.title';
        return Database::all($query, $allParams);
    }

    /** Outgoing links from this note (resolved + unresolved), scoped to same vault. */
    public static function outlinks(int $noteId): array
    {
        $note = self::findById($noteId);
        if (!$note) return [];

        // Extract vault from path.
        $parts = explode('/', $note['path']);
        $vault = $parts[0] ?? '';

        $links = Database::all(
            'SELECT l.target_title, l.target_note, n.path
             FROM links l LEFT JOIN notes n ON n.id = l.target_note
             WHERE l.source_note = ? ORDER BY l.target_title',
            [$noteId]
        );

        // Enforce user permission boundary on outgoing links targets
        foreach ($links as &$link) {
            if ($link['target_note'] !== null && !self::isPathAllowed($link['path'])) {
                $link['target_note'] = null;
                $link['path'] = null;
            }
        }

        // Filter to same vault if vault is non-empty.
        if (!$vault) {
            return $links;
        }

        return array_filter($links, function ($link) use ($vault) {
            // Keep unresolved links (no target_note).
            if ($link['target_note'] === null) return true;
            // Check if target is in same vault.
            $targetParts = explode('/', $link['path'] ?? '');
            $targetVault = $targetParts[0] ?? '';
            return $vault === $targetVault;
        });
    }

    /** Re-resolve unresolved links cheaply after a save/create. */
    private static function touchLinkResolution(): void
    {
        $unresolved = Database::all(
            'SELECT l.id, l.target_title, n.path AS source_path 
             FROM links l 
             JOIN notes n ON n.id = l.source_note
             WHERE l.target_note IS NULL'
        );
        foreach ($unresolved as $l) {
            $title = $l['target_title'];
            $slug = Security::slug($title);
            
            // Extract vault name from source note's path
            $parts = explode('/', $l['source_path']);
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
            if ($note) {
                Database::run('UPDATE links SET target_note = ? WHERE id = ?', [$note['id'], $l['id']]);
            }
        }
    }

    /**
     * Check if the user is authorized to read/write a note at the given path.
     */
    public static function isPathAllowed(string $path): bool
    {
        $vaults = Auth::allowedVaults();
        if ($vaults === null) {
            return true;
        }
        $parts = explode('/', $path);
        $top = $parts[0] ?? '';
        return in_array($top, $vaults, true);
    }

    /**
     * Generate path-filtering SQL constraints for the allowed vault paths.
     */
    public static function allowedVaultsSql(string $alias = 'notes'): array
    {
        $vaults = Auth::allowedVaults();
        if ($vaults === null) {
            return ['', []];
        }
        if (empty($vaults)) {
            return [' AND 1=0 ', []];
        }
        $clauses = [];
        $params = [];
        foreach ($vaults as $v) {
            $prefix = ($alias !== '' ? $alias . '.' : '');
            $clauses[] = "{$prefix}path = ? OR {$prefix}path LIKE ?";
            $params[] = $v;
            $params[] = $v . '/%';
        }
        return [' AND (' . implode(' OR ', $clauses) . ') ', $params];
    }
}
