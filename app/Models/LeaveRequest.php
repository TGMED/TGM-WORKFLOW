<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Concerns\HasApprovals;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time off asked for by a member of staff.
 *
 * @property int $id
 * @property int $user_id
 * @property int $leave_type_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $days
 * @property string|null $reason
 * @property RequestStatus $status
 * @property int $approvals_required
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read LeaveType $leaveType
 */
#[Fillable([
    'user_id',
    'leave_type_id',
    'start_date',
    'end_date',
    'days',
    'reason',
    'status',
    'approvals_required',
    'decided_at',
])]
class LeaveRequest extends Model implements Approvable
{
    use HasApprovals;

    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'status' => RequestStatus::class,
            'approvals_required' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Stored as bare `Y-m-d` so date comparisons line up on every driver.
     *
     * @return Attribute<Carbon, string>
     */
    protected function startDate(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (Carbon|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * @return Attribute<Carbon, string>
     */
    protected function endDate(): Attribute
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function module(): RequestModule
    {
        return RequestModule::Leave;
    }

    public function requester(): User
    {
        return $this->user;
    }

    public function summary(): string
    {
        return "{$this->days} day".($this->days === 1 ? '' : 's').
            " of {$this->leaveType->name} from ".$this->start_date->format('j M Y');
    }

    /**
     * Requests that count against an allowance: granted, or still in play.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeCommitted(Builder $query): void
    {
        $query->whereIn('status', [
            RequestStatus::Pending->value,
            RequestStatus::Approved->value,
        ]);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeInYear(Builder $query, int $year): void
    {
        $query->whereBetween('start_date', ["{$year}-01-01", "{$year}-12-31"]);
    }
}
