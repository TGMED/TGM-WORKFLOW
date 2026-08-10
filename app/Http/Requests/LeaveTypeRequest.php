<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
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
