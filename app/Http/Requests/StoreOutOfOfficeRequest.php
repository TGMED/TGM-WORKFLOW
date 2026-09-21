<?php

namespace App\Http\Requests;

use App\Enums\OutOfOfficeKind;
use App\Models\LeaveRequest;
use App\Models\OutOfOfficeRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Support\Workdays;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreOutOfOfficeRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(OutOfOfficeKind::class)],
            // Asked for a little ahead, but a fortnight back as well: a day
            // spent at a client site is often written up on the way home.
            'start_date' => ['required', 'date', 'after_or_equal:'.Carbon::now()->subDays(14)->toDateString()],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            // Where they will be, and how to reach them there. Only asked for
            // on an assignment: somebody working from home is reachable on the
            // numbers already on their record.
            'destination' => [
                Rule::requiredIf(fn (): bool => $this->kind()?->needsDestination() ?? false),
                'nullable',
                'string',
                'max:160',
            ],
            'contact_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.after_or_equal' => 'That is too far back to record now.',
            'end_date.after_or_equal' => 'The last day cannot fall before the first day.',
            'reason.min' => 'Give your approver enough detail to decide on.',
            'destination.required' => 'Say where the assignment takes you.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->guardAgainstNoWorkdays($validator);
            $this->guardAgainstOverlap($validator);
            $this->guardAgainstLeave($validator);
        });
    }

    /**
     * Working days the request covers, measured against the schedule of the
     * site this person works at.
     */
    public function days(): int
    {
        $workdays = $this->staff()->location?->workdayNumbers() ?? [1, 2, 3, 4, 5];

        return Workdays::countBetween(
            $this->startDate(),
            $this->endDate(),
            $workdays,
            PublicHoliday::datesBetween($this->startDate(), $this->endDate()),
        );
    }

    public function kind(): ?OutOfOfficeKind
    {
        return OutOfOfficeKind::tryFrom($this->string('kind')->toString());
    }

    public function startDate(): Carbon
    {
        return Carbon::parse($this->string('start_date')->toString())->startOfDay();
    }

    public function endDate(): Carbon
    {
        return Carbon::parse($this->string('end_date')->toString())->startOfDay();
    }

    /**
     * A stretch that lands entirely on days this site does not work is not a
     * request at all.
     */
    protected function guardAgainstNoWorkdays(Validator $validator): void
    {
        if ($this->days() < 1) {
            $validator->errors()->add(
                'start_date',
                'Those dates cover no working days at your site.',
            );
        }
    }

    protected function guardAgainstOverlap(Validator $validator): void
    {
        $clash = OutOfOfficeRequest::query()
            ->where('user_id', $this->staff()->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $this->endDate()->toDateString())
            ->where('end_date', '>=', $this->startDate()->toDateString())
            ->exists();

        if ($clash) {
            $validator->errors()->add(
                'start_date',
                'You already have a request covering some of those days.',
            );
        }
    }

    /**
     * Working elsewhere and being on leave are different claims about the same
     * day, and only one of them can be true.
     */
    protected function guardAgainstLeave(Validator $validator): void
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
                'You have leave booked over some of those days.',
            );
        }
    }

    protected function staff(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user->loadMissing('location');
    }
}
