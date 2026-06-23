<?php
/**
 * Tag model - tag listing and tag pages.
 */

declare(strict_types=1);

class Tag
{
    /** All tags with their note counts, ordered by popularity then name. */
    public static function allWithCounts(): array
    {
        [$sql, $params] = Note::allowedVaultsSql('n');
        return Database::all(
            "SELECT t.tag_name, COUNT(nt.note_id) AS cnt
             FROM tags t
             JOIN note_tags nt ON nt.tag_id = t.id
             JOIN notes n ON n.id = nt.note_id
             WHERE 1=1 {$sql}
             GROUP BY t.id ORDER BY cnt DESC, t.tag_name ASC",
            $params
        );
    }

    /** Notes carrying a given tag. */
    public static function notes(string $tagName): array
    {
        [$sql, $params] = Note::allowedVaultsSql('n');
        $params = array_merge([$tagName], $params);
        return Database::all(
            "SELECT n.id, n.title, n.path, n.folder, n.modified_at
             FROM notes n
             JOIN note_tags nt ON nt.note_id = n.id
             JOIN tags t ON t.id = nt.tag_id
             WHERE t.tag_name = ? COLLATE NOCASE {$sql}
             ORDER BY n.title",
            $params
        );
    }

    public static function exists(string $tagName): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM tags WHERE tag_name = ? COLLATE NOCASE',
            [$tagName]
        ) > 0;
    }
}
