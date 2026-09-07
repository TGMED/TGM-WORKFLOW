<?php

namespace App\Http\Requests;

use App\Enums\LeaveAnchor;
use App\Enums\Permission;
use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageRequestSettings) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var LeaveType|null $type */
        $type = $this->route('leaveType');

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('leave_types', 'name')->ignore($type?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            // Null means uncapped: the type is available without a yearly
            // allowance being counted against it.
            'days_per_year' => ['nullable', 'integer', 'between:1,365'],
            // The separate figure for managers and above. Null means one
            // allowance for the whole company.
            'days_per_year_manager' => ['nullable', 'integer', 'between:1,365'],

            // The policy gates: service served, and confirmation in post.
            'min_service_months' => ['nullable', 'integer', 'between:0,120'],
            'requires_confirmed' => ['boolean'],
            'requires_evidence' => ['boolean'],

            // Entitlement that lapses. Both halves travel together: a window
            // with nothing to hang off, or an anchor with no window, would
            // silently never apply.
            'anchor' => ['nullable', new Enum(LeaveAnchor::class), 'required_with:window_months'],
            'window_months' => ['nullable', 'integer', 'between:1,24', 'required_with:anchor'],
            'is_paid' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a leave type with that name.',
            'anchor.required_with' => 'Say which date the window is counted from.',
            'window_months.required_with' => 'Say how many months the entitlement stays claimable for.',
        ];
    }

    /**
     * The slug is derived once, on creation, and then left alone so renaming a
     * type does not orphan anything keyed on it.
     *
     * @return array<string, mixed>
     */
    public function payload(bool $withSlug = false): array
    {
        $data = $this->validated();

        // No rule is a rule of nothing, not a null column.
        $data['min_service_months'] ??= 0;

        if ($withSlug) {
            $data['slug'] = $this->uniqueSlug(Str::slug($this->string('name')->toString()));
        }

        return $data;
    }

    protected function uniqueSlug(string $base): string
    {
        $slug = $base === '' ? 'leave-type' : $base;
        $candidate = $slug;
        $suffix = 2;

        while (LeaveType::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$slug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
