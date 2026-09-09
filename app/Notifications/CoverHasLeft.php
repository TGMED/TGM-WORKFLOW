<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;

/**
 * Tells somebody the colleague who was covering their desk has left.
 *
 * The company knowing is not the same as them knowing. Until this, the only
 * sign was a count in a toast on the screen of whoever recorded the exit,
 * which was gone the moment the page reloaded - and the person whose leave it
 * was found out when their cover did not turn up.
 */
class CoverHasLeft extends TopicNotification
{
    public function __construct(
        protected LeaveRequest $leave,
        protected string $leaverName,
        protected bool $canRename,
    ) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::RequestRaised;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        return (new PanelMailMessage)
            ->subject('Your cover has left the company')
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line("{$this->leaverName} has left the company, and they were covering your desk.")
            ->panel($this->leave->summary(), $this->whatHappensNow())
            ->action(
                $this->canRename ? 'Name somebody else' : 'View the request',
                $this->link('/leave'),
            );
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            'Your cover has left the company',
            "{$this->leaverName} was covering your desk. ".$this->whatHappensNow(),
            $this->link('/leave'),
            ['module' => 'leave'],
        );
    }

    /**
     * Whether this is theirs to fix turns on how far the request has got. One
     * nobody has ruled on yet is still theirs to edit; one already approved is
     * not, and telling them to go and change it would only waste their time.
     */
    protected function whatHappensNow(): string
    {
        return $this->canRename
            ? 'Open the request and name somebody else to cover, and it carries on from there.'
            : 'The request stands as approved. Speak to the people team about who covers the desk.';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
