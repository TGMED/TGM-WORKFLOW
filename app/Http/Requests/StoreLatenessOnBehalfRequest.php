<?php

namespace App\Http\Requests;

use Illuminate\Support\Carbon;

/**
 * A lateness explanation filed by an approver for a member of staff.
 *
 * Staff explain themselves on the day, which is the point of the exercise. An
 * approver is usually writing up something from earlier in the week, so this
 * reaches back a fortnight instead.
 */
class StoreLatenessOnBehalfRequest extends StoreLatenessRequest
{
    use RaisesOnBehalf;

    private const REACHES_BACK_DAYS = 14;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = $this->today();

        return [
            'staff_id' => $this->staffRule(),
            'work_date' => [
                'required',
                'date',
                'before_or_equal:'.$today->toDateString(),
                'after_or_equal:'.$today->copy()->subDays(self::REACHES_BACK_DAYS)->toDateString(),
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'staff_id.exists' => 'Pick a member of staff who is still with the company.',
            'staff_id.not_in' => 'File your own lateness from the lateness page.',
            'work_date.before_or_equal' => 'Lateness cannot be explained ahead of the day.',
            'work_date.after_or_equal' => 'That day is too far back to explain now.',
        ];
    }

    /**
     * Today at the site the member of staff works at, since it is their
     * morning being explained rather than the approver's.
     */
    protected function today(): Carbon
    {
        $timezone = $this->staff()->location->timezone ?? config('app.timezone');

        return Carbon::now()->setTimezone($timezone)->startOfDay();
    }
}
