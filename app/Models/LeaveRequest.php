<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Concerns\BelongsToStaff;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\RoutesThroughTheLine;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Time off asked for by a member of staff.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $raised_by_id
 * @property int|null $team_lead_id
 * @property int|null $head_id
 * @property int $leave_type_id
 * @property int|null $supervisor_id
 * @property int|null $relief_officer_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $days
 * @property string|null $reason
 * @property string|null $evidence_path
 * @property string|null $evidence_name
 * @property RequestStatus $status
 * @property int $approvals_required
 * @property int $round
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User|null $raisedBy
 * @property-read LeaveType $leaveType
 * @property-read User|null $supervisor
 * @property-read User|null $reliefOfficer
 * @property-read User|null $teamLead
 * @property-read User|null $head
 * @property-read Collection<int, LeaveDocument> $documents
 * @property int|null $documents_count
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
    'evidence_path',
    'evidence_name',
    'status',
    'approvals_required',
    'round',
    'decided_at',
])]
class LeaveRequest extends Model implements Approvable, AuditableContract
{
    use Auditable;
    use BelongsToStaff;
    use HasApprovals;

    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    use RoutesThroughTheLine;
    use SoftDeletes;

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
     * Documents added after booking, oldest first. The one attached at
     * booking stays on the request itself.
     *
     * @return HasMany<LeaveDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(LeaveDocument::class)->orderBy('id');
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
     * The run is ordered: the relief officer signs off the cover, then the
     * people responsible for the requester have their say — team lead first,
     * then head of department — then the named supervisor, and only after all
     * of that may any other approver top up the count when the module asks for
     * more than one approval.
     *
     * Each stage falls away when nobody fills it, so somebody in no team and
     * no department reaches the supervisor exactly as they did before.
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

        if (($awaiting = $this->lineAwaiting()) !== null) {
            return $awaiting === $user->id;
        }

        if ($this->supervisor_id !== null && ! $this->supervisorDecided()) {
            return $this->supervisor_id === $user->id;
        }

        // No approver was named (older requests): any company-wide approver
        // takes the manager's turn.
        if (! $this->managerApproved()) {
            return $user->approvesCompanyWide();
        }

        // Last of all, a super admin has the final say. Until one of them
        // approves, the leave is not granted, whatever else has happened.
        if (! $this->adminSignedOff()) {
            return $user->isSuperAdmin();
        }

