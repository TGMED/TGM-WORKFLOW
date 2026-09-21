<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pointing one person at another on the chart.
 *
 * A null manager is a legitimate answer, not a missing one: it is how somebody
 * is put at the top of the chart, or taken out from under a manager who has
 * left.
 */
class OrganogramAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageDepartments) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $managerId = $this->managerId();

            if ($managerId === null) {
                return;
            }

            /** @var User $subject */
            $subject = $this->route('user');

            // Caught here rather than left to blow up later: a loop on the
            // chart is a stretch of the company reporting only to itself, and
            // every page that walks the line has to cope with it forever after.
            if ($subject->wouldReportInACircle($managerId)) {
                $validator->errors()->add(
                    'manager_id',
                    $managerId === $subject->id
                        ? 'Nobody reports to themselves.'
                        : 'That would close a loop: they already report to '.$subject->name.', however far up the chart you go.',
                );
            }
        });
    }

    public function managerId(): ?int
    {
        $id = $this->input('manager_id');

        return $id === null || $id === '' ? null : (int) $id;
    }
}
