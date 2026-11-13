<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;

/**
 * Asks the person a leave request is about (and whoever filed it for them)
 * to add a document, such as a medical record, before it can be decided.
 * The request stays exactly where it was in the chain.
 */
class LeaveDocumentRequested extends TopicNotification
{
    public function __construct(protected LeaveRequest $request, protected string $askedBy, protected ?string $note) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::RequestDecided;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $mine = $notifiable instanceof User && $notifiable->id === $this->request->user_id;

        $mail = (new PanelMailMessage)
            ->subject($this->subject($mine))
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line($mine
                ? "{$this->askedBy} has asked you to add a document to your leave request before it is decided."
                : "{$this->askedBy} has asked for a document to be added to {$this->request->requester()->name}'s leave request before it is decided.");

        if ($this->note !== null && $this->note !== '') {
            $mail->line('Their note: “'.$this->note.'”');
        }

        return $mail
            ->panel($this->request->summary(), 'Open the request on your leave page and use "Add document". Anything already attached stays on file.')
            ->action('Add a document', $this->link('/leave'));
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subject($notifiable->id === $this->request->user_id),
            $this->request->summary(),
            $this->link('/leave'),
            ['module' => $this->request->module()->value],
        );
    }

    protected function subject(bool $mine): string
    {
        return $mine
            ? 'Please add a document to your leave request'
            : 'A document is needed on '.$this->request->requester()->name."'s leave";
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
