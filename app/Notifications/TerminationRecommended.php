<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\TerminationRecommendation;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;
use Illuminate\Support\HtmlString;

/**
 * Tells the people team that a senior member of staff has recommended letting
 * somebody go.
 *
 * Nothing is decided by this arriving. It is a case put to them, and the mail
 * says as much, because a note of this kind read in a hurry could be taken for
 * a decision already made.
 */
class TerminationRecommended extends TopicNotification
{
    public function __construct(protected TerminationRecommendation $recommendation) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TerminationRecommended;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $subject = $this->recommendation->subject;
        $raiser = $this->recommendation->raisedBy;

        $mail = (new PanelMailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line("{$raiser->name} has recommended that {$subject->name} be let go, and has put it to you to decide.");

        return $mail
            ->panel($this->details(), $this->standing(), 'Nothing happens to their record until you act on it.')
            ->action('Read the case', $this->link('/admin/recommendations'))
            ->line('No employment ends because of this note. The exit, if there is one, is still recorded by hand.');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subjectLine(),
            $this->recommendation->raisedBy->name.' has put a case to HR about '.
                $this->recommendation->subject->name.'.',
            $this->link('/admin/recommendations'),
        );
    }

    protected function subjectLine(): string
    {
        return 'A termination has been recommended to you';
    }

    protected function details(): HtmlString
    {
        $offence = $this->recommendation->offence;
        $sanction = $this->recommendation->policySanction();

        $rows = [
            '<strong>Who:</strong> '.e($this->recommendation->subject->name),
            '<strong>Raised by:</strong> '.e($this->recommendation->raisedBy->name),
            '<strong>Occurrence:</strong> '.e((string) $this->recommendation->occurrence),
        ];

        if ($offence !== null) {
            $rows[] = '<strong>Offence:</strong> '.e($offence->title);
        }

        if ($sanction !== null) {
            $rows[] = '<strong>What the policy says:</strong> '.e($sanction->action->label());
        }

        $rows[] = '<strong>Grounds:</strong> '.e($this->recommendation->grounds);

        return new HtmlString(implode("<br>\n", $rows));
    }

    /**
     * Said plainly, because the gap between what was asked for and what the
     * handbook provides for is the first thing worth noticing.
     */
    protected function standing(): string
    {
        if ($this->recommendation->offence === null) {
            return 'No offence from the register is cited, so there is no ladder to read this against.';
        }

        return $this->recommendation->policyAgrees()
            ? 'The policy reaches dismissal at this occurrence.'
            : 'The policy does not reach dismissal at this occurrence, so this asks for more than the handbook sets out.';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
