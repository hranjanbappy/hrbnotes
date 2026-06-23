<?php
/**
 * Search model - FTS5 full-text search across title, content and tags.
 *
 * The user query is tokenised and turned into a safe FTS MATCH expression
 * (each term suffixed with * for prefix matching). Snippets come from the
 * built-in FTS snippet() function.
 */

declare(strict_types=1);

class Search
{
    /**
     * @return array of ['note_id','title','path','folder','modified_at','snippet']
     */
    public static function query(string $raw, int $limit = 50): array
    {
        $match = self::buildMatch($raw);
        if ($match === '') {
            return [];
        }

        [$sql, $params] = Note::allowedVaultsSql('n');
        $params = array_merge([$match], $params, [$limit]);

        try {
            // Highlight with sentinel tokens, NOT raw <mark>. The view escapes
            // the snippet for XSS safety and only then swaps the tokens for the
            // real <mark> tags - so note content can never inject HTML.
            return Database::all(
                "SELECT n.id AS note_id, n.title, n.path, n.folder, n.modified_at,
                        snippet(search_index, 1, '@@HL@@', '@@/HL@@', ' … ', 12) AS snippet
                 FROM search_index si
                 JOIN notes n ON n.id = si.note_id
                 WHERE search_index MATCH ? {$sql}
                 ORDER BY bm25(search_index) LIMIT ?",
                $params
            );
        } catch (Throwable $e) {
            // Malformed MATCH (rare) - fall back to a LIKE scan.
            return self::likeFallback($raw, $limit);
        }
    }

    /** Escape a snippet for HTML then restore highlight tokens as <mark>. */
    public static function highlight(?string $snippet): string
    {
        $safe = Security::e($snippet ?? '');
        $safe = str_replace('@@HL@@', '<mark>', $safe);
        $safe = str_replace('@@/HL@@', '</mark>', $safe);
        return $safe;
    }

    /** Turn free text into `term1* term2*` while stripping FTS operators. */
    private static function buildMatch(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // Drop characters with special meaning in FTS5.
        $clean = preg_replace('/["()*:^\-]/', ' ', $raw) ?? '';
        $terms = preg_split('/\s+/', trim($clean)) ?: [];
        $terms = array_filter($terms, fn($t) => $t !== '');
        if (!$terms) {
            return '';
        }
        return implode(' ', array_map(fn($t) => $t . '*', $terms));
    }

    private static function likeFallback(string $raw, int $limit): array
    {
        $like = '%' . $raw . '%';
        [$sql, $params] = Note::allowedVaultsSql('n');
        $params = array_merge([$like, $like, $like], $params, [$limit]);
        return Database::all(
            "SELECT n.id AS note_id, n.title, n.path, n.folder, n.modified_at,
                    substr(si.content, 1, 160) AS snippet
             FROM search_index si JOIN notes n ON n.id = si.note_id
             WHERE (si.title LIKE ? OR si.content LIKE ? OR si.tags LIKE ?) {$sql}
             LIMIT ?",
            $params
        );
    }
}
