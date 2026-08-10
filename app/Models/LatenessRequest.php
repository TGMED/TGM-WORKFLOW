<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Concerns\HasApprovals;
use Database\Factories\LatenessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An explanation for arriving late on a given day. Approving one excuses the
 * lateness; the attendance record itself is left untouched.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $attendance_id
 * @property Carbon $work_date
 * @property int $minutes_late
 * @property string $reason
 * @property RequestStatus $status
 * @property int $approvals_required
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Attendance|null $attendance
 */
#[Fillable([
    'user_id',
    'attendance_id',
    'work_date',
    'minutes_late',
    'reason',
    'status',
    'approvals_required',
    'decided_at',
])]
class LatenessRequest extends Model implements Approvable
{
    use HasApprovals;

    /** @use HasFactory<LatenessRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes_late' => 'integer',
            'status' => RequestStatus::class,
            'approvals_required' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<Carbon, string>
     */
    protected function workDate(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (Carbon|string $value): string => Carbon::parse($value)->toDateString(),
        );
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

    public function module(): RequestModule
    {
        return RequestModule::Lateness;
    }

    public function requester(): User
    {
        return $this->user;
    }

    public function summary(): string
    {
        return "{$this->minutes_late} minutes late on ".$this->work_date->format('j M Y');
    }
}
