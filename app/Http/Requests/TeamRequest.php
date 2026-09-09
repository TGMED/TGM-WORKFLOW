<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating or renaming a team inside a department, and naming who leads it.
 *
 * A team is drawn from one department, so both the lead and the members have
 * to already sit in it. That is what keeps "the people under this head" a
 * question with a single answer.
 */
class TeamRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->department();
        $team = $this->team();

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('teams', 'name')
                    ->where('department_id', $department->id)
                    ->ignore($team?->id)
                    ->withoutTrashed(),
            ],

            'lead_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('is_active', true)
                    ->where('department_id', $department->id),
            ],

            'members' => ['nullable', 'array'],
            'members.*' => [
                'integer',
                Rule::exists('users', 'id')
                    ->where('is_active', true)
                    ->where('department_id', $department->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This department already has a team by that name.',
            'lead_user_id.exists' => 'A team is led by somebody in its own department. Move them into it first.',
            'members.*.exists' => 'A team is drawn from its own department. Move them into it first.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lead = $this->input('lead_user_id');

            if ($lead === null) {
                return;
            }

            // Somebody other than the lead themselves: a lead who leads only
            // themselves is the case this whole page exists to prevent.
            $others = array_diff($this->chosenMembers(), [(int) $lead]);

            if ($others !== []) {
                return;
            }

            $validator->errors()->add(
                'members',
                'A team lead needs a team. Choose who is on it, or leave the lead unset for now.',
            );
        });
    }

    /**
     * The people picked on the form, before the lead is folded in.
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
     * The team being edited, or null when one is being created.
     */
    public function team(): ?Team
    {
        $team = $this->route('team');

        return $team instanceof Team ? $team : null;
    }

    /**
     * The department the team belongs to: named in the URL when the team is
     * being created, and read off the team itself when it already exists.
     */
    public function department(): Department
    {
        $team = $this->team();

        if ($team !== null) {
            return $team->department;
        }

        /** @var Department $department */
        $department = $this->route('department');

        return $department;
    }

    /**
     * The people on the team, the lead included: whoever runs it is on it.
     *
     * Null when the request did not mention membership, so renaming a team
     * cannot quietly disband it. See DepartmentRequest::members().
     *
     * @return array<int, int>|null
     */
    public function members(): ?array
    {
        if (! $this->has('members')) {
            return null;
        }

        $ids = $this->chosenMembers();

        $lead = $this->input('lead_user_id');

        if ($lead !== null) {
            $ids[] = (int) $lead;
        }

        return array_values(array_unique($ids));
    }
}
