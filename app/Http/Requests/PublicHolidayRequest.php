<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\PublicHoliday;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageLocations) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
            // Empty for a day every site is off, or the one site that keeps it.
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.exists' => 'Choose a site from the list.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->guardAgainstTwoOnOneDay($validator);
        });
    }

    /**
     * One holiday a day for any one site: two names for the same day would
     * count it off twice in nobody's favour, and read as a mistake. A
     * company-wide holiday already covers every site, so a site's own holiday
     * cannot sit on the same day as one, nor the other way round.
     */
    protected function guardAgainstTwoOnOneDay(Validator $validator): void
    {
        /** @var PublicHoliday|null $holiday */
        $holiday = $this->route('holiday');
        $locationId = $this->filled('location_id') ? $this->integer('location_id') : null;

        $clash = PublicHoliday::query()
            ->with('location:id,name')
            ->where('date', $this->date('date')?->toDateString())
            ->when($holiday !== null, fn ($query) => $query->whereKeyNot($holiday->id))
            ->when($locationId !== null, fn ($query) => $query->observedAt($locationId))
            ->first();

        if ($clash === null) {
            return;
        }

        $site = $clash->location->name ?? 'Another site';

        $validator->errors()->add('date', match (true) {
            $clash->location_id === null => "Every site is already off that day for {$clash->name}.",
            $locationId === null => "{$site} already has {$clash->name} that day. Take it off before making the day company-wide.",
            default => "{$site} already has {$clash->name} that day.",
        });
    }
}
