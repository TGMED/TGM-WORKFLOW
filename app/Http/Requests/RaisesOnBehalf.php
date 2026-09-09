<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Shared parts of a request an approver files for somebody else.
 *
 * The request belongs to the member of staff named in `staff_id`, so every
 * rule that would have measured the person filling the form in — allowances,
 * clashes, the site's working days — measures them instead.
 */
trait RaisesOnBehalf
{
    /**
     * Only an approver may file for someone else. Which member of staff they
     * may file for is a validation matter, not an authorisation one.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ApproveRequests) === true;
    }

    /**
     * @return array<int, mixed>
     */
    protected function staffRule(): array
    {
        return [
            'required',
            'integer',
            // Filing "for yourself" is just filing, and would let an approver
            // walk their own request through the chain.
            Rule::notIn([$this->user()?->id]),
            Rule::exists('users', 'id')
                ->where('is_active', true)
                ->whereNotIn(
                    'id',
                    DB::table('role_user')
                        ->whereIn('role_id', Role::query()->where('slug', Role::SUPER_ADMIN)->pluck('id'))
                        ->pluck('user_id')
                        ->all(),
                ),
        ];
    }

    /**
     * The member of staff the request is for.
     *
     * Falls back to the person filling the form in when `staff_id` names
     * nobody, purely so the other rules have someone to measure against; the
     * request still fails on `staff_id` in that case.
     */
    protected function staff(): User
    {
        /** @var User $filer */
        $filer = $this->user();

        $staff = User::query()->find($this->integer('staff_id'));

        return ($staff ?? $filer)->loadMissing('location');
    }
}
