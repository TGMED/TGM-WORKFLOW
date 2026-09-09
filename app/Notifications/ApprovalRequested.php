<?php

namespace App\Notifications;

use App\Contracts\Approvable;
use App\Enums\ApprovalStage;
use App\Enums\NotificationTopic;
use App\Models\User;
use App\Notifications\Concerns\DescribesRequest;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells someone a request is now waiting on them, whether they are agreeing
 * cover or ruling on it.
 */
class ApprovalRequested extends TopicNotification
{
    use DescribesRequest;

    /**
     * @param  Approvable&Model  $request
     */
    public function __construct(
        protected Approvable $request,
        protected ApprovalStage $stage,
    ) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::ApprovalRequested;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $requester = $this->request->requester();
        $raisedBy = $this->request->filedBy();

        $mail = (new PanelMailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$this->firstName($notifiable).',');

        $mail = $this->stage === ApprovalStage::Relief
            ? $mail->line("{$requester->name} has asked you to cover their desk.")
            : $mail->line("{$requester->name} has a request waiting on your decision.");

        if ($raisedBy !== null) {
            $mail->line("It was filed on their behalf by {$raisedBy->name}.");
        }

        return $this->describe($mail, $this->request, $notifiable)
            ->action('Open approvals', $this->link('/approvals'))
            ->line('If this is not yours to decide, you can leave it: it will still show for anyone else it is with.');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subject(),
            $this->request->requester()->name.' - '.$this->request->summary(),
            $this->link('/approvals'),
            ['module' => $this->request->module()->value],
        );
    }

    protected function subject(): string
    {
        return $this->stage === ApprovalStage::Relief
            ? 'Cover asked of you'
            : 'A request is waiting on you';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
