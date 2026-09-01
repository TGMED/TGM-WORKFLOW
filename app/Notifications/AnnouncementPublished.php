<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\Announcement;
use App\Models\User;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A company notice, sent to everyone still on the books when it is published.
 */
class AnnouncementPublished extends TopicNotification
{
    public function __construct(protected Announcement $announcement) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::Announcement;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->announcement->title)
            ->greeting('Hello '.$this->firstName($notifiable).',');

        // The body is written as prose in a textarea, so each paragraph goes
        // out as its own line rather than one wall of text.
        foreach ($this->paragraphs() as $paragraph) {
            $mail->line($paragraph);
        }

        $mail->action('Open the dashboard', $this->link('/dashboard'));

        // A notice outlives the administrator who wrote it, so it may have no
        // author left to sign off with.
        return $this->announcement->author === null
            ? $mail
            : $mail->salutation('- '.$this->announcement->author->name);
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->announcement->title,
            $this->announcement->excerpt(),
            $this->link('/dashboard'),
            ['announcement_id' => (string) $this->announcement->id],
        );
    }

    /**
     * @return array<int, string>
     */
    protected function paragraphs(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/\R{2,}/', $this->announcement->body) ?: [],
        )));
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
