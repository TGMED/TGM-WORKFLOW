<?php

namespace App\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A notification that belongs to a topic people can switch off.
 *
 * Subclasses say which topic they are and what to send; which channels run is
 * settled here, from the recipient's own settings. Everything is queued, so a
 * slow mail API never holds up the click that caused it.
 */
abstract class TopicNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function topic(): NotificationTopic;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $topic = $this->topic();

        $channels = [];

        if (NotificationSetting::allows($notifiable, $topic, NotificationChannel::Email)) {
            $channels[] = 'mail';
        }

        if (NotificationSetting::allows($notifiable, $topic, NotificationChannel::Push)) {
            $channels[] = 'fcm';
        }

        return $channels;
    }

    /**
     * An absolute link into the app, for mail buttons and push payloads.
     */
    protected function link(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
