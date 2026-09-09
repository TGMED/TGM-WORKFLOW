<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\Department;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;

/**
 * Tells somebody which part of the company they have been put in.
 *
 * Sent only to people who were not already in it: re-saving a department to
 * add one person should not write to everybody else who was already there.
 */
class AddedToDepartment extends TopicNotification
{
    public function __construct(protected Department $department) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::Department;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        return (new PanelMailMessage)
            ->subject("You have been added to {$this->department->name}")
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line("You are now part of {$this->department->name}.")
            ->panel($this->details(), $this->whoRunsIt())
            ->action('Open your dashboard', $this->link('/dashboard'))
            ->salutation('- The '.config('app.name').' team');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            "You have been added to {$this->department->name}",
            $this->whoRunsIt(),
            $this->link('/dashboard'),
            ['department_id' => (string) $this->department->id],
        );
    }

    protected function details(): string
    {
        $description = trim((string) $this->department->description);

        return $description === ''
            ? 'Department: '.$this->department->name
            : 'Department: '.$this->department->name.'. '.$description;
    }

    /**
     * A department without a head is a real state here, and saying so is more
     * use than leaving the line out and hoping nobody wonders.
     */
    protected function whoRunsIt(): string
    {
        $head = $this->department->head;

        return $head === null
            ? 'Nobody heads it yet. The people team will let you know once somebody does.'
            : "{$head->name} heads it, so your leave and lateness now go to them.";
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
