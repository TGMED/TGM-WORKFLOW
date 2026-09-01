<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\User;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * The company's note to one person on their birthday. Written to them by
 * name, and to nobody else: a birthday is theirs to share if they want to.
 */
class BirthdayGreeting extends TopicNotification
{
    public function topic(): NotificationTopic
    {
        return NotificationTopic::Birthday;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $user */
        $user = $notifiable;

        $mail = (new MailMessage)
            ->subject('Happy birthday, '.$this->firstName($user).'!')
            ->greeting('Happy birthday, '.$this->firstName($user).'!')
            ->line('Everyone at '.config('app.name').' hopes you have a good one.');

        $years = $this->yearsOfService($user);

        if ($years !== null) {
            $mail->line($years === 1
                ? 'It is also your first year with us. Thank you for it.'
                : "That is {$years} years with us now. Thank you for all of them.");
        }

        if (filled($user->department)) {
            $mail->line("Have a lovely day, from all of us and from everyone in {$user->department}.");
        }

        return $mail->salutation('- The '.config('app.name').' team');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            'Happy birthday, '.$this->firstName($notifiable).'!',
            'Everyone at '.config('app.name').' hopes you have a good one.',
            $this->link('/dashboard'),
        );
    }

    /**
     * Whole years since they joined, when we know the date and it is at least
     * one. A person who joined this year gets a plain greeting instead.
     */
    protected function yearsOfService(User $user): ?int
    {
        if ($user->hired_at === null) {
            return null;
        }

        $years = (int) $user->hired_at->diffInYears(Carbon::now());

        return $years >= 1 ? $years : null;
    }

    protected function firstName(User $user): string
    {
        return $user->profile?->first_name ?: explode(' ', trim($user->name))[0];
    }
}
