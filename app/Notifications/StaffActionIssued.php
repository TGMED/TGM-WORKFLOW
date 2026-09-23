<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Enums\StaffActionKind;
use App\Models\StaffAction;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;
use Illuminate\Support\HtmlString;

/**
 * A query, warning or confirmation, sent to the person it is about, their
 * head of department and the people team. The person it is about is written
 * to directly; everyone else is told about it.
 */
class StaffActionIssued extends TopicNotification
{
    public function __construct(protected StaffAction $action) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::StaffAction;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $mine = $this->isSubject($notifiable);

        $mail = (new PanelMailMessage)
            ->subject($this->subjectLine($notifiable))
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line($this->opening($mine));

        return $mail
            ->panel($this->details(), $mine && $this->action->kind->expectsResponse()
                ? 'Answer it from the Conduct page'.($this->action->response_due_on !== null
                    ? ' by '.$this->action->response_due_on->format('j M Y').'.'
                    : '.')
                : null)
            ->action($mine ? 'Read it' : 'See the record', $this->link($mine ? '/conduct' : '/admin/conduct'));
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subjectLine($notifiable),
            $this->action->title,
            $this->link($this->isSubject($notifiable) ? '/conduct' : '/admin/conduct'),
        );
    }

    protected function subjectLine(object $notifiable): string
    {
        $name = $this->action->subject->name;

        return match ($this->action->kind) {
            StaffActionKind::Query => $this->isSubject($notifiable) ? 'You have been issued a query' : "{$name} has been issued a query",
            StaffActionKind::Warning => $this->isSubject($notifiable) ? 'You have been issued a warning' : "{$name} has been issued a warning",
            StaffActionKind::Confirmation => $this->isSubject($notifiable) ? 'Your appointment has been confirmed' : "{$name} has been confirmed",
        };
    }

    protected function opening(bool $mine): string
    {
        $issuer = $this->action->issuedBy->name ?? 'HR';
        $name = $this->action->subject->name;

        return match ($this->action->kind) {
            StaffActionKind::Query => $mine
                ? "{$issuer} has issued you a query and asks for your written answer."
                : "{$issuer} has issued {$name} a query.",
            StaffActionKind::Warning => $mine
                ? "{$issuer} has issued you a formal warning."
                : "{$issuer} has issued {$name} a formal warning.",
            StaffActionKind::Confirmation => $mine
                ? 'Congratulations: you have completed your probation and your appointment is confirmed.'
                : "{$issuer} has confirmed {$name}'s appointment at the end of their probation.",
        };
    }

    protected function details(): HtmlString
    {
        $rows = [
            '<strong>'.e($this->action->kind->label()).':</strong> '.e($this->action->title),
        ];

        if ($this->action->offence !== null) {
            $rows[] = '<strong>Offence:</strong> '.e($this->action->offence->title);
        }

        $rows[] = nl2br(e($this->action->body));

        return new HtmlString(implode("<br>\n", $rows));
    }

    protected function isSubject(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->id === $this->action->subject_user_id;
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
