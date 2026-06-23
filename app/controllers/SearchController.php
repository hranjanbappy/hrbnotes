<?php
/**
 * SearchController - full-text search page and instant-search JSON endpoint.
 */

declare(strict_types=1);

class SearchController extends Controller
{
    public function page(): void
    {
        Auth::require();
        $q = trim((string) ($_GET['q'] ?? ''));
        $results = $q !== '' ? Search::query($q) : [];
        $this->view('search', [
            'title'   => 'Search',
            'query'   => $q,
            'results' => $results,
            'tree'    => Note::tree(),
        ]);
    }

    /** GET JSON: instant search results. */
    public function api(): void
    {
        Auth::require();
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->json(['query' => $q, 'results' => $q === '' ? [] : Search::query($q, 25)]);
    }
}
