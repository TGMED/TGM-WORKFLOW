<?php

namespace App\Notifications;

use App\Contracts\Approvable;
use App\Enums\NotificationTopic;
use App\Enums\RequestModule;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the requester side that a request is on the books.
 *
 * For someone filing their own, it is a receipt. For someone an approver
 * filed for, it is the first they hear of it, so it says who did the filing
 * and what it is now waiting on.
 */
class RequestRaised extends TopicNotification
{
    /**
     * @param  Approvable&Model  $request
     */
    public function __construct(protected Approvable $request) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::RequestRaised;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject($notifiable))
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line($this->headline($notifiable))
            ->line($this->request->summary())
            ->line($this->waitingOn())
            ->action('View the request', $this->link($this->path()));
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subject($notifiable),
            $this->request->summary(),
            $this->link($this->path()),
            ['module' => $this->request->module()->value],
        );
    }

    /**
     * Whether this copy is going to the person the request is about, rather
     * than to the approver who filed it for them.
     */
    protected function isRequester(object $notifiable): bool
    {
        return $notifiable instanceof User
            && $notifiable->id === $this->request->requester()->id;
    }

    protected function subject(object $notifiable): string
    {
        $noun = $this->request->module() === RequestModule::Leave
            ? 'leave request'
            : 'lateness explanation';

        if (! $this->isRequester($notifiable)) {
            return "You filed a {$noun} for ".$this->request->requester()->name;
        }

        return $this->request->filedBy() === null
            ? "Your {$noun} is in"
            : "A {$noun} has been filed for you";
    }

    protected function headline(object $notifiable): string
    {
        $filer = $this->request->filedBy();

        if (! $this->isRequester($notifiable)) {
            return 'This went in for '.$this->request->requester()->name.'. They have been told as well.';
        }

        return $filer === null
            ? 'Your request has gone in.'
            : "{$filer->name} filed this for you.";
    }

    /**
     * Who the request is sitting with now, so nobody has to open the app to
     * find out whether anything is expected of them next.
     */
    protected function waitingOn(): string
    {
        if ($this->request->module() !== RequestModule::Leave) {
            return 'It is now with an approver for a decision.';
        }

        /** @var LeaveRequest $leave */
        $leave = $this->request;

        if ($leave->reliefOfficer !== null && ! $leave->reliefAgreed()) {
            return "It is with {$leave->reliefOfficer->name} to agree cover, and goes on for approval after that.";
        }

        return $leave->supervisor === null
            ? 'It is now with an approver for a decision.'
            : "It is with {$leave->supervisor->name} for approval.";
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
