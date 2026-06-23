<?php
/**
 * MarkdownParser - extraction helpers for Obsidian-flavoured Markdown.
 *
 * This class does NOT render Markdown to HTML (that happens client-side with
 * Marked.js). It only *extracts* metadata - title, tags, wiki links - and
 * produces a plain-text version used for the search index and snippets.
 *
 * Obsidian compatibility: extraction is read-only and never rewrites the file,
 * so formatting, tags and links are preserved exactly.
 */

declare(strict_types=1);

class MarkdownParser
{
    /**
     * Parse raw note content and return:
     *   ['title' => string, 'tags' => string[], 'links' => string[],
     *    'plain' => string, 'frontmatter' => array]
     */
    public static function parse(string $content, string $fallbackTitle): array
    {
        $frontmatter = [];
        $body = $content;

        // --- YAML front matter (--- ... ---) at the very top -----------------
        if (preg_match('/^(?:\xEF\xBB\xBF)?---\s*\R(.*?)\R---\s*\R?/s', $content, $m)) {
            $frontmatter = self::parseFrontMatter($m[1]);
            $body = substr($content, strlen($m[0]));
        }

        return [
            'title'       => self::extractTitle($body, $frontmatter, $fallbackTitle),
            'tags'        => self::extractTags($body, $frontmatter),
            'links'       => self::extractWikiLinks($body),
            'plain'       => self::toPlainText($body),
            'frontmatter' => $frontmatter,
        ];
    }

    /** Title priority: front-matter `title` > first H1 > filename. */
    private static function extractTitle(string $body, array $fm, string $fallback): string
    {
        if (!empty($fm['title'])) {
            return trim((string) $fm['title']);
        }
        if (preg_match('/^\s{0,3}#\s+(.+?)\s*$/m', $body, $m)) {
            return trim($m[1]);
        }
        return $fallback;
    }

    /**
     * Tags from `#tag` inline syntax plus a front-matter `tags:` list.
     * Inline tags inside code spans / fenced code blocks are ignored.
     */
    private static function extractTags(string $body, array $fm): array
    {
        $tags = [];

        // Front-matter tags (list or comma/space separated string).
        if (isset($fm['tags'])) {
            foreach (self::normaliseList($fm['tags']) as $t) {
                $t = ltrim(trim($t), '#');
                if ($t !== '') {
                    $tags[] = strtolower($t);
                }
            }
        }

        // Strip fenced code blocks and inline code so we don't pick up `#hex`.
        $clean = preg_replace('/```.*?```/s', '', $body) ?? $body;
        $clean = preg_replace('/`[^`]*`/', '', $clean) ?? $clean;

        // #tag - allows nested tags like #fire/design, letters digits _ - /.
        if (preg_match_all('/(?<![\w&])#([A-Za-z][\w\/\-]*)/', $clean, $m)) {
            foreach ($m[1] as $t) {
                $tags[] = strtolower($t);
            }
        }

        return array_values(array_unique($tags));
    }

    /** Extract [[Wiki Link]] and [[Note|Alias]] targets (the target part). */
    private static function extractWikiLinks(string $body): array
    {
        $links = [];
        // Ignore links inside fenced code blocks.
        $clean = preg_replace('/```.*?```/s', '', $body) ?? $body;

        if (preg_match_all('/\[\[([^\[\]]+?)\]\]/', $clean, $m)) {
            foreach ($m[1] as $raw) {
                // Drop alias (|) and heading/block anchors (#, ^).
                $target = preg_split('/[|#^]/', $raw)[0] ?? $raw;
                $target = trim($target);
                if ($target !== '') {
                    $links[] = $target;
                }
            }
        }
        return array_values(array_unique($links));
    }

    /**
     * Produce a plain-text rendition for FTS indexing and snippets:
     * strips fences, markup characters and wiki-link braces but keeps words.
     */
    public static function toPlainText(string $body): string
    {
        $t = $body;
        $t = preg_replace('/```.*?```/s', ' ', $t) ?? $t;      // fenced code
        $t = preg_replace('/`[^`]*`/', ' ', $t) ?? $t;          // inline code
        $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $t) ?? $t; // images
        $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $t) ?? $t; // links -> text
        $t = preg_replace('/\[\[([^\]|]*)(?:\|([^\]]*))?\]\]/', '$1 $2', $t) ?? $t; // wiki
        $t = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $t) ?? $t; // headings
        $t = preg_replace('/[*_>#~\-]+/', ' ', $t) ?? $t;       // misc markup
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return trim($t);
    }

    // --- helpers ------------------------------------------------------------

    private static function parseFrontMatter(string $yaml): array
    {
        // Minimal YAML: key: value and simple `- item` lists. No external lib.
        $out = [];
        $lines = preg_split('/\R/', $yaml) ?: [];
        $currentKey = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s*-\s+(.*)$/', $line, $m) && $currentKey !== null) {
                $out[$currentKey] = array_merge((array) ($out[$currentKey] ?? []), [trim($m[1], " \"'")]);
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_\-]+)\s*:\s*(.*)$/', $line, $m)) {
                $currentKey = $m[1];
                $val = trim($m[2]);
                if ($val === '') {
                    $out[$currentKey] = [];
                } else {
                    $out[$currentKey] = trim($val, " \"'");
                }
            }
        }
        return $out;
    }

    private static function normaliseList($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $value = (string) $value;
        $value = trim($value, " []");
        return preg_split('/[,\s]+/', $value) ?: [];
    }
}
