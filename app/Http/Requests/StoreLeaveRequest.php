<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Support\Workdays;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
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
                Rule::notIn([$this->user()?->id]),
                Rule::exists('users', 'id')->where('is_active', true)->whereIn(
                    'role_id',
                    Role::query()->whereIn('slug', [Role::APPROVER, Role::SUPER_ADMIN])->pluck('id')->all(),
                ),
            ],
            'relief_officer_id' => [
                'required',
                'integer',
                Rule::notIn([$this->user()?->id]),
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            'start_date' => ['required', 'date', 'after_or_equal:'.Carbon::now()->subYear()->toDateString()],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'leave_type_id.exists' => 'Pick a leave type that is still in use.',
            'supervisor_id.exists' => 'Pick someone who can approve leave.',
            'supervisor_id.not_in' => 'You cannot approve your own leave.',
            'supervisor_id.different' => 'Your relief officer cannot also be your approver.',
            'relief_officer_id.exists' => 'Pick a colleague who is still with the company.',
            'relief_officer_id.not_in' => 'Someone else has to cover your desk.',
            'end_date.after_or_equal' => 'The last day cannot fall before the first day.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->guardAgainstOverlap($validator);
            $this->guardAgainstNoWorkdays($validator);
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

    protected function guardAgainstOverlap(Validator $validator): void
    {
        $clash = LeaveRequest::query()
            ->where('user_id', $this->staff()->id)
            ->committed()
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
        $type = LeaveType::query()->find($this->integer('leave_type_id'));

        if ($type === null || ! $type->isCapped()) {
            return;
        }

        $balance = app(LeaveBalance::class)->forType(
            $this->staff(),
            $type,
            $this->startDate()->year,
        );

        if ($this->days() > $balance['remaining']) {
            $validator->errors()->add('leave_type_id', sprintf(
                'That is %d day(s) of %s but you have %d left this year.',
                $this->days(),
                $type->name,
                $balance['remaining'],
            ));
        }
    }
}
