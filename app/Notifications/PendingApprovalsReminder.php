<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;
use Illuminate\Support\HtmlString;

/**
 * The morning round-up of requests still waiting for a decision.
 *
 * Two shapes from one class. An approver's copy lists what is waiting on
 * them, and falls under the approvals topic nobody can switch off. A super
 * admin's copy is the overview: everything pending and who it is sitting
 * with, under its own topic so they can turn it off if it is too much.
 *
 * Carries plain values rather than models, so the queued job is small and a
 * request decided in the meantime cannot fail it.
 */
class PendingApprovalsReminder extends TopicNotification
{
    /**
     * @param  array<int, array{module: string, requester: string, summary: string, hours_waiting: int, waiting_on: array<int, string>, waiting_on_you: bool}>  $requests
     */
    public function __construct(
        protected array $requests,
        protected bool $overview,
    ) {}

    public function topic(): NotificationTopic
    {
        return $this->overview
            ? NotificationTopic::ApprovalReminder
            : NotificationTopic::ApprovalRequested;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $count = count($this->requests);
        $yours = $this->yoursCount();

        $mail = (new PanelMailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$this->firstName($notifiable).',');

        if ($this->overview) {
            $mail->line(sprintf(
                '%d %s still waiting for a decision%s.',
                $count,
                $count === 1 ? 'request is' : 'requests are',
                $yours > 0 ? ", {$yours} of them with you" : '',
            ));
        } else {
            $mail->line(sprintf(
                '%d %s waiting for your decision.',
                $count,
                $count === 1 ? 'request is' : 'requests are',
            ));
        }

        $mail->panel(...array_map(fn (array $row): HtmlString => $this->row($row), $this->requests));

        return $mail
            ->action('Open approvals', $this->link('/approvals'))
            ->line('You will get this reminder each working morning while requests are waiting.');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subject(),
            $this->overview
                ? 'Open approvals to see what is waiting and who it is with.'
                : 'Open approvals to decide them.',
            $this->link('/approvals'),
        );
    }

    protected function subject(): string
    {
        $count = count($this->requests);
        $noun = $count === 1 ? 'request' : 'requests';

        return $this->overview
            ? "Reminder: {$count} {$noun} still pending"
            : "Reminder: {$count} {$noun} waiting for your decision";
    }

    /**
     * @param  array{module: string, requester: string, summary: string, hours_waiting: int, waiting_on: array<int, string>, waiting_on_you: bool}  $row
     */
    protected function row(array $row): HtmlString
    {
        $lines = [
            '<strong>'.e(ucfirst($row['module'])).'</strong> from '.e($row['requester']),
            e($row['summary']),
            'Waiting '.e($this->waited($row['hours_waiting'])),
        ];

        if ($this->overview) {
            $lines[] = $row['waiting_on'] === []
                ? 'Nobody is able to decide it'
                : 'With '.e($this->names($row['waiting_on'])).($row['waiting_on_you'] ? ' (including you)' : '');
        }

        return new HtmlString(implode("<br>\n", $lines));
    }

    protected function yoursCount(): int
    {
        return count(array_filter($this->requests, fn (array $row): bool => $row['waiting_on_you']));
    }

    /**
     * In days once there are enough of them, since "72 hours" is a sum the
     * reader should not have to do.
     */
    protected function waited(int $hours): string
    {
        if ($hours < 48) {
            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        $days = intdiv($hours, 24);

        return $days.' day'.($days === 1 ? '' : 's');
    }

    /**
     * @param  array<int, string>  $names
     */
    protected function names(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        // A long list of approvers reads as noise; the first few make the point.
        if (count($names) > 4) {
            return implode(', ', array_slice($names, 0, 3)).' and '.(count($names) - 3).' others';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
