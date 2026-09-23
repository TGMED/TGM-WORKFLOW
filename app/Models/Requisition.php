<?php

namespace App\Models;

use App\Enums\RequisitionStatus;
use App\Enums\RetirementStatus;
use Database\Factories\RequisitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Money asked for ahead of spending it.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $requester_id
 * @property int|null $department_id
 * @property string $title
 * @property string $purpose
 * @property string $amount
 * @property string $bank_code
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property RequisitionStatus $status
 * @property int|null $decided_by_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property int|null $paid_by_id
 * @property Carbon|null $paid_at
 * @property string|null $payment_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $requester
 * @property-read Department|null $department
 * @property-read User|null $decidedBy
 * @property-read User|null $paidBy
 * @property-read Retirement|null $retirement
 */
#[Fillable([
    'reference',
    'requester_id',
    'department_id',
    'title',
    'purpose',
    'amount',
    'bank_code',
    'bank_name',
    'account_number',
    'account_name',
    'status',
    'decided_by_id',
    'decided_at',
    'decision_note',
    'paid_by_id',
    'paid_at',
    'payment_reference',
])]
class Requisition extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<RequisitionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function booted(): void
    {
        // Numbered from the row's own id, so two raised at once can never be
        // handed the same reference.
        static::created(function (self $requisition): void {
            if ($requisition->reference === null) {
                $requisition->forceFill([
                    'reference' => 'REQ-'.str_pad((string) $requisition->id, 5, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RequisitionStatus::class,
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_id');
    }

    /**
     * @return HasOne<Retirement, $this>
     */
    public function retirement(): HasOne
    {
        return $this->hasOne(Retirement::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Whether the requester may account for it now: money was granted, and
     * there is no retirement yet, or finance sent the last one back.
     */
    public function awaitsRetirement(): bool
    {
        if (! $this->status->canBeRetired()) {
            return false;
        }

        return $this->retirement === null || $this->retirement->status === RetirementStatus::Queried;
    }
}
