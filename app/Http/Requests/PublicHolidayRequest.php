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
            // Empty for a day every site is off, or the sites that keep it.
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', 'distinct', Rule::exists('locations', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_ids.*.exists' => 'Choose sites from the list.',
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
     * The sites that keep the holiday, none when every site does.
     *
     * @return list<int>
     */
    public function locationIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('location_ids', [])));
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
        $locationIds = $this->locationIds();

        $clash = PublicHoliday::query()
            ->with('locations:id,name')
            ->where('date', $this->date('date')?->toDateString())
            ->when($holiday !== null, fn ($query) => $query->whereKeyNot($holiday->id))
            ->when($locationIds !== [], fn ($query) => $query->observedAt($locationIds))
            ->first();

        if ($clash === null) {
            return;
        }

        // Only the sites the two have in common, when this one is not
        // company-wide, so the message names the overlap.
        $overlap = $clash->locations
            ->when($locationIds !== [], fn ($sites) => $sites->whereIn('id', $locationIds))
            ->pluck('name');
        $sites = $overlap->join(', ', ' and ');
        $has = $overlap->count() === 1 ? 'has' : 'have';

        $validator->errors()->add('date', match (true) {
            $clash->locations->isEmpty() => "Every site is already off that day for {$clash->name}.",
            $locationIds === [] => "{$sites} already {$has} {$clash->name} that day. Take it off before making the day company-wide.",
            default => "{$sites} already {$has} {$clash->name} that day.",
        });
    }
}
