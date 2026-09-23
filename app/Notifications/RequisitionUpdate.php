<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;
use Illuminate\Support\HtmlString;

/**
 * Something happened to a requisition: raised or retired (told to finance),
 * or decided, paid, or its retirement answered (told to the requester).
 */
class RequisitionUpdate extends TopicNotification
{
    public const RAISED = 'raised';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const PAID = 'paid';

    public const RETIRED = 'retired';

    public const RETIREMENT_ACCEPTED = 'retirement_accepted';

    public const RETIREMENT_QUERIED = 'retirement_queried';

    public function __construct(protected Requisition $requisition, protected string $event) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::Requisition;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $note = match ($this->event) {
            self::DECLINED, self::APPROVED => $this->requisition->decision_note,
            self::RETIREMENT_ACCEPTED, self::RETIREMENT_QUERIED => $this->requisition->retirement?->review_note,
            default => null,
        };

        return (new PanelMailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello '.($notifiable instanceof User ? explode(' ', trim($notifiable->name))[0] : 'there').',')
            ->line($this->sentence())
            ->panel($this->details(), $note === null ? null : 'Note: '.e($note))
            ->action('Open it', $this->link($this->forFinance() ? '/admin/requisitions' : '/requisitions'));
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subjectLine(),
            $this->requisition->title,
            $this->link($this->forFinance() ? '/admin/requisitions' : '/requisitions'),
        );
    }

    protected function forFinance(): bool
    {
        return in_array($this->event, [self::RAISED, self::RETIRED], true);
    }

    protected function subjectLine(): string
    {
        $ref = $this->requisition->reference;

        return match ($this->event) {
            self::RAISED => "Requisition {$ref} is waiting on finance",
            self::APPROVED => "Requisition {$ref} was approved",
            self::DECLINED => "Requisition {$ref} was declined",
            self::PAID => "Requisition {$ref} has been paid",
            self::RETIRED => "Requisition {$ref} has been retired",
            self::RETIREMENT_ACCEPTED => "Your retirement of {$ref} was accepted",
            default => "Your retirement of {$ref} was sent back",
        };
    }

    protected function sentence(): string
    {
        $name = $this->requisition->requester->name;

        return match ($this->event) {
            self::RAISED => "{$name} has raised a requisition for you to decide.",
            self::APPROVED => 'Your requisition has been approved. Finance will record it once it is paid.',
            self::DECLINED => 'Your requisition has been declined.',
            self::PAID => 'Your requisition has been paid. Retire it with your receipts once the money is spent.',
            self::RETIRED => "{$name} has accounted for a requisition, for you to check.",
            self::RETIREMENT_ACCEPTED => 'Finance has accepted your retirement, and the requisition is closed.',
            default => 'Finance has sent your retirement back. Correct it and send it again.',
        };
    }

    protected function details(): HtmlString
    {
        $r = $this->requisition;

        $rows = [
            '<strong>Reference:</strong> '.e((string) $r->reference),
            '<strong>For:</strong> '.e($r->title),
            '<strong>Amount:</strong> NGN '.e(number_format((float) $r->amount, 2)),
            '<strong>Pay to:</strong> '.e("{$r->account_name}, {$r->bank_name} {$r->account_number}"),
        ];

        if ($r->retirement !== null && in_array($this->event, [self::RETIRED, self::RETIREMENT_ACCEPTED, self::RETIREMENT_QUERIED], true)) {
            $rows[] = '<strong>Spent:</strong> NGN '.e(number_format((float) $r->retirement->amount_spent, 2));
        }

        return new HtmlString(implode("<br>\n", $rows));
    }
}
