<?php
/**
 * TagController - tag index and per-tag note listing.
 */

declare(strict_types=1);

class TagController extends Controller
{
    public function index(): void
    {
        Auth::require();
        $this->view('tags', [
            'title' => 'Tags',
            'tags'  => Tag::allWithCounts(),
            'tree'  => Note::tree(),
        ]);
    }

    public function show(): void
    {
        Auth::require();
        $tag = trim((string) ($_GET['tag'] ?? ''));
        $this->view('tag', [
            'title' => '#' . $tag,
            'tag'   => $tag,
            'notes' => $tag !== '' ? Tag::notes($tag) : [],
            'tree'  => Note::tree(),
        ]);
    }
}
