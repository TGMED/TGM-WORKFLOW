<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An anniversary note already sent. Written in the same breath as the send so
 * a second run of the command on the same day finds it and stays quiet.
 *
 * The number of years is kept alongside the year it was sent in, so the log
 * still reads correctly for someone whose hire date is corrected later.
 *
 * @property int $id
 * @property int $user_id
 * @property int $year
 * @property int $years_of_service
 * @property Carbon $sent_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'year', 'years_of_service', 'sent_at'])]
class WorkAnniversaryGreeting extends Model
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
            'years_of_service' => 'integer',
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
