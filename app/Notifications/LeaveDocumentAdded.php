<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Concerns\DescribesRequest;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;

/**
 * Tells whoever a leave request is waiting on that a document has been added
 * to it, so a medical record they asked for does not sit there unread.
 */
class LeaveDocumentAdded extends TopicNotification
{
    use DescribesRequest;

    /**
     * @param  array<int, string>  $names
     */
    public function __construct(protected LeaveRequest $request, protected array $names, protected string $addedBy) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::ApprovalRequested;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $mail = (new PanelMailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line("{$this->addedBy} added {$this->documents()} to {$this->request->requester()->name}'s leave request: ".implode(', ', $this->names).'.');

        return $this->describe($mail, $this->request, $notifiable)
            ->action('Review the request', $this->link('/approvals'));
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
        return 'New '.(count($this->names) === 1 ? 'document' : 'documents').' on '.$this->request->requester()->name."'s leave";
    }

    protected function documents(): string
    {
        return count($this->names) === 1 ? 'a document' : count($this->names).' documents';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
