<?php

namespace App\Models;

use App\Enums\AttemptResult;
use App\Enums\ClockType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $attendance_id
 * @property int|null $location_id
 * @property ClockType $type
 * @property AttemptResult $result
 * @property string|null $message
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int|null $accuracy_meters
 * @property int|null $distance_meters
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Location|null $location
 */
#[Fillable([
    'user_id',
    'attendance_id',
    'location_id',
    'type',
    'result',
    'message',
    'latitude',
    'longitude',
    'accuracy_meters',
    'distance_meters',
    'ip_address',
    'user_agent',
])]
class ClockAttempt extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ClockType::class,
            'result' => AttemptResult::class,
            'latitude' => 'float',
            'longitude' => 'float',
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
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @param  Builder<ClockAttempt>  $query
     */
    public function scopeRejected(Builder $query): void
    {
        $query->where('result', '!=', AttemptResult::Success->value);
    }
}
