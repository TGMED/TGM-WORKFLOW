<?php

namespace App\Notifications;

use App\Contracts\Approvable;
use App\Enums\NotificationTopic;
use App\Models\User;
use App\Notifications\Concerns\DescribesRequest;
use App\Notifications\Messages\PanelMailMessage;
use App\Services\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Tells the people team, and whoever manages the approver sitting on it, that
 * a request has been waiting too long.
 *
 * Addressed to somebody who is not being asked to decide it: the point is that
 * a person is waiting and nobody has said anything, which is a management
 * problem rather than an approval one. The names of whoever it is stuck with
 * are given, because the first useful act is to go and ask them.
 */
class ApprovalOverdue extends TopicNotification
{
    use DescribesRequest;

    /**
     * @param  Approvable&Model  $request
     * @param  Collection<int, User>  $waitingOn  Whoever it is sitting with.
     */
    public function __construct(
        protected Approvable $request,
        protected Collection $waitingOn,
        protected int $hoursWaiting,
    ) {}

    public function topic(): NotificationTopic
    {
        return NotificationTopic::ApprovalOverdue;
    }

    public function toMail(object $notifiable): PanelMailMessage
    {
        $requester = $this->request->requester();

        $mail = (new PanelMailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$this->firstName($notifiable).',')
            ->line(sprintf(
                "%s's %s has been waiting %s for a decision.",
                $requester->name,
                $this->request->module()->noun(),
                $this->waitedFor(),
            ));

        if ($this->waitingOn->isNotEmpty()) {
            $mail->line('It is sitting with '.$this->names().'.');
        }

        return $this->describe($mail, $this->request, $notifiable)
            ->action('Look at the request', $this->link('/approvals'))
            ->line('Nothing is asked of you here beyond a nudge in the right direction.');
    }

    public function toFcm(User $notifiable): PushMessage
    {
        return PushMessage::make(
            $this->subject(),
            sprintf(
                '%s has waited %s. %s',
                $this->request->requester()->name,
                $this->waitedFor(),
                $this->request->summary(),
            ),
            $this->link('/approvals'),
            ['module' => $this->request->module()->value],
        );
    }

    protected function subject(): string
    {
        return ucfirst($this->request->module()->noun()).' has been waiting '.$this->waitedFor();
    }

    /**
     * Said in days once there are enough of them to say, since "72 hours" is a
     * sum the reader should not have to do.
     */
    protected function waitedFor(): string
    {
        if ($this->hoursWaiting < 48) {
            return $this->hoursWaiting.' hour'.($this->hoursWaiting === 1 ? '' : 's');
        }

        $days = intdiv($this->hoursWaiting, 24);

        return $days.' day'.($days === 1 ? '' : 's');
    }

    protected function names(): string
    {
        $names = $this->waitingOn->pluck('name')->all();

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    protected function firstName(object $notifiable): string
    {
        return $notifiable instanceof User
            ? explode(' ', trim($notifiable->name))[0]
            : 'there';
    }
}
