<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\TerminationRecommendation;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;

/**
 * Tells whoever raised a recommendation what the people team decided.
 */
class RecommendationDecided extends TopicNotification
{
    public function __construct(protected TerminationRecommendation $recommendation) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TerminationRecommended;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $decider = $this->recommendation->decidedBy?->name;

        $mail = (new PanelMailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line(sprintf(
                'Your recommendation about %s has been %s by %s.',
                $this->recommendation->subject->name,
                strtolower($this->recommendation->status->label()),
                $decider ?? 'the people team',
            ));

        if ($this->recommendation->hr_note !== null) {
            $mail->line('"'.$this->recommendation->hr_note.'"');
        }

        return $mail->action('See the case', $this->link('/recommendations'));
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subjectLine(),
            'Your recommendation about '.$this->recommendation->subject->name.
                ' was '.strtolower($this->recommendation->status->label()).'.',
            $this->link('/recommendations'),
        );
    }

    protected function subjectLine(): string
    {
        return 'HR has answered your recommendation';
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
