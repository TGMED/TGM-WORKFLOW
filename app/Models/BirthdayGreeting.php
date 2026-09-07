<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A greeting already sent. Written in the same breath as the send so a second
 * run of the command on the same day finds it and stays quiet.
 *
 * @property int $id
 * @property int $user_id
 * @property int $year
 * @property Carbon $sent_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'year', 'sent_at'])]
class BirthdayGreeting extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
