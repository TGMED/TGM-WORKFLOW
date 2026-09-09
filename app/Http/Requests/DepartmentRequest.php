<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating or renaming a department, and naming who heads it.
 *
 * The rule worth reading twice is at the bottom: a head has to come with
 * people. A head of department with nobody under them is a role granted for
 * nothing, which is exactly what the departments page exists to prevent.
 */
class DepartmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->department();

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('departments', 'name')->ignore($department?->id)->withoutTrashed(),
            ],
            'description' => ['nullable', 'string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],

            'head_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true),
            ],

            // Sent whole rather than as a difference: the page shows the
            // department's people as a list, and what comes back is that list.
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a department by that name.',
            'head_user_id.exists' => 'Pick somebody still with the company to head it.',
            'members.*.exists' => 'One of the people chosen is no longer with the company.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $head = $this->input('head_user_id');

            if ($head === null) {
                return;
            }

            // Somebody other than the head themselves. members() folds the head
            // in, since whoever runs a department belongs to it, so the count
            // that matters is the one taken before that.
            $others = array_diff($this->chosenMembers(), [(int) $head]);

            if ($others !== []) {
                return;
            }

            $validator->errors()->add(
                'members',
                'A head of department needs people to head. Choose who is in this department, or leave the head unset for now.',
            );
        });
    }

    /**
     * The people picked on the form, before the head is folded in.
     *
     * @return array<int, int>
     */
    protected function chosenMembers(): array
    {
        /** @var array<int, mixed> $members */
        $members = $this->input('members') ?? [];

        return array_map(intval(...), $members);
    }

    /**
     * The people who should be in this department, the head included: whoever
     * runs it belongs to it.
     *
     * Null when the request did not mention membership at all, which is not the
     * same as mentioning it and naming nobody. The first leaves the department
     * as it is; the second empties it. Collapsing the two would let a request
     * that only renamed a department turn everybody out of it.
     *
     * @return array<int, int>|null
     */
    public function members(): ?array
    {
        if (! $this->has('members')) {
            return null;
        }

        $ids = $this->chosenMembers();

        $head = $this->input('head_user_id');

        if ($head !== null) {
            $ids[] = (int) $head;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $name = (string) $this->validated()['name'];

        $department = $this->department();

        return [
            'name' => $name,
            'description' => $this->validated()['description'] ?? null,
            'is_active' => (bool) ($this->validated()['is_active'] ?? true),
            // The slug only follows the name for a department being created.
            // Renaming one later leaves the slug alone, so anything already
            // pointing at it keeps working.
            'slug' => $department === null
                ? Department::uniqueSlug($name)
                : $department->slug,
        ];
    }

    /**
     * The department being edited, or null when one is being created.
     */
    protected function department(): ?Department
    {
        $department = $this->route('department');

        return $department instanceof Department ? $department : null;
    }
}
