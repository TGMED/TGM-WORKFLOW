<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The noticeboard, for everybody.
 *
 * Notices used to sit on the dashboard as a panel of four, which meant the
 * fifth was unreadable and the dashboard was half numbers and half prose. They
 * get a page of their own, and the dashboard gets to be about numbers.
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        $notices = Announcement::query()
            ->with('author:id,name')
            ->live()
            ->inReadingOrder()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'excerpt' => $announcement->excerpt(),
                'is_pinned' => $announcement->is_pinned,
                'author' => $announcement->author?->name,
                'published_at' => $announcement->published_at?->toIso8601String(),
            ]);

        return Inertia::render('Announcements', [
            'announcements' => $notices,
        ]);
    }
}
