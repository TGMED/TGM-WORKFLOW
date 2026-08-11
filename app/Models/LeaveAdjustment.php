<?php

namespace App\Models;

use Database\Factories\LeaveAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Days added to or taken off one person's allowance for one leave type in one
 * year: leave carried over, time off in lieu, a correction. They stack, and
 * nothing is ever overwritten, so the balance can always be explained.
 *
 * @property int $id
 * @property int $user_id
 * @property int $leave_type_id
 * @property int $year
 * @property int $days
 * @property string $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'leave_type_id', 'year', 'days', 'reason', 'created_by'])]
class LeaveAdjustment extends Model
{
    /** @use HasFactory<LeaveAdjustmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'integer',
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Signed for display: a grant reads as +3, a deduction as -2.
     */
    public function signedDays(): string
    {
        return ($this->days > 0 ? '+' : '').$this->days;
    }

    /**
     * @param  Builder<LeaveAdjustment>  $query
     */
    public function scopeInYear(Builder $query, int $year): void
    {
        $query->where('year', $year);
    }
}
