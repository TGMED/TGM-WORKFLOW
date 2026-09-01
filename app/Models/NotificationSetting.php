<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's answer for one topic. Absence is consent: a topic with no row
 * is on, so a newly added topic reaches everybody until they say otherwise.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationTopic $topic
 * @property bool $email
 * @property bool $push
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'topic', 'email', 'push'])]
class NotificationSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'topic' => NotificationTopic::class,
            'email' => 'boolean',
            'push' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this person may be written to about this topic down this
     * channel. Topics marked required ignore the stored answer, and an
     * inactive account is never written to at all.
     */
    public static function allows(User $user, NotificationTopic $topic, NotificationChannel $channel): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($topic->isRequired()) {
            return true;
        }

        $setting = $user->relationLoaded('notificationSettings')
            ? $user->notificationSettings->firstWhere('topic', $topic)
            : self::query()->where('user_id', $user->id)->where('topic', $topic->value)->first();

        if ($setting === null) {
            return true;
        }

        return $channel === NotificationChannel::Email ? $setting->email : $setting->push;
    }

    /**
     * Every topic with this person's answer filled in, ready for the settings
     * page. Topics they have never touched come back as on.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function summaryFor(User $user): array
    {
        $stored = self::query()->where('user_id', $user->id)->get()->keyBy(
            fn (self $setting): string => $setting->topic->value,
        );

        return array_map(function (NotificationTopic $topic) use ($stored): array {
            $setting = $stored->get($topic->value);

            return [
                'topic' => $topic->value,
                'label' => $topic->label(),
                'description' => $topic->description(),
                'required' => $topic->isRequired(),
                // Required, never answered, or answered yes.
                'email' => $topic->isRequired() || $setting === null || $setting->email,
                'push' => $topic->isRequired() || $setting === null || $setting->push,
            ];
        }, NotificationTopic::cases());
    }
}
