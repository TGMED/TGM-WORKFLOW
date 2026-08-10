<?php

namespace App\Http\Requests;

use App\Models\LatenessRequest;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreLatenessRequest extends FormRequest
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
        $today = $this->today()->toDateString();

        return [
            // Lateness is explained on the day it happens: the reason is
            // freshest then, and it cannot be filed ahead of the morning.
            'work_date' => ['required', 'date', 'before_or_equal:'.$today, 'after_or_equal:'.$today],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_date.before_or_equal' => 'Lateness is explained on the day it happens.',
            'work_date.after_or_equal' => 'Lateness is explained on the day it happens.',
            'reason.min' => 'Give your approver enough detail to decide on.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('work_date')) {
                return;
            }

            $exists = LatenessRequest::query()
                ->where('user_id', $this->staff()->id)
                ->where('work_date', $this->workDate()->toDateString())
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'work_date',
                    'You have already explained that day.',
                );
            }
        });
    }

    public function workDate(): Carbon
    {
        return Carbon::parse($this->string('work_date')->toString())->startOfDay();
    }

    /**
     * Today at the site this person clocks in at, so someone in a different
     * timezone is not blocked from explaining their own morning.
     */
    protected function today(): Carbon
    {
        $timezone = $this->staff()->location->timezone ?? config('app.timezone');

        return Carbon::now()->setTimezone($timezone)->startOfDay();
    }

    protected function staff(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user->loadMissing('location');
    }
}
