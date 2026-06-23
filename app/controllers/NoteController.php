<?php
/**
 * NoteController - the main workspace (viewer + editor) and its JSON API.
 *
 * Page routes render the shell; the browser then calls the JSON endpoints to
 * load/save note content without a full reload.
 */

declare(strict_types=1);

class NoteController extends Controller
{
    /** Render the workspace shell (sidebar tree + viewer/editor panes). */
    public function workspace(): void
    {
        Auth::require();
        $path = $_GET['path'] ?? '';
        $this->view('workspace', [
            'title'       => 'Notes',
            'tree'        => Note::tree(),
            'initialPath' => is_string($path) ? $path : '',
        ]);
    }

    /** GET JSON: note content + metadata + backlinks + tags. */
    public function apiGet(): void
    {
        Auth::require();
        $path = $_GET['path'] ?? '';
        if (!is_string($path) || $path === '') {
            $this->json(['error' => 'Missing path.'], 400);
        }

        $note = Note::findByPath($path);
        $content = Note::readContent($path);
        if ($note === null || $content === null) {
            $this->json(['error' => 'Note not found.'], 404);
        }

        Note::markOpened((int) $note['id']);
        $abs = Security::safeVaultPath($path, true);
        clearstatcache(true, $abs);

        $this->json([
            'id'        => (int) $note['id'],
            'title'     => $note['title'],
            'path'      => $note['path'],
            'folder'    => $note['folder'],
            'content'   => $content,
            'mtime'     => (int) filemtime($abs),
            'modified'  => $note['modified_at'],
            'tags'      => Note::tagsFor((int) $note['id']),
            'backlinks' => Note::backlinks((int) $note['id']),
            'outlinks'  => Note::outlinks((int) $note['id']),
        ]);
    }

    /** POST JSON: save note content (with optimistic lock via mtime). */
    public function apiSave(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        $in      = $this->jsonInput();
        $path    = (string) ($in['path'] ?? '');
        $newPath = isset($in['newPath']) ? trim((string)$in['newPath']) : $path;
        $content = (string) ($in['content'] ?? '');
        $mtime   = isset($in['mtime']) ? (int) $in['mtime'] : null;

        if ($path === '') {
            $this->json(['error' => 'Missing path.'], 400);
        }

        // Ensure newPath has a valid Markdown extension if it was changed
        if ($newPath !== '' && !preg_match('/\.(md|markdown)$/i', $newPath)) {
            $newPath .= '.md';
        }

        if ($newPath !== $path) {
            // Check conflict on the old path first before renaming
            if ($mtime !== null) {
                $oldAbs = Security::safeVaultPath($path, true);
                if ($oldAbs !== null) {
                    clearstatcache(true, $oldAbs);
                    if ((int)filemtime($oldAbs) !== $mtime) {
                        $this->json([
                            'error'   => 'conflict',
                            'message' => 'File changed on disk since you opened it. Reload to merge.',
                        ], 409);
                        return;
                    }
                }
            }

            // Verify permission for the new path
            if (!Note::isPathAllowed($newPath)) {
                $this->json(['error' => 'Permission denied for new path.'], 403);
                return;
            }

            // Check if file already exists at target
            $newAbs = Security::safeVaultPath($newPath, false);
            if ($newAbs === null) {
                $this->json(['error' => 'Invalid new path.'], 400);
                return;
            }
            if (strcasecmp($path, $newPath) !== 0 && file_exists($newAbs)) {
                $this->json(['error' => 'A file already exists at the new path.'], 400);
                return;
            }

            // Rename the file and update DB references
            if (!Note::rename($path, $newPath)) {
                $this->json(['error' => 'Failed to rename note.'], 500);
                return;
            }

            // Set current path to the renamed path, and bypass subsequent conflict checks during write
            $path = $newPath;
            $mtime = null;
        }

        $result = Note::save($path, $content, $mtime);
        if ($result === 'conflict') {
            $this->json([
                'error'   => 'conflict',
                'message' => 'File changed on disk since you opened it. Reload to merge.',
            ], 409);
        }
        if ($result === 'error') {
            $this->json(['error' => 'Unable to save note.'], 500);
        }

        $abs = Security::safeVaultPath($path, true);
        clearstatcache(true, $abs);
        $note = Note::findByPath($path);

        $this->json([
            'status'   => 'ok',
            'path'     => $path,
            'mtime'    => (int) filemtime($abs),
            'modified' => $note['modified_at'] ?? null,
            'tags'     => Note::tagsFor((int) $note['id']),
        ]);
    }

    /** POST JSON: create a new note. */
    public function apiCreate(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        $in   = $this->jsonInput();
        $path = trim((string) ($in['path'] ?? ''));
        if ($path === '') {
            $this->json(['error' => 'Missing path.'], 400);
        }

        $title   = pathinfo($path, PATHINFO_FILENAME);
        $content = "# " . $title . "\n\n";
        $created = Note::create($path, $content);

        if ($created === null) {
            $this->json(['error' => 'Could not create note (already exists or bad path).'], 400);
        }
        $this->json(['status' => 'ok', 'path' => $created]);
    }

    /** GET JSON: the folder tree (used to refresh sidebar after changes). */
    public function apiTree(): void
    {
        Auth::require();
        $this->json(['tree' => Note::tree()]);
    }

    /** POST JSON: delete a note permanently. */
    public function apiDelete(): void
    {
        Auth::require();
        Csrf::verifyRequest();

        $in   = $this->jsonInput();
        $path = trim((string) ($in['path'] ?? ''));
        if ($path === '') {
            $this->json(['error' => 'Missing path.'], 400);
        }

        $abs = Security::safeVaultPath($path, true);
        if ($abs === null || !is_file($abs)) {
            $this->json(['error' => 'Note not found.'], 404);
        }

        if (!unlink($abs)) {
            $this->json(['error' => 'Could not delete file.'], 500);
        }

        // Remove from database.
        $note = Note::findByPath($path);
        if ($note) {
            Database::run('DELETE FROM note_tags WHERE note_id = ?', [(int) $note['id']]);
            Database::run('DELETE FROM links WHERE source_note = ? OR target_note = ?',
                [(int) $note['id'], (int) $note['id']]);
            Database::run('DELETE FROM notes WHERE id = ?', [(int) $note['id']]);
        }

        $this->json(['status' => 'ok']);
    }
}
