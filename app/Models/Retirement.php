<?php

namespace App\Models;

use App\Enums\RetirementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The account of what a requisition's money was spent on.
 *
 * @property int $id
 * @property int $requisition_id
 * @property string $amount_spent
 * @property string|null $notes
 * @property RetirementStatus $status
 * @property int|null $reviewed_by_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Requisition $requisition
 * @property-read User|null $reviewedBy
 */
#[Fillable(['requisition_id', 'amount_spent', 'notes', 'status', 'reviewed_by_id', 'reviewed_at', 'review_note'])]
class Retirement extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_spent' => 'decimal:2',
            'status' => RetirementStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Requisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * What is left over: positive is owed back to the company, negative is
     * owed to the person who spent it.
     */
    public function balance(): string
    {
        return number_format((float) $this->requisition->amount - (float) $this->amount_spent, 2, '.', '');
    }
}
