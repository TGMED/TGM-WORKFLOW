<?php

namespace App\Models;

use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A kind of leave staff can request. Annual and sick ship with the app; HR
 * adds the rest from the request settings page.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int|null $days_per_year
 * @property bool $is_paid
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name', 'description', 'days_per_year', 'is_paid', 'is_active'])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
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
     * A null allowance means the type is uncapped and no balance is enforced.
     */
    public function isCapped(): bool
    {
        return $this->days_per_year !== null;
    }

    /**
     * @param  Builder<LeaveType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
