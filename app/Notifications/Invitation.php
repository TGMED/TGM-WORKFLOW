<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The way in for somebody the people team has just added.
 *
 * Queued, and only once whatever added them has committed: an import checks a
 * file inside a transaction it then rolls back, and a preview must not email
 * anybody. Like the reset link it is not a topic anyone may switch off, since
 * somebody without an account has no settings to switch it off with.
 */
class Invitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) config('hr.invitation_days');
        $firstName = strtok((string) ($notifiable->name ?? ''), ' ') ?: 'there';

        return (new MailMessage)
            ->subject('You have been invited to '.config('app.name'))
            ->greeting("Hello {$firstName},")
            ->line('The people team has set up your account. Choose a password to get in, then we will walk you through the few details we need from you.')
            ->action('Choose your password', route('invitation.show', $this->token))
            ->line("The link works for {$days} days. If it runs out, ask the people team to send another.")
            ->line('If you were not expecting this, you can ignore it: nothing happens until the link is used.');
    }
}
