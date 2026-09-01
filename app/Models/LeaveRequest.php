<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Concerns\HasApprovals;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time off asked for by a member of staff.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $raised_by_id
 * @property int $leave_type_id
 * @property int|null $supervisor_id
 * @property int|null $relief_officer_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $days
 * @property string|null $reason
 * @property RequestStatus $status
 * @property int $approvals_required
 * @property int $round
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $raisedBy
 * @property-read LeaveType $leaveType
 * @property-read User|null $supervisor
 * @property-read User|null $reliefOfficer
 */
#[Fillable([
    'user_id',
    'raised_by_id',
    'leave_type_id',
    'supervisor_id',
    'relief_officer_id',
    'start_date',
    'end_date',
    'days',
    'reason',
    'status',
    'approvals_required',
    'round',
    'decided_at',
])]
class LeaveRequest extends Model implements Approvable
{
    use HasApprovals;

    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'status' => RequestStatus::class,
            'approvals_required' => 'integer',
            'round' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Stored as bare `Y-m-d` so date comparisons line up on every driver.
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The approver who filed this for the requester, when they did not file
     * it themselves.
     *
     * @return BelongsTo<User, $this>
     */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * The approver the requester named to rule on this.
     *
     * @return BelongsTo<User, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * The colleague covering the desk, who signs off before the supervisor
     * sees the request.
     *
     * @return BelongsTo<User, $this>
     */
    public function reliefOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relief_officer_id');
    }

    /**
     * Whether the cover has been agreed. Requests raised before relief
     * officers existed carry none, and skip the stage.
     */
    public function reliefAgreed(): bool
    {
        return $this->relief_officer_id === null || $this->currentDecisions()
            ->where('stage', ApprovalStage::Relief)
            ->where('decision', ApprovalDecision::Approved)
            ->isNotEmpty();
    }

    /**
     * The column carries a default of 1, which a row just created has not
     * read back yet, so a request in hand counts as being on its first round
     * until the database says otherwise.
     */
    public function currentRound(): int
    {
        return $this->round ?? 1;
    }

    /**
     * Whether the requester may still change the request. A returned one is
     * theirs to redo, and an untouched one can be tidied up. Once somebody has
     * had their say this round — the relief officer included — the details are
     * settled, and a change would move the ground under that decision.
     */
    public function isEditable(): bool
    {
        return $this->status === RequestStatus::Returned
            || ($this->status->isOpen() && $this->currentDecisions()->isEmpty());
    }

    /**
     * Whether a change to this request would put it back to the top of the
     * chain, asking everyone who saw the returned version to look again.
     */
    public function needsResubmitting(): bool
    {
        return $this->status === RequestStatus::Returned;
    }

    public function supervisorDecided(): bool
    {
        return $this->supervisor_id !== null
            && $this->currentDecisions()->contains('approver_id', $this->supervisor_id);
    }

    /**
     * The run is ordered: the relief officer signs off the cover, the named
     * supervisor rules on it, and only then may any other approver top up the
     * count when the module asks for more than one approval.
     */
    public function awaitsDecisionFrom(User $user): bool
    {
        if (! $this->requestStatus()->isOpen() || $this->user_id === $user->id) {
            return false;
        }

        if ($this->wasDecidedBy($user)) {
            return false;
        }

        if (! $this->reliefAgreed()) {
            return $this->relief_officer_id === $user->id;
        }

        if (! $user->canApprove()) {
            return false;
        }

        if ($this->supervisor_id !== null && ! $this->supervisorDecided()) {
            return $this->supervisor_id === $user->id;
        }

        return true;
    }

    public function approvalStageFor(User $user): ApprovalStage
    {
        return ! $this->reliefAgreed() && $this->relief_officer_id === $user->id
            ? ApprovalStage::Relief
            : ApprovalStage::Approval;
    }

    /**
     * Where the request sits right now, for the requester's own list.
     */
    public function stageLabel(): string
    {
        if (! $this->requestStatus()->isOpen()) {
            return $this->status->label();
        }

        if (! $this->reliefAgreed()) {
            return "With {$this->reliefOfficer->name} for cover";
        }

        if ($this->supervisor_id !== null && ! $this->supervisorDecided()) {
            return "With {$this->supervisor->name} for approval";
        }

        return 'Awaiting a further approval';
    }

    public function module(): RequestModule
    {
        return RequestModule::Leave;
    }

    public function requester(): User
    {
        return $this->user;
    }

    public function summary(): string
    {
        return "{$this->days} day".($this->days === 1 ? '' : 's').
            " of {$this->leaveType->name} from ".$this->start_date->format('j M Y');
    }

    /**
     * Requests that count against an allowance: granted, or still in play.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeCommitted(Builder $query): void
    {
        $query->whereIn('status', [
            RequestStatus::Pending->value,
            RequestStatus::Approved->value,
        ]);
    }

    /**
     * Requests that were granted.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', RequestStatus::Approved->value);
    }

    /**
     * Requests whose dates touch the range given, both ends inclusive.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): void
    {
        $query->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString());
    }

    /**
     * Cover this person has taken on: requests still in play where they are
     * the relief officer and have already agreed to it. A request they have
     * not answered yet is no obligation, so it does not count.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeCoveredBy(Builder $query, int $userId): void
    {
        $query->where('relief_officer_id', $userId)
            ->committed()
            ->whereHas('approvals', fn (Builder $approvals) => $approvals
                ->where('approver_id', $userId)
                ->where('stage', ApprovalStage::Relief->value)
                ->where('decision', ApprovalDecision::Approved->value)
                // Cover agreed on a version that was later sent back and
                // changed is no longer cover agreed on this one.
                ->whereColumn('approvals.round', 'leave_requests.round'));
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeInYear(Builder $query, int $year): void
    {
        $query->whereBetween('start_date', ["{$year}-01-01", "{$year}-12-31"]);
    }
}
