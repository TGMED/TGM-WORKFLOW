<?php

namespace App\Http\Requests;

use App\Models\LatenessRequest;
use App\Models\User;
use App\Services\LatenessWindow;
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
            // Lateness is raised on the day it falls on, and only that day.
            // How early in that day is the deadline's business, below.
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

            // Said before the deadline is: somebody who already filed is not
            // late with anything, and telling them the window shut would send
            // them looking for a problem they do not have.
            if ($exists) {
                $validator->errors()->add(
                    'work_date',
                    'You have already raised that day.',
                );

                return;
            }

            if ($this->enforcesDeadline() && ! $this->window()->isOpen($this->staff(), $this->workDate())) {
                $validator->errors()->add(
                    'work_date',
                    $this->missedDeadlineMessage(),
                );
            }
        });
    }

    /**
     * Whether the filing deadline applies. It does to a person filing for
     * themselves, which is the whole point of asking for notice.
     */
    protected function enforcesDeadline(): bool
    {
        return true;
    }

    /**
     * Said with the time in it: a deadline a person is told about only after
     * they have missed it should at least say when it was.
     */
    protected function missedDeadlineMessage(): string
    {
        $closed = $this->window()->closesAt($this->staff(), $this->workDate());

        return $closed === null
            ? 'Lateness is raised ahead of the start of work.'
            : sprintf(
                'Lateness is raised ahead of the start of work. Filing closed at %s today.',
                $closed->format('g:ia'),
            );
    }

    protected function window(): LatenessWindow
    {
        return app(LatenessWindow::class);
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
