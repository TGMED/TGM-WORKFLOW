<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One spell of somebody holding an asset. Open until it is handed back.
 *
 * @property int $id
 * @property int $asset_id
 * @property int $user_id
 * @property int|null $assigned_by_id
 * @property Carbon $assigned_at
 * @property Carbon|null $returned_at
 * @property string|null $note
 * @property-read Asset $asset
 * @property-read User $user
 * @property-read User|null $assignedBy
 */
#[Fillable(['asset_id', 'user_id', 'assigned_by_id', 'assigned_at', 'returned_at', 'note'])]
class AssetAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }
}
