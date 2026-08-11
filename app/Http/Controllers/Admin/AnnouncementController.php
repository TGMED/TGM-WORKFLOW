<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnnouncementRequest;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Announcements', [
            'announcements' => Announcement::query()
                ->with('author:id,name')
                ->inReadingOrder()
                ->get()
                ->map(fn (Announcement $announcement): array => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'is_pinned' => $announcement->is_pinned,
                    'published_at' => $announcement->published_at?->toDateString(),
                    'expires_at' => $announcement->expires_at?->toDateString(),
                    'state' => $announcement->state(),
                    'author' => $announcement->author?->name,
                    'created_at' => $announcement->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $announcement = new Announcement($request->payload());
        $announcement->user_id = $request->user()->id;
        $announcement->save();

        return $this->done(
            $announcement->isLive()
                ? "\"{$announcement->title}\" is on everyone's dashboard."
                : "\"{$announcement->title}\" has been saved.",
        );
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($request->payload());

        return $this->done("\"{$announcement->title}\" updated.");
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return $this->done("\"{$announcement->title}\" has been deleted.");
    }

    protected function done(string $message): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
