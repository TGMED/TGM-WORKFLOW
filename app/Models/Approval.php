<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One approver's decision on one request. Leave and lateness share this
 * ledger, so the approval rules only have to be written once.
 *
 * @property int $id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property int $approver_id
 * @property int $step
 * @property int $round
 * @property ApprovalStage $stage
 * @property ApprovalDecision $decision
 * @property string|null $comment
 * @property Carbon $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $approver
 */
#[Fillable([
    'approvable_type',
    'approvable_id',
    'approver_id',
    'step',
    'round',
    'stage',
    'decision',
    'comment',
    'decided_at',
])]
class Approval extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stage' => 'approval',
        'round' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecision::class,
            'stage' => ApprovalStage::class,
            'decided_at' => 'datetime',
            'step' => 'integer',
            'round' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
