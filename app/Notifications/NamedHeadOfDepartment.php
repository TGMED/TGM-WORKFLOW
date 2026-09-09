<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\Department;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;

/**
 * Tells somebody they now run a department.
 *
 * It comes with the role attached, so the mail says what the job has just
 * given them the run of rather than only naming it: the people in the
 * department, and the teams inside it, are what changed for them today.
 */
class NamedHeadOfDepartment extends TopicNotification
{
    public function __construct(protected Department $department) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::Department;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        return (new PanelMailMessage)
            ->subject("You are now head of {$this->department->name}")
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line("You have been named head of {$this->department->name}.")
            ->panel($this->standing(), $this->whatItGrants())
            ->action('Open the departments page', $this->link('/admin/departments'))
            ->line('If this looks wrong, the people team can hand it to somebody else.');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            "You are now head of {$this->department->name}",
            $this->standing(),
            $this->link('/admin/departments'),
            ['department_id' => (string) $this->department->id],
        );
    }

    /**
     * The size of what they have taken on, counted at the moment they were
     * told rather than described in the abstract.
     */
    protected function standing(): string
    {
        // Not counting themselves: they are the head of the rest, not of a
        // group they are one of.
        $people = $this->department->members()
            ->active()
            ->whereKeyNot($this->department->head_user_id ?? 0)
            ->count();

        $teams = $this->department->teams()->count();

        if ($people === 0) {
            return 'Nobody sits in it yet besides you.';
        }

        $parts = [$people === 1 ? '1 person' : "{$people} people"];

        if ($teams > 0) {
            $parts[] = $teams === 1 ? '1 team' : "{$teams} teams";
        }

        return 'You now have '.implode(' in ', $parts).'.';
    }

    protected function whatItGrants(): string
    {
        return 'The head of department role comes with it, so their leave and '
            .'lateness now reach you for a decision.';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
