<?php

namespace App\Notifications;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
use App\Enums\NotificationTopic;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Approval;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the person whose request it is what just happened to it.
 */
class RequestDecided extends TopicNotification
{
    /**
     * @param  Approvable&Model  $request
     */
    public function __construct(
        protected Approvable $request,
        protected Approval $approval,
    ) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::RequestDecided;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line($this->headline())
            ->line($this->request->summary());

        if (filled($this->approval->comment)) {
            $mail->line("They said: \"{$this->approval->comment}\"");
        }

        return $mail
            ->line($this->outcome())
            ->action('View the request', $this->link($this->path()));
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subject(),
            $this->request->summary(),
            $this->link($this->path()),
            ['module' => $this->request->module()->value],
        );
    }

    protected function subject(): string
    {
        return match (true) {
            $this->approval->decision === ApprovalDecision::Approved
                && $this->approval->stage === ApprovalStage::Relief => 'Your cover has been agreed',
            $this->isGranted() => $this->request->module() === RequestModule::Leave
                ? 'Your leave has been approved'
                : 'Your request was approved',
            $this->approval->decision === ApprovalDecision::Approved => 'Your request has moved forward',
            $this->wasSentBack() => 'Your request has come back to you',
            default => 'Your request was declined',
        };
    }

    /**
     * Whether this decision granted the request outright. One approval along
     * the way is not the request being approved, and must never be told as if
     * it were: leave in particular is only granted once a super admin has
     * given the final approval.
     */
    protected function isGranted(): bool
    {
        return $this->approval->decision === ApprovalDecision::Approved
            && $this->request->requestStatus() === RequestStatus::Approved;
    }

    /**
     * Whether the decline handed the request back rather than ending it.
     *
     * Read off where the request landed rather than off who took the decision:
     * a relief officer has always sent leave back, and now every approver on a
     * leave request does too. The status is the one thing that knows.
     */
    protected function wasSentBack(): bool
    {
        return $this->request->requestStatus() === RequestStatus::Returned;
    }

    protected function headline(): string
    {
        // Leave names its approvers by office; the cover keeps their name.
        $name = $this->request instanceof LeaveRequest
            ? $this->request->approverLabel($this->approval)
            : $this->approval->approver->name;

        // A job title reads as "The Operations Manager ..."; a name as itself.
        if ($this->request instanceof LeaveRequest
            && $this->approval->stage !== ApprovalStage::Relief
            && ($this->approval->stage === ApprovalStage::Admin || trim((string) $this->approval->approver->position) !== '')) {
            $name = 'The '.$name;
        }

        return match (true) {
            $this->approval->decision === ApprovalDecision::Approved
                && $this->approval->stage === ApprovalStage::Relief => "{$name} has agreed to cover for you.",
            $this->isGranted() => "{$name} gave the final approval. Your request is approved.",
            $this->approval->decision === ApprovalDecision::Approved => "{$name} has signed off your request. It is not approved yet.",
            $this->wasSentBack() => "{$name} has sent your request back for a change.",
            default => "{$name} declined your request.",
        };
    }

    /**
     * Where the request stands now that this decision has landed, which is
     * not always the same as what the decision itself was.
     */
    protected function outcome(): string
    {
        $status = $this->request->requestStatus();
        $outstanding = $this->request->approvalsOutstanding();

        return match (true) {
            // Say what comes next in words, so "still needs a super admin's
            // final approval" reads as that rather than as a bare count.
            $status->isOpen() && ($next = $this->request->nextStep()) !== null => $next,
            $status->isOpen() && $outstanding > 0 => "It still needs {$outstanding} more approval(s).",
            $status->isOpen() => 'It is on its way through the rest of the chain.',
            // A returned request is not finished with, so it is told as
            // something to do rather than as a verdict.
            $status === RequestStatus::Returned => 'Change it and send it round again, or withdraw it if it is no longer needed.',
            default => "It is now marked {$status->label()}.",
        };
    }

    protected function path(): string
    {
        return $this->request->module() === RequestModule::Leave ? '/leave' : '/lateness';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
