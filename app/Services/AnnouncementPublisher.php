<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Puts a notice in front of the company.
 *
 * Sending is one-way: `notified_at` is stamped the first time a notice goes
 * out, so a notice pulled back and published again does not land in everyone's
 * inbox a second time.
 */
class AnnouncementPublisher
{
    /**
     * Tell everyone still on the books, bar the author, who wrote it.
     *
     * Returns how many people were written to, which is what the toast on the
     * admin page reports back.
     */
    public function announce(Announcement $announcement): int
    {
        if (! $announcement->awaitsNotifying()) {
            return 0;
        }

        $announcement->loadMissing('author');

        $sent = 0;

        // Chunked because this is the one place the app writes to the whole
        // company at once, and a large staff list should not be held in
        // memory in one go.
        User::query()
            ->active()
            ->when($announcement->user_id, fn ($query, int $author) => $query->whereKeyNot($author))
            ->chunkById(200, function (Collection $people) use ($announcement, &$sent): void {
                Notification::send($people, new AnnouncementPublished($announcement));

                $sent += $people->count();
            });

        $announcement->forceFill(['notified_at' => Carbon::now()])->save();

        return $sent;
    }

    /**
     * Notices whose publish date has come round while nobody was looking.
     * Scheduling a notice is a promise to send it later, and this is what
     * keeps it.
     *
     * @return int The number of notices sent.
     */
    public function announceDue(): int
    {
        $due = Announcement::query()
            ->live()
            ->whereNull('notified_at')
            ->orderBy('published_at')
            ->get();

        foreach ($due as $announcement) {
            $this->announce($announcement);
        }

        return $due->count();
    }
}
