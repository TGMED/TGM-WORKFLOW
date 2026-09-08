<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Concerns\DescribesRequest;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the named approver that leave is on its way to them.
 *
 * Their turn does not come until the relief officer has agreed cover, so this
 * asks nothing of them; it is a heads-up, so they can plan around the dates
 * before the request formally lands.
 */
class ApprovalUpcoming extends TopicNotification
{
    use DescribesRequest;

    public function __construct(protected LeaveRequest $request) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::RequestRaised;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $requester = $this->request->requester();
        $filer = $this->request->filedBy();

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line("{$requester->name} has named you to approve their leave.");

        if ($filer !== null) {
            $mail->line("It was filed on their behalf by {$filer->name}.");
        }

        return $this->describe($mail, $this->request, $notifiable)
            ->action('View the request', $this->link('/approvals'))
            ->line('Nothing is needed from you yet; we will write again when it is your turn.');
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
        return 'Leave heading your way for approval';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
