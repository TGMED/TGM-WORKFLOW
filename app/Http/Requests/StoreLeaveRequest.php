<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\LeaveRequest;
use App\Models\LeaveRestrictedPeriod;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\LeaveEligibility;
use App\Support\Workdays;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    /** Memo for the several rules that read it; named so it cannot be mistaken for request input. */
    protected ?LeaveType $resolvedType = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')->where('is_active', true),
            ],
            // The supervisor must hold approval rights; the relief officer is
            // whoever covers the desk, so any active colleague will do.
            'supervisor_id' => [
                'required',
                'integer',
                'different:relief_officer_id',
                Rule::notIn($this->ineligibleApprovers()),
                Rule::exists('users', 'id')->where('is_active', true)->whereIn(
                    'role_id',
                    Role::idsWithPermission(Permission::ApproveRequests),
                ),
            ],
            'relief_officer_id' => [
                'required',
                'integer',
                Rule::notIn($this->ineligibleOfficers()),
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            'start_date' => ['required', 'date', 'after_or_equal:'.Carbon::now()->subYear()->toDateString()],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            // The sick paper or letter behind a type the policy will not take
            // on somebody's word. Only asked for where the type says so, and
            // not asked for twice when one is already on file.
            'evidence' => [
                // The form posts the field on every request, empty when there
                // is nothing to attach, so the type rules below have to be
                // told to sit out a null. Without this every booking of a type
                // that needs no paperwork is turned away for not being a file.
                // `required_if` is implicit and still fires, so a type that
                // does ask for evidence is no easier to get past.
                'nullable',
                Rule::requiredIf(fn (): bool => $this->needsEvidence()),
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:8192',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $dropped = $this->droppedUpload();

        return [
            'leave_type_id.exists' => 'Pick a leave type that is still in use.',
            'supervisor_id.exists' => 'Pick someone who can approve leave.',
            'supervisor_id.not_in' => 'You cannot approve your own leave.',
            'supervisor_id.different' => 'Your relief officer cannot also be your approver.',
            'relief_officer_id.exists' => 'Pick a colleague who is still with the company.',
            'relief_officer_id.not_in' => 'Someone else has to cover your desk.',
            'end_date.after_or_equal' => 'The last day cannot fall before the first day.',
            'evidence.required' => 'That type of leave has to come with supporting evidence.',
            'evidence.uploaded' => $dropped === null
                ? 'That file did not finish uploading. Attach it again.'
                : $this->droppedUploadMessage($dropped),
            'evidence.mimes' => 'Attach a PDF or an image of the document.',
            'evidence.max' => 'Keep the attachment under 8 MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->guardAgainstOverlap($validator);
            $this->guardAgainstRestrictedPeriod($validator);
            $this->guardAgainstNoWorkdays($validator);
            // Eligibility comes before the allowance: being told how many days
            // are left on a type you cannot take at all helps nobody.
            $this->guardAgainstIneligibility($validator);
            $this->guardAgainstAnExpiredEntitlement($validator);
            $this->guardAgainstAllowance($validator);
            $this->guardAgainstCoverAlreadyOwed($validator);
            $this->guardAgainstAnAbsentReliefOfficer($validator);
        });
    }

    /**
     * Working days the request actually covers, measured against the schedule
     * of the site this person works at.
     */
    public function days(): int
    {
        $workdays = $this->staff()->location?->workdayNumbers() ?? [1, 2, 3, 4, 5];

        return Workdays::countBetween($this->startDate(), $this->endDate(), $workdays);
    }

    public function startDate(): Carbon
    {
        return Carbon::parse($this->string('start_date')->toString())->startOfDay();
    }

    public function endDate(): Carbon
    {
        return Carbon::parse($this->string('end_date')->toString())->startOfDay();
    }

    protected function staff(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user->loadMissing('location');
    }

    /**
     * Who may not be named as the approver. Nobody rules on their own leave.
     *
     * @return array<int, int>
     */
    protected function ineligibleApprovers(): array
    {
        return [$this->staff()->id];
    }

    /**
     * Who may not be named as the relief officer. Somebody else has to cover
     * the desk of the person going away.
     *
     * @return array<int, int>
     */
    protected function ineligibleOfficers(): array
    {
        return [$this->staff()->id];
    }

    /**
     * The request being changed, when this is an edit rather than a fresh
     * booking. It has to sit out of its own clash and allowance checks.
     */
    protected function editing(): ?LeaveRequest
    {
        return null;
    }

    /**
     * Whether the periods the business has closed to leave apply here. They
     * apply to anybody booking their own time off; an approver filing for
     * someone else is the exception the restriction is meant to have.
     */
    protected function enforcesRestrictions(): bool
    {
        return true;
    }

    /**
     * Whether cover this person has already agreed to stands in the way of
     * the request. It does when they are booking their own time off, for the
     * same reason a closed period does.
     */
    protected function enforcesCoverOwed(): bool
    {
        return true;
    }

    /**
     * Leave cannot be booked over a period the business has closed, unless
     * the period lets this type of leave through, or lets this person through
     * on the marital status their profile carries.
     */
    protected function guardAgainstRestrictedPeriod(Validator $validator): void
    {
        if (! $this->enforcesRestrictions()) {
            return;
        }

        $type = $this->leaveType();

        $period = LeaveRestrictedPeriod::query()
            ->with('leaveTypes:id')
            ->overlapping($this->startDate(), $this->endDate())
            ->orderBy('start_date')
            ->get()
            ->first(fn (LeaveRestrictedPeriod $period): bool => ! ($type !== null && $period->allows($type))
                && ! $period->exempts($this->staff()));

        if ($period === null) {
            return;
        }

        $validator->errors()->add('start_date', $this->restrictionMessage($period));
    }

    /**
     * What a member of staff is told when a closed period turns their request
     * away. Somebody the period would have let through but for a blank
     * marital status is pointed at their profile rather than left guessing.
     */
    protected function restrictionMessage(LeaveRestrictedPeriod $period): string
    {
        $message = sprintf(
            'Leave is closed from %s for %s.',
            $period->rangeLabel(),
            $period->name,
        );

        if ($period->reason !== null) {
            $message .= ' '.$period->reason;
        }

        if ($period->exempt_marital_statuses !== [] && $this->staff()->profile?->marital_status === null) {
            $message .= ' Your profile does not record a marital status, and this period is open to '
                .$this->statusList($period->exempt_marital_statuses)
                .' staff. Set it on your profile if it applies to you.';
        }

        return $message;
    }

    /**
     * @param  array<int, string>  $statuses
     */
    protected function statusList(array $statuses): string
    {
        $statuses = array_map(fn (string $status): string => mb_strtolower($status), $statuses);

        if (count($statuses) === 1) {
            return $statuses[0];
        }

        $last = array_pop($statuses);

        return implode(', ', $statuses).' and '.$last;
    }

    protected function guardAgainstOverlap(Validator $validator): void
    {
        $clash = LeaveRequest::query()
            ->where('user_id', $this->staff()->id)
            ->committed()
            ->when($this->editing(), fn ($query, LeaveRequest $leave) => $query->whereKeyNot($leave->id))
            ->where('start_date', '<=', $this->endDate()->toDateString())
            ->where('end_date', '>=', $this->startDate()->toDateString())
            ->exists();

        if ($clash) {
            $validator->errors()->add(
                'start_date',
                'You already have leave booked or awaiting a decision over these dates.',
            );
        }
    }

    protected function guardAgainstNoWorkdays(Validator $validator): void
    {
        if ($this->days() === 0) {
            $validator->errors()->add(
                'start_date',
                'Those dates contain no working days for your site.',
            );
        }
    }

    /**
     * Somebody who has agreed to hold the fort for a colleague has to be there
     * to do it, so their own leave cannot land on those days.
     */
    protected function guardAgainstCoverAlreadyOwed(Validator $validator): void
    {
        if (! $this->enforcesCoverOwed()) {
            return;
        }

        $clash = LeaveRequest::query()
            ->with('user:id,name')
            ->coveredBy($this->staff()->id)
            ->overlapping($this->startDate(), $this->endDate())
            ->first();

        if ($clash !== null) {
            $validator->errors()->add('start_date', sprintf(
                'You are covering for %s from %s to %s. Hand that over before booking these days.',
                $clash->user->name,
                $clash->start_date->format('j M'),
                $clash->end_date->format('j M Y'),
            ));
        }
    }

    /**
     * A relief officer who is off themselves is no cover at all.
     */
    protected function guardAgainstAnAbsentReliefOfficer(Validator $validator): void
    {
        $away = LeaveRequest::query()
            ->where('user_id', $this->integer('relief_officer_id'))
            ->committed()
            ->overlapping($this->startDate(), $this->endDate())
            ->first();

        if ($away !== null) {
            $validator->errors()->add('relief_officer_id', sprintf(
                'They are away themselves from %s to %s. Pick someone who will be in.',
                $away->start_date->format('j M'),
                $away->end_date->format('j M Y'),
            ));
        }
    }

    protected function guardAgainstAllowance(Validator $validator): void
    {
        $type = $this->leaveType();

        if ($type === null || ! $type->isCappedFor($this->staff())) {
            return;
        }

        $balance = app(LeaveBalance::class)->forType(
            $this->staff(),
            $type,
            $this->startDate()->year,
            $this->editing()?->id,
        );

        if ($this->days() > $balance['remaining']) {
            $validator->errors()->add('leave_type_id', sprintf(
                'That is %d working day(s) of %s but you have %d left this year.',
                $this->days(),
                $type->name,
                $balance['remaining'],
            ));
        }
    }

    /**
     * The policy gates on the type: length of service, and confirmation in
     * post. Both are read from the same place the leave page reads them, so
     * nobody is offered a type here that the form had already greyed out.
     */
    protected function guardAgainstIneligibility(Validator $validator): void
    {
        $type = $this->leaveType();

        if ($type === null) {
            return;
        }

        $eligibility = app(LeaveEligibility::class)->check(
            $this->staff(),
            $type,
            $this->startDate(),
        );

        if (! $eligibility['eligible']) {
            $validator->errors()->add('leave_type_id', $eligibility['reason']);
        }
    }

    /**
     * Entitlement that lapses. Birthday leave is the policy's one case: it is
     * claimable for six months from the birthday, and both ends of the request
     * have to land inside that window.
     */
    protected function guardAgainstAnExpiredEntitlement(Validator $validator): void
    {
        $type = $this->leaveType();
        $window = $type?->windowFor($this->staff(), $this->startDate());

        if ($window === null) {
            return;
        }

        if ($this->startDate()->betweenIncluded($window['start'], $window['end'])
            && $this->endDate()->betweenIncluded($window['start'], $window['end'])) {
            return;
        }

        $validator->errors()->add('start_date', sprintf(
            '%s has to be taken within %d months of %s. Yours runs from %s to %s.',
            $type->name,
            $type->window_months,
            $type->anchor->label(),
            $window['start']->format('j M Y'),
            $window['end']->format('j M Y'),
        ));
    }

    /**
     * An attachment PHP threw away before Laravel could see it. It stays in
     * the file bag carrying an error code, while `hasFile` reports nothing
     * there at all, so on its own the form tells somebody who did attach a
     * document that they attached none.
     *
     * The commonest cause is a file over `upload_max_filesize`, which is a
     * lower ceiling than the 8 MB these rules allow whenever the two are set
     * apart -- and under `artisan serve` that is the CLI ini, not the one the
     * deployed site runs on.
     */
    protected function droppedUpload(): ?UploadedFile
    {
        $file = $this->files->get('evidence');

        return $file instanceof UploadedFile && ! $file->isValid() ? $file : null;
    }

    /**
     * What to tell somebody whose attachment did not survive the upload. The
     * server's own ceiling stands whatever these rules allow, so a file that
     * was too big is measured against that rather than against the size the
     * form advertises.
     */
    protected function droppedUploadMessage(UploadedFile $file): string
    {
        return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? sprintf(
                'That file is bigger than this server takes, which is %s. Attach a smaller one.',
                ini_get('upload_max_filesize'),
            )
            : 'That file did not finish uploading. Attach it again.';
    }

    /**
     * Whether an attachment has to come with this request. An edit that
     * already has one on file is not asked for it again.
     */
    protected function needsEvidence(): bool
    {
        return $this->leaveType()?->requires_evidence === true
            && $this->editing()?->evidence_path === null;
    }

    /**
     * The type being requested, looked up once for the several rules that
     * need it.
     */
    protected function leaveType(): ?LeaveType
    {
        return $this->resolvedType ??= LeaveType::query()->find($this->integer('leave_type_id'));
    }
}
