<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A supporting document on a requisition or a retirement: a quote, an
 * invoice, a receipt. Kept on the private disk and only ever served through
 * a controller that checks who is asking.
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $path
 * @property string $name
 * @property int|null $size
 * @property int|null $uploaded_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Requisition|Retirement $attachable
 */
#[Fillable(['path', 'name', 'size', 'uploaded_by_id'])]
class Attachment extends Model
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * The requisition this belongs to, directly or through its retirement.
     */
    public function requisition(): ?Requisition
    {
        $parent = $this->attachable;

        return $parent instanceof Retirement ? $parent->requisition : $parent;
    }
}
