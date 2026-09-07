<?php

namespace App\Models;

use Database\Factories\LeaveRestrictedPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A stretch of the calendar closed to leave.
 *
 * Two ways through it: the marital statuses the period names may book anyway,
 * and the leave types it does not cover at all. Everyone else is turned away,
 * including anyone whose profile carries no marital status — an unset status
 * is not an exemption, or clearing it would be the way round the block.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $reason
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property array<int, string> $exempt_marital_statuses
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $author
 * @property-read Collection<int, LeaveType> $leaveTypes
 */
#[Fillable(['user_id', 'name', 'reason', 'start_date', 'end_date', 'exempt_marital_statuses'])]
class LeaveRestrictedPeriod extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<LeaveRestrictedPeriodFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'exempt_marital_statuses' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exempt_marital_statuses' => 'array',
        ];
    }

    /**
     * Stored as bare `Y-m-d`, matching leave requests, so the two compare
     * the same way on every driver.
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Types the restriction lets through. Sick leave is nobody's choice of
     * date, so a closed window should not stand in its way.
     *
     * @return BelongsToMany<LeaveType, $this>
     */
    public function leaveTypes(): BelongsToMany
    {
        return $this->belongsToMany(LeaveType::class);
    }

    /**
     * Whether this person may book over the period regardless, on the marital
     * status their profile carries.
     */
    public function exempts(User $user): bool
    {
        $status = $user->profile?->marital_status;

        return $status !== null && in_array($status, $this->exempt_marital_statuses, strict: true);
    }

    /**
     * Whether the period leaves this type of leave alone.
     */
    public function allows(LeaveType $type): bool
    {
        return $this->leaveTypes->contains('id', $type->id);
    }

    /**
     * The period as staff see it on a rejection.
     */
    public function rangeLabel(): string
    {
        return $this->start_date->format('j M Y').' to '.$this->end_date->format('j M Y');
    }

    /**
     * Periods whose days touch the range given, both ends inclusive.
     *
     * @param  Builder<LeaveRestrictedPeriod>  $query
     */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): void
    {
        $query->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString());
    }
}
