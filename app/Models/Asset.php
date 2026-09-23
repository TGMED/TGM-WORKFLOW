<?php

namespace App\Models;

use App\Enums\AssetCondition;
use App\Enums\AssetStatus;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One piece of company equipment.
 *
 * @property int $id
 * @property string $tag
 * @property string $name
 * @property int $asset_category_id
 * @property string|null $serial_number
 * @property int|null $location_id
 * @property string|null $spot
 * @property AssetStatus $status
 * @property AssetCondition $condition
 * @property Carbon|null $purchased_on
 * @property string|null $purchase_cost
 * @property string|null $notes
 * @property int|null $assigned_user_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AssetCategory $category
 * @property-read Location|null $location
 * @property-read User|null $assignee
 */
#[Fillable([
    'tag',
    'name',
    'asset_category_id',
    'serial_number',
    'location_id',
    'spot',
    'status',
    'condition',
    'purchased_on',
    'purchase_cost',
    'notes',
    'assigned_user_id',
    'assigned_at',
])]
class Asset extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'condition' => AssetCondition::class,
            'purchased_on' => 'date',
            'purchase_cost' => 'decimal:2',
            'assigned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<AssetAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }
}
