<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalSetting;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RequestSettingsController extends Controller
{
    public function index(): Response
    {
        $approvers = User::query()->active()->withRole(Role::APPROVER, Role::SUPER_ADMIN)->count();

        return Inertia::render('admin/RequestSettings', [
            'modules' => collect(RequestModule::cases())
                ->map(fn (RequestModule $module): array => [
                    'value' => $module->value,
                    'label' => $module->label(),
                    'description' => $module->description(),
                    'approvers_required' => ApprovalSetting::approversRequired($module),
                    'pending' => $module->model()::query()
                        ->where('status', RequestStatus::Pending->value)
                        ->count(),
                ])
                ->all(),
            'leave_types' => $this->leaveTypes(),
            'approvers' => $this->approverOptions(),
            // A threshold higher than the number of approvers on staff would
            // leave every request stuck, so the page warns about it.
            'approver_count' => $approvers,
            'totals' => [
                'leave_requests' => LeaveRequest::query()->count(),
                'lateness_requests' => LatenessRequest::query()->count(),
            ],
        ]);
    }

    /**
     * Set how many approvals a module's requests need from here on. Requests
     * already in flight keep the number they were raised under.
     */
    public function update(Request $request, string $module): RedirectResponse
    {
        $requestModule = RequestModule::tryFrom($module);

        abort_if($requestModule === null, 404);

        $validated = $request->validate([
            'approvers_required' => ['required', 'integer', 'between:1,5'],
        ]);

        ApprovalSetting::for($requestModule)->update([
            'approvers_required' => $validated['approvers_required'],
        ]);

        $count = $validated['approvers_required'];

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s now need %d approval%s. Requests already raised keep their original count.',
                $requestModule->label(),
                $count,
                $count === 1 ? '' : 's',
            ),
        ]);
    }

    /**
     * Set the whole approver pool in one go: everyone ticked gains approval
     * rights, everyone unticked who had them drops back to staff.
     */
    public function approvers(Request $request): RedirectResponse
    {
        $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $chosen = $request->collect('user_ids')->map(fn (mixed $id): int => (int) $id);

        $candidates = $this->approverCandidates();
        $committed = $this->supervisorsOfOpenLeave();

        $promote = $candidates
            ->reject(fn (User $user): bool => $user->isApprover())
            ->filter(fn (User $user): bool => $chosen->contains($user->id));

        $demote = $candidates
            ->filter(fn (User $user): bool => $user->isApprover())
            ->reject(fn (User $user): bool => $chosen->contains($user->id));

        // A request names its approver up front, so taking the rights off
        // someone mid-run would leave it with nobody able to rule on it.
        $held = $demote->filter(fn (User $user): bool => $committed->contains($user->id));
        $demote = $demote->reject(fn (User $user): bool => $committed->contains($user->id));

        if ($promote->isNotEmpty()) {
            User::query()
                ->whereKey($promote->pluck('id'))
                ->update(['role_id' => Role::idFor(Role::APPROVER)]);
        }

        if ($demote->isNotEmpty()) {
            User::query()
                ->whereKey($demote->pluck('id'))
                ->update(['role_id' => Role::idFor(Role::STAFF)]);
        }

        return back()->with('toast', $this->approverToast(
            $promote->count(),
            $demote->count(),
            $held,
        ));
    }

    /**
     * Everyone who could hold approval rights. Super admins already approve by
     * virtue of running the system, so their role stays on the staff page.
     *
     * @return Collection<int, User>
     */
    protected function approverCandidates(): Collection
    {
        return User::query()
            ->active()
            ->withRole(Role::STAFF, Role::APPROVER)
            ->with('role:id,slug')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function approverOptions(): array
    {
        $committed = $this->supervisorsOfOpenLeave();

        return $this->approverCandidates()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'department' => $user->department,
                'position' => $user->position,
                'is_approver' => $user->isApprover(),
                'locked' => $user->isApprover() && $committed->contains($user->id),
            ])
            ->values()
            ->all();
    }

    /**
     * The approvers named on leave that still has to run its course, whether
     * it is waiting for a decision or sitting with its author to redo.
     *
     * @return Collection<int, int>
     */
    protected function supervisorsOfOpenLeave(): Collection
    {
        return LeaveRequest::query()
            ->whereIn('status', [RequestStatus::Pending->value, RequestStatus::Returned->value])
            ->whereNotNull('supervisor_id')
            ->distinct()
            ->pluck('supervisor_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  Collection<int, User>  $held
     * @return array{type: string, message: string}
     */
    protected function approverToast(int $promoted, int $demoted, Collection $held): array
    {
        $changes = [];

        if ($promoted > 0) {
            $changes[] = sprintf('%d %s can now approve requests', $promoted, $promoted === 1 ? 'person' : 'people');
        }

        if ($demoted > 0) {
            $changes[] = sprintf('%d no longer %s', $demoted, $demoted === 1 ? 'does' : 'do');
        }

        if ($held->isEmpty()) {
            return $changes === []
                ? ['type' => 'info', 'message' => 'The approver list is already as you left it.']
                : ['type' => 'success', 'message' => ucfirst(implode(', ', $changes)).'.'];
        }

        return [
            'type' => 'info',
            'message' => trim(sprintf(
                '%s %s kept approval rights, being named on leave that is still waiting on them.',
                $changes === [] ? '' : ucfirst(implode(', ', $changes)).'.',
                $held->pluck('name')->join(', ', ' and '),
            )),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function leaveTypes(): array
    {
        $inUse = LeaveRequest::query()
            ->selectRaw('leave_type_id, count(*) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        return LeaveType::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type): array => [
                'id' => $type->id,
                'slug' => $type->slug,
                'name' => $type->name,
                'description' => $type->description,
                'days_per_year' => $type->days_per_year,
                'is_paid' => $type->is_paid,
                'is_active' => $type->is_active,
                'requests' => (int) ($inUse[$type->id] ?? 0),
            ])
            ->all();
    }
}
