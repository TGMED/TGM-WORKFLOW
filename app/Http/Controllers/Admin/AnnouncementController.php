<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementPublisher;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function __construct(protected AnnouncementPublisher $publisher) {}

    public function index(): Response
    {
        $announcements = Announcement::query()
            ->with('author:id,name')
            ->inReadingOrder()
            ->limit(60)
            ->get();

        return Inertia::render('admin/Announcements', [
            'announcements' => $announcements
                ->map(fn (Announcement $announcement): array => $this->payload($announcement))
                ->values(),
            'audience' => User::query()->active()->count(),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $announcement = Announcement::query()->create([
            ...$request->payload(),
            'user_id' => $request->user()->id,
        ]);

        return back()->with('toast', $this->outcome($announcement, 'Notice posted.'));
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        // Pulling a notice back leaves `notified_at` alone: the company has
        // already read it, and republishing must not mail them again.
        $announcement->update($request->payload());

        return back()->with('toast', $this->outcome($announcement, 'Notice updated.'));
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Notice deleted.',
        ]);
    }

    /**
     * Send the notice if it has gone live and has not been sent before, and
     * say what happened either way.
     *
     * @return array<string, string>
     */
    protected function outcome(Announcement $announcement, string $saved): array
    {
        if (! $announcement->awaitsNotifying()) {
            return ['type' => 'success', 'message' => $saved.' '.$this->standing($announcement)];
        }

        $sent = $this->publisher->announce($announcement);

        return [
            'type' => 'success',
            'message' => "{$saved} Sent to {$sent} ".($sent === 1 ? 'person.' : 'people.'),
        ];
    }

    /**
     * Why a notice was not sent, which is worth saying: an administrator who
     * meant to announce something should not have to guess.
     */
    protected function standing(Announcement $announcement): string
    {
        return match ($announcement->state()) {
            'draft' => 'It is a draft, so nobody has been told.',
            'scheduled' => 'It goes out on '.$announcement->published_at->format('j M Y').'.',
            'expired' => 'It has already come down.',
            default => 'The company was told when it first went up.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'excerpt' => $announcement->excerpt(),
            'is_pinned' => $announcement->is_pinned,
            'state' => $announcement->state(),
            'author' => $announcement->author?->name,
            'published_at' => $announcement->published_at?->toDateString(),
            'expires_at' => $announcement->expires_at?->toDateString(),
            'notified_at' => $announcement->notified_at?->toIso8601String(),
            'created_at' => $announcement->created_at?->toIso8601String(),
        ];
    }
}
