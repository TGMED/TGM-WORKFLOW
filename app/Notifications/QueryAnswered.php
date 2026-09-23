<?php

namespace App\Notifications;

use App\Enums\NotificationTopic;
use App\Models\StaffAction;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;
use Illuminate\Support\HtmlString;

/**
 * Somebody has answered the query they were issued.
 */
class QueryAnswered extends TopicNotification
{
    public function __construct(protected StaffAction $action) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::StaffAction;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        return (new PanelMailMessage)
            ->subject($this->subjectLine())
            ->greeting('Hello '.($notifiable instanceof User ? explode(' ', trim($notifiable->name))[0] : 'there').',')
            ->line("{$this->action->subject->name} has answered the query \"{$this->action->title}\".")
            ->panel(new HtmlString(nl2br(e((string) $this->action->response))))
            ->action('Read the record', $this->link('/admin/conduct'));
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make($this->subjectLine(), $this->action->title, $this->link('/admin/conduct'));
    }

    protected function subjectLine(): string
    {
        return "{$this->action->subject->name} has answered a query";
    }
}