        // See HasApprovals::awaitsDecisionFrom(): a head or lead approves for
        // their own people, not for whoever is left over.
        return $user->approvesCompanyWide();
    }

    /**
     * Whether a super admin has given the final approval this round.
     */
    public function adminSignedOff(): bool
    {
        return $this->currentDecisions()
            ->where('decision', ApprovalDecision::Approved)
            ->contains('stage', ApprovalStage::Admin);
    }

    /**
     * Whether the manager's approval is in: the approver the requester named,
     * or, where nobody was named, any approval past the cover.
     */
    public function managerApproved(): bool
    {
        if ($this->supervisor_id !== null) {
            return $this->supervisorDecided();
        }

        return $this->currentDecisions()
            ->where('decision', ApprovalDecision::Approved)
            ->whereIn('stage', [ApprovalStage::Approval, ApprovalStage::Admin])
            ->isNotEmpty();
    }

    /**
     * Whether everyone before the super admin has had their say — the cover,
     * the reporting line and the manager — and the request now waits only on
     * the final approval.
     */
    public function awaitsAdmin(): bool
    {
        return $this->requestStatus()->isOpen()
            && $this->reliefAgreed()
            && $this->lineAwaiting() === null
            && $this->managerApproved()
            && ! $this->adminSignedOff();
    }

    /**
     * Approvers on leave are shown by their office — their job title — rather
     * than their name, everywhere a request is displayed. The cover is a
     * colleague agreeing to cover, not an approver, and keeps their name. The
     * audit trail still records the actual person behind every decision.
     */
    public static function officeOf(?User $person, string $fallback): string
    {
        $title = trim((string) $person?->position);

        // No job title on file: fall back to the person's name.
        return $title !== '' ? $title : ($person->name ?? $fallback);
    }

    /**
     * An office as it reads mid-sentence: "the Operations Manager" for a
     * title, but a bare name where there is no title to fall back on.
     */
    public static function officePhrase(?User $person, string $fallback): string
    {
        if ($person === null) {
            return $fallback;
        }

        return trim((string) $person->position) !== ''
            ? 'the '.trim((string) $person->position)
            : $person->name;
    }

    /**
     * How the final approval is signed, whichever super admin gives it.
     */
    public static function finalApproverTitle(): string
    {
        return (string) config('hr.final_approver_title', 'Chairman');
    }

    public function supervisorOffice(): ?string
    {
        return $this->supervisor === null ? null : self::officeOf($this->supervisor, 'Approver');
    }

    public function teamLeadOffice(): ?string
    {
        return $this->teamLead === null ? null : self::officeOf($this->teamLead, 'Team lead');
    }

    public function headOffice(): ?string
    {
        return $this->head === null ? null : self::officeOf($this->head, 'Head of department');
    }

    /**
     * Who took a decision, as the request displays it: the cover by name, the
     * final approval as the Chairman, and every other approver by office.
     */
    public function approverLabel(Approval $approval): string
    {
        return match ($approval->stage) {
            ApprovalStage::Relief => $approval->approver->name,
            ApprovalStage::Admin => self::finalApproverTitle(),
            default => self::officeOf($approval->approver, match ($approval->approver_id) {
                $this->team_lead_id => 'Team lead',
                $this->head_id => 'Head of department',
                default => 'Approver',
            }),
        };
    }

    /**
     * The stage as the trail names it. The final approval reads as that
     * rather than as "administrator".
     */
    public function stageLabelFor(Approval $approval): string
    {
        return $approval->stage === ApprovalStage::Admin
            ? 'Final approval'
            : $approval->stage->label();
    }

    /**
     * An office for use inside a sentence: "the Operations Manager", or "you"
     * when the reader is the one it is waiting on.
     */
    protected function officeInSentence(?User $viewer, ?User $person, string $fallback, bool $turnIsNow = true): string
    {
        if ($viewer !== null && $person !== null && ($turnIsNow ? $this->awaitsDecisionFrom($viewer) : $viewer->id === $person->id)) {
            return 'you';
        }

        return self::officePhrase($person, $fallback);
    }

    /**
     * Leave passes the reporting line, then the manager, then a super admin.
     * Each is a gate: none settles the request alone.
     */
    protected function approvalGatesFinished(): bool
    {
        return $this->lineFinished()
            && $this->managerApproved()
            && $this->adminSignedOff();
    }

    /**
     * The relief officer agrees cover; a super admin taking the final
     * decision does so as administrator. When the named approver is a super
     * admin themselves, their approval is the final one: asking them twice, or
     * waiting on a second super admin who may not exist, would only stall it.
     */
    public function approvalStageFor(User $user): ApprovalStage
    {
        if (! $this->reliefAgreed() && $this->relief_officer_id === $user->id) {
            return ApprovalStage::Relief;
        }

        // A super admin taking the manager's turn — as the named approver, or
        // with nobody named — gives the final approval in the same click.
        $finalTurn = $this->reliefAgreed()
            && $this->lineAwaiting() === null
            && ! $this->adminSignedOff()
            && ($this->managerApproved()
                || $this->supervisor_id === null
                || $this->supervisor_id === $user->id);

        return $finalTurn && $user->isSuperAdmin()
            ? ApprovalStage::Admin
            : ApprovalStage::Approval;
    }

    /**
     * A decline at any point sends the leave back to the person who asked for
     * it, rather than ending it.
     *
     * The chain has several people in it and a decline usually means something
     * about the request is wrong — the dates, the cover, the reason — rather
     * than that the answer is permanently no. Sending it back lets the
     * requester fix it and put it round again, and the round they were on
     * stays on the trail with the reason it came back.
     *
     * The effect is that leave has no terminal refusal. A request that should
     * not go ahead is withdrawn by the requester, which is a decision with a
     * name against it rather than one that leaves them nothing to do.
     */
    public function statusAfterRejection(ApprovalStage $stage): RequestStatus
    {
        return RequestStatus::Returned;
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

        if (($line = $this->lineStageLabel()) !== null) {
            return $line;
        }

        if ($this->supervisor_id !== null && ! $this->supervisorDecided()) {
            return 'With '.self::officePhrase($this->supervisor, 'an approver').' for approval';
        }

        if (! $this->adminSignedOff()) {
            return 'With the '.self::finalApproverTitle().' for final approval';
        }

        return 'Awaiting a further approval';
    }

    /**
     * The reporting line's stage, by office rather than by name.
     */
    public function lineStageLabel(): ?string
    {
        if ($this->lineAwaiting() === null) {
            return null;
        }

        return $this->lineAwaiting() === $this->team_lead_id
            ? 'With '.self::officePhrase($this->teamLead, 'your team lead').', your team lead'
            : 'With '.self::officePhrase($this->head, 'your head of department').', your head of department';
    }

    /**
     * Whether the supporting document the policy asks for is on file.
     */
    public function hasEvidence(): bool
    {
        return $this->evidence_path !== null;
    }

    /**
     * Who may open the attachment: the person it is about, whoever filed it
     * for them, anyone the request is waiting on or has already been ruled on
     * by, and super admins. Medical and bereavement paperwork goes no wider.
     */
    public function evidenceVisibleTo(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->raised_by_id === $user->id
            || $this->awaitsDecisionFrom($user)
            || $this->wasDecidedBy($user)
            // Super admins give the final approval, and may ask for a record
            // before their turn comes, so they need to be able to read it.
            || $user->isSuperAdmin();
    }

    /**
     * Whether any document is on file: the one attached at booking, or one
     * added since.
     */
    public function hasAnyDocument(): bool
    {
        return $this->hasEvidence() || $this->documents()->exists();
    }

    /**
     * Documents can be added to sick leave (the type that takes paperwork)
     * while it is still open or sent back, so a medical record can follow a
     * photo of the medicine. Once the
     * request is settled, the paperwork behind it is too.
     */
    public function acceptsDocuments(): bool
    {
        // Only the types that take paperwork at all (sick leave); every other
        // type stays as it was booked.
        return $this->leaveType->requires_evidence === true
            && ($this->status === RequestStatus::Pending
                || $this->status === RequestStatus::Returned);
    }

    /**
     * Only the person the leave is for may add a document. The cover,
     * approvers and super admins can read them and ask for one, but never
     * attach paperwork on the requester's behalf. Adding one changes nothing
     * about where the request has reached.
     */
    public function documentsAddableBy(User $user): bool
    {
        return $this->acceptsDocuments() && $this->user_id === $user->id;
    }

    /**
     * An approver the request is waiting on, or any super admin, may ask for
     * a document while it is still pending. The cover only agrees to cover,
     * so is not one to ask; nor does anybody ask themselves.
     */
    public function mayAskForDocumentFrom(User $user): bool
    {
        if ($this->status !== RequestStatus::Pending
            || $this->leaveType->requires_evidence !== true
            || $this->user_id === $user->id) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->awaitsDecisionFrom($user)
            && $this->approvalStageFor($user) !== ApprovalStage::Relief;
    }

    /**
     * Every document on the request, the booking one first, ready for a page.
     *
     * @return array<int, array{id: int|null, name: string, url: string, added_by: string|null, added_at: string|null, at_booking: bool}>
     */
    public function documentListing(): array
    {
        $rows = [];

        if ($this->hasEvidence()) {
            $rows[] = [
                'id' => null,
                'name' => $this->evidence_name ?? 'Document',
                'url' => route('leave.evidence', $this),
                'added_by' => null,
                'added_at' => $this->created_at?->toIso8601String(),
                'at_booking' => true,
            ];
        }

        foreach ($this->documents as $document) {
            $rows[] = [
                'id' => $document->id,
                'name' => $document->name,
                'url' => route('leave.documents.show', [$this, $document]),
                'added_by' => $document->uploadedBy?->name,
                'added_at' => $document->created_at?->toIso8601String(),
                'at_booking' => false,
            ];
        }

        return $rows;
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
        return "{$this->days} working day".($this->days === 1 ? '' : 's').
            " of {$this->leaveType->name} from ".$this->start_date->format('j M Y');
    }

    /**
     * @return array<string, string>
     */
    public function details(?User $viewer = null): array
    {
        $shared = $this->sharedDetails();

        return [
            'Requester' => $shared['Requester'],
            'Leave type' => $this->leaveType->name,
            'Dates' => $this->dateRange(),
            'Working days' => (string) $this->days,
            'Reason' => blank($this->reason) ? 'Not given' : $this->reason,
            'Relief officer' => $this->name($this->reliefOfficer, $viewer, 'None named'),
            'Team lead' => $this->officeWithYou($this->teamLead, $this->teamLeadOffice(), $viewer, 'None'),
            'Head of department' => $this->officeWithYou($this->head, $this->headOffice(), $viewer, 'None'),
            'Approver' => $this->officeWithYou($this->supervisor, $this->supervisorOffice(), $viewer, 'Any approver'),
            'Final approval' => self::finalApproverTitle(),
            'Status' => $shared['Status'],
            'Filed' => $shared['Filed'],
        ];
    }

    /**
     * An office on the details block, marked where the reader holds it.
     */
    protected function officeWithYou(?User $person, ?string $office, ?User $viewer, string $fallback): string
    {
        if ($person === null || $office === null) {
            return $fallback;
        }

        return $viewer !== null && $viewer->id === $person->id ? "{$office} (you)" : $office;
    }

    public function standing(?User $viewer = null): string
    {
        if (($settled = $this->settledStanding()) !== null) {
            return $settled;
        }

        if (! $this->reliefAgreed()) {
            return 'Waiting on '.$this->decider($viewer, $this->reliefOfficer, 'a relief officer').' to agree cover.';
        }

        if (($waiting = $this->lineAwaitingUser()) !== null) {
            return 'Waiting on '.$this->officeInSentence($viewer, $waiting, 'an approver').' for a decision.';
        }

        if ($this->awaitsAdmin()) {
            return $viewer !== null && $this->awaitsDecisionFrom($viewer)
                ? 'Waiting on you for the final approval.'
                : 'Waiting on the '.self::finalApproverTitle().' for the final approval.';
        }

        return 'Waiting on '.$this->officeInSentence($viewer, $this->supervisor, 'an approver').' for a decision.';
    }

    /**
     * Who approves first once the cover is agreed. The cover's own sign-off
     * also settles their place in the reporting line, so a team lead or head
     * who is covering is passed over rather than named as up next.
     */
    protected function firstApproverAfterCover(): ?User
    {
        $relief = $this->relief_officer_id;

        if ($this->team_lead_id !== null && $this->team_lead_id !== $relief && ! $this->teamLeadDecided()) {
            return $this->teamLead;
        }

        if ($this->head_id !== null && $this->head_id !== $relief && ! $this->headDecided()) {
            return $this->head;
        }

        return $this->supervisor_id !== null && $this->supervisor_id !== $relief ? $this->supervisor : null;
    }

    public function nextStep(?User $viewer = null): ?string
    {
        if (! $this->requestStatus()->isOpen()) {
            return null;
        }

        $final = 'the '.self::finalApproverTitle();

        if (! $this->reliefAgreed()) {
            return 'After that it goes to '.$this->officeInSentence(
                $viewer,
                $this->firstApproverAfterCover(),
                'an approver',
                turnIsNow: false,
            ).' for approval, then '.$final.' gives the final approval.';
        }

        $outstanding = $this->approvalsOutstanding();

        if ($outstanding < 1) {
            return null;
        }

        if (! $this->adminSignedOff()) {
            if ($this->awaitsAdmin()) {
                // The person reading is the final approver: the standing line
                // already says it is waiting on them, so there is nothing to add.
                return $viewer !== null && $this->awaitsDecisionFrom($viewer)
                    ? null
                    : ucfirst($final).' gives the final approval.';
            }

            // Still with somebody before the final approval. When that is the
            // reader, "after that" follows on from "waiting on you"; for anyone
            // else, say who it is with now.
            $now = $this->lineAwaitingUser() ?? $this->supervisor;

            return $now === null || ($viewer !== null && $this->awaitsDecisionFrom($viewer))
                ? 'After that, '.$final.' gives the final approval.'
                : 'It is with '.$this->officeInSentence($viewer, $now, 'an approver').' for approval, then '.$final.' gives the final approval.';
        }

        return $outstanding === 1
            ? 'One approval and the leave is granted.'
            : "It needs {$outstanding} more approvals before the leave is granted.";
    }

    /**
     * Leave of a single day reads as that day rather than as a range.
     */
    protected function dateRange(): string
    {
        $start = $this->start_date->format('j M Y');

        return $this->start_date->isSameDay($this->end_date)
            ? $start
            : $start.' - '.$this->end_date->format('j M Y');
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
