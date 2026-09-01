<?php

namespace App\Notifications;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
use App\Enums\NotificationTopic;
use App\Enums\RequestModule;
use App\Models\Approval;
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
            $this->approval->decision === ApprovalDecision::Approved => 'Your request was approved',
            $this->approval->stage === ApprovalStage::Relief => 'Your request has come back to you',
            default => 'Your request was declined',
        };
    }

    protected function headline(): string
    {
        $name = $this->approval->approver->name;

        return match (true) {
            $this->approval->decision === ApprovalDecision::Approved
                && $this->approval->stage === ApprovalStage::Relief => "{$name} has agreed to cover for you.",
            $this->approval->decision === ApprovalDecision::Approved => "{$name} approved your request.",
            $this->approval->stage === ApprovalStage::Relief => "{$name} has sent your request back for a change.",
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
            $status->isOpen() && $outstanding > 0 => "It still needs {$outstanding} more approval(s).",
            $status->isOpen() => 'It is on its way through the rest of the chain.',
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
