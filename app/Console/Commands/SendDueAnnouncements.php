<?php

namespace App\Console\Commands;

use App\Services\AnnouncementPublisher;
use Illuminate\Console\Command;

/**
 * Sends notices that were scheduled for later and have now come round.
 * A notice published on the spot goes out from the admin page instead, so
 * this usually finds nothing.
 */
class SendDueAnnouncements extends Command
{
    protected $signature = 'announcements:send';

    protected $description = 'Send any published announcement the company has not been told about yet';

    public function handle(AnnouncementPublisher $publisher): int
    {
        $sent = $publisher->announceDue();

        $this->info($sent === 0
            ? 'No announcements were waiting to go out.'
            : "Sent {$sent} announcement(s).");

        return self::SUCCESS;
    }
}
