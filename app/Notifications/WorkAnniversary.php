<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\User;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The company's note to one person on the anniversary of their first day.
 *
 * Written to them and to nobody else, on the same reasoning as the birthday
 * greeting: how long someone has been somewhere is theirs to mention.
 */
class WorkAnniversary extends TopicNotification
{
    public function __construct(protected int $years) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::Anniversary;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $user */
        $user = $notifiable;

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Congratulations, '.$this->firstName($user).'!')
            ->line($this->milestone());

        if (filled($user->position)) {
            $mail->line("Thank you for everything you have brought to us as {$user->position}.");
        }

        if ($user->department !== null) {
            $mail->line("From all of us, and from everyone in {$user->department->name}.");
        }

        return $mail->salutation('- The '.config('app.name').' team');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subject(),
            $this->milestone(),
            $this->link('/dashboard'),
        );
    }

    protected function subject(): string
    {
        return $this->years === 1
            ? 'One year with us today'
            : "{$this->years} years with us today";
    }

    protected function milestone(): string
    {
        return $this->years === 1
            ? 'Today marks a year since your first day with '.config('app.name').'.'
            : "Today marks {$this->years} years since your first day with ".config('app.name').'.';
    }

    protected function firstName(User $user): string
    {
        return $user->profile?->first_name ?: explode(' ', trim($user->name))[0];
    }
}
