<?php
/**
 * DashboardController - landing page after login.
 */

declare(strict_types=1);

class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::require();
        $this->view('dashboard', [
            'title'          => 'Dashboard',
            'counts'         => Note::counts(),
            'recentModified' => Note::recentModified(8),
            'recentOpened'   => Note::recentOpened(8),
            'topTags'        => array_slice(Tag::allWithCounts(), 0, 15),
            'tree'           => Note::tree(),
        ]);
    }
}
