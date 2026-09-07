<?php

namespace App\Models;

use App\Enums\LeaveAnchor;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A kind of leave staff can request, together with the policy rules that
 * govern who may take it. The rules are columns rather than code so HR can
 * change one from the request settings page without a deploy.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int|null $days_per_year
 * @property int|null $days_per_year_manager
 * @property int $min_service_months
 * @property bool $requires_confirmed
 * @property bool $requires_evidence
 * @property LeaveAnchor|null $anchor
 * @property int|null $window_months
 * @property bool $is_paid
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'slug',
    'name',
    'description',
    'days_per_year',
    'days_per_year_manager',
    'min_service_months',
    'requires_confirmed',
    'requires_evidence',
    'anchor',
    'window_months',
    'is_paid',
    'is_active',
])]
class LeaveType extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'min_service_months' => 0,
        'requires_confirmed' => false,
        'requires_evidence' => false,
        'is_paid' => true,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_per_year' => 'integer',
            'days_per_year_manager' => 'integer',
            'min_service_months' => 'integer',
            'requires_confirmed' => 'boolean',
            'requires_evidence' => 'boolean',
            'anchor' => LeaveAnchor::class,
            'window_months' => 'integer',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * The yearly allowance this person draws. Managers and above take the
     * separate figure where the type sets one; everybody else, and every type
     * that does not, falls back to the single company allowance.
     */
    public function allowanceFor(User $user): ?int
    {
        return $this->days_per_year_manager !== null && $user->isManagerOrAbove()
            ? $this->days_per_year_manager
            : $this->days_per_year;
    }

    /**
     * A null allowance means the type is uncapped and no balance is enforced.
     */
    public function isCapped(): bool
    {
        return $this->days_per_year !== null || $this->days_per_year_manager !== null;
    }

    public function isCappedFor(User $user): bool
    {
        return $this->allowanceFor($user) !== null;
    }

    /**
     * Whether the entitlement lapses rather than running the calendar year.
     */
    public function expires(): bool
    {
        return $this->anchor !== null && $this->window_months !== null;
    }

    /**
     * The days this person may currently book the type over: from the last
     * anchor date to the end of its window. Null when the type does not
     * expire, or when the employee record does not carry the date the window
     * hangs off — an unenforceable rule is left open rather than closed.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    public function windowFor(User $user, ?Carbon $on = null): ?array
    {
        if (! $this->expires()) {
            return null;
        }

        $anchor = $this->anchor?->lastOccurrenceFor($user, $on ?? Carbon::now()->startOfDay());

        if ($anchor === null) {
            return null;
        }

        return [
            'start' => $anchor,
            'end' => $anchor->copy()->addMonths($this->window_months)->subDay(),
        ];
    }

    /**
     * Months of service the policy asks for, phrased for a person being told
     * they are short of it.
     */
    public function serviceRequirement(): string
    {
        return $this->min_service_months % 12 === 0
            ? ($this->min_service_months / 12).' year'.($this->min_service_months === 12 ? '' : 's')
            : $this->min_service_months.' months';
    }

    /**
     * @param  Builder<LeaveType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
