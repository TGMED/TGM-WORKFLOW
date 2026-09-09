<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Concerns\BelongsToStaff;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\RoutesThroughTheLine;
use Database\Factories\LatenessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An explanation for arriving late on a given day. Approving one excuses the
 * lateness; the attendance record itself is left untouched.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $raised_by_id
 * @property int|null $team_lead_id
 * @property int|null $head_id
 * @property int|null $attendance_id
 * @property Carbon $work_date
 * @property int $minutes_late
 * @property string $reason
 * @property RequestStatus $status
 * @property int $approvals_required
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User|null $raisedBy
 * @property-read Attendance|null $attendance
 */
#[Fillable([
    'user_id',
    'raised_by_id',
    'attendance_id',
    'work_date',
    'minutes_late',
    'reason',
    'status',
    'approvals_required',
    'decided_at',
])]
class LatenessRequest extends Model implements Approvable
{
    use BelongsToStaff;
    use HasApprovals;

    /** @use HasFactory<LatenessRequestFactory> */
    use HasFactory;

    use RoutesThroughTheLine;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes_late' => 'integer',
            'status' => RequestStatus::class,
            'approvals_required' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<Carbon, string>
     */
    protected function workDate(): Attribute
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
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function module(): RequestModule
    {
        return RequestModule::Lateness;
    }

    public function requester(): User
    {
        return $this->user;
    }

    public function summary(): string
    {
        return "{$this->minutes_late} minutes late on ".$this->work_date->format('j M Y');
    }

    /**
     * @return array<string, string>
     */
    public function details(?User $viewer = null): array
    {
        $shared = $this->sharedDetails();

        return [
            'Requester' => $shared['Requester'],
            'Date' => $this->work_date->format('j M Y'),
            'Minutes late' => (string) $this->minutes_late,
            'Reason' => blank($this->reason) ? 'Not given' : $this->reason,
            'Team lead' => $this->name($this->teamLead, $viewer, 'None'),
            'Head of department' => $this->name($this->head, $viewer, 'None'),
            'Status' => $shared['Status'],
            'Filed' => $shared['Filed'],
        ];
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
            ? 'One approval and the lateness is excused.'
            : "It needs {$outstanding} more approvals before the lateness is excused.";
    }
}
