<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\OutOfOfficeKind;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Concerns\BelongsToStaff;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\RoutesThroughTheLine;
use Database\Factories\OutOfOfficeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Days worked away from the office, either from home or out on company
 * business.
 *
 * Approving one does not take a day off anybody: it records that the work
 * happened elsewhere, which is what the roster and the attendance report need
 * to know before they call somebody missing.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $raised_by_id
 * @property int|null $team_lead_id
 * @property int|null $head_id
 * @property OutOfOfficeKind $kind
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $days
 * @property string $reason
 * @property string|null $destination
 * @property string|null $contact_number
 * @property RequestStatus $status
 * @property int $approvals_required
 * @property Carbon|null $decided_at
 * @property Carbon|null $escalated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User|null $raisedBy
 * @property-read User|null $teamLead
 * @property-read User|null $head
 */
#[Fillable([
    'user_id',
    'raised_by_id',
    'kind',
    'start_date',
    'end_date',
    'days',
    'reason',
    'destination',
    'contact_number',
    'status',
    'approvals_required',
    'decided_at',
])]
class OutOfOfficeRequest extends Model implements Approvable, AuditableContract
{
    use Auditable;
    use BelongsToStaff;
    use HasApprovals;

    /** @use HasFactory<OutOfOfficeRequestFactory> */
    use HasFactory;

    use RoutesThroughTheLine;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => OutOfOfficeKind::class,
            'days' => 'integer',
            'status' => RequestStatus::class,
            'approvals_required' => 'integer',
            'decided_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /**
     * Stored as bare `Y-m-d`, as leave is, so date comparisons line up on
     * every driver.
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
     * The chain is settled the moment the request is filed, in one place, so
     * no caller can forget it and no route can skip it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->stampReportingLine();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    /**
     * Requests approved and covering any part of the window, for the roster
     * and for the attendance report.
     *
     * @param  Builder<OutOfOfficeRequest>  $query
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString());
    }

    /**
     * @param  Builder<OutOfOfficeRequest>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', RequestStatus::Approved->value);
    }

    /**
     * Whether the requester may still change it: a returned one is theirs to
     * redo, and an untouched one can be tidied up.
     */
    public function isEditable(): bool
    {
        return $this->status === RequestStatus::Returned
            || ($this->status->isOpen() && $this->currentDecisions()->isEmpty());
    }

    public function module(): RequestModule
    {
        return RequestModule::OutOfOffice;
    }

    public function requester(): User
    {
        return $this->user;
    }

    public function summary(): string
    {
        return $this->kind->label().' for '.$this->days.' working day'.
            ($this->days === 1 ? '' : 's').' from '.$this->start_date->format('j M Y');
    }

    /**
     * @return array<string, string>
     */
    public function details(?User $viewer = null): array
    {
        $shared = $this->sharedDetails();

        return array_filter([
            'Requester' => $shared['Requester'],
            'Kind' => $this->kind->label(),
            'Dates' => $this->dateRange(),
            'Working days' => (string) $this->days,
            'Reason' => $this->reason,
            'Where' => $this->destination,
            'Reachable on' => $this->contact_number,
            'Team lead' => $this->name($this->teamLead, $viewer, 'None'),
            'Head of department' => $this->name($this->head, $viewer, 'None'),
            'Status' => $shared['Status'],
            'Filed' => $shared['Filed'],
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function standing(?User $viewer = null): string
    {
        return $this->settledStanding()
            ?? 'Waiting on '.$this->decider($viewer, $this->lineAwaitingUser(), 'an approver').' for a decision.';
    }

    public function nextStep(?User $viewer = null): ?string
    {
        if (! $this->requestStatus()->isOpen()) {
            return null;
        }

        $outstanding = $this->approvalsOutstanding();

        if ($outstanding < 1) {
            return null;
        }

        return $outstanding === 1
            ? 'One approval and the days are agreed.'
            : "It needs {$outstanding} more approvals before the days are agreed.";
    }

    /**
     * A single day reads as that day rather than as a range.
     */
    public function dateRange(): string
    {
        $start = $this->start_date->format('j M Y');

        return $this->start_date->isSameDay($this->end_date)
            ? $start
            : $start.' to '.$this->end_date->format('j M Y');
    }

    /**
     * Where the request has got to, for the requester's own list.
     */
    public function stageLabel(): string
    {
        if (! $this->requestStatus()->isOpen()) {
            return $this->status->label();
        }

        return $this->lineStageLabel() ?? 'Awaiting an approval';
    }
}
