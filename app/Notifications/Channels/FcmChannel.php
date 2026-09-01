<?php

namespace App\Notifications\Channels;

use App\Models\PushToken;
use App\Models\User;
use App\Services\Push\FcmClient;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Delivers a notification to every browser the recipient has allowed
 * notifications in. A notification opts in by implementing `toFcm()`.
 */
class FcmChannel
{
    public function __construct(protected FcmClient $client) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toFcm')) {
            return;
        }

        $message = $notification->toFcm($notifiable);

        if (! $message instanceof PushMessage) {
            return;
        }

        $tokens = PushToken::query()->where('user_id', $notifiable->id)->get();

        foreach ($tokens as $token) {
            if ($this->client->send($token->token, $message)) {
                $token->forceFill(['last_used_at' => Carbon::now()])->save();

                continue;
            }

            // FCM has told us this browser is gone. Keeping the token would
            // only mean sending into the void every time.
            $token->delete();
        }
    }
}
