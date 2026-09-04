<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LeaveAnchor;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalSetting;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveRestrictedPeriod;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'restricted_periods' => $this->restrictedPeriods(),
            // The pick-list the profile form writes from, so a period can
            // only ever exempt a status somebody could actually be recorded as.
            'marital_statuses' => config('profile.marital_statuses'),
            // The dates an expiring entitlement can be counted from.
            'leave_anchors' => LeaveAnchor::options(),
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
     * Periods closed to leave, newest window first. Past ones stay listed:
     * they still stand in the way of a request backdated into them, and an
     * administrator may want last year's window to copy from.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function restrictedPeriods(): array
    {
        return LeaveRestrictedPeriod::query()
            ->with('leaveTypes:id,name')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (LeaveRestrictedPeriod $period): array => [
                'id' => $period->id,
                'name' => $period->name,
                'reason' => $period->reason,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'range_label' => $period->rangeLabel(),
                'is_over' => $period->end_date->isBefore(Carbon::now()->startOfDay()),
                'exempt_marital_statuses' => $period->exempt_marital_statuses,
                'exempt_leave_types' => $period->leaveTypes
                    ->map(fn (LeaveType $type): array => ['id' => $type->id, 'name' => $type->name])
                    ->values()
                    ->all(),
            ])
            ->all();
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
                'days_per_year_manager' => $type->days_per_year_manager,
                'min_service_months' => $type->min_service_months,
                'requires_confirmed' => $type->requires_confirmed,
                'requires_evidence' => $type->requires_evidence,
                'anchor' => $type->anchor?->value,
                'window_months' => $type->window_months,
                'is_paid' => $type->is_paid,
                'is_active' => $type->is_active,
                'requests' => (int) ($inUse[$type->id] ?? 0),
            ])
            ->all();
    }
}
