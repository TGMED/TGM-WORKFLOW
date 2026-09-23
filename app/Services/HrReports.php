<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\RequestStatus;
use App\Enums\RequisitionStatus;
use App\Enums\RetirementStatus;
use App\Enums\ReviewVisibility;
use App\Enums\StaffActionKind;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use App\Models\Requisition;
use App\Models\StaffAction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The HR reports: one table per subject, each summed over a date window.
 *
 * Every section answers with the same shape (a title, the columns, the rows)
 * so the page draws them all alike and the CSV export writes exactly what the
 * page shows. Totals are worked out in PHP rather than in SQL: the numbers are
 * a company's worth, not a warehouse's, and it keeps the queries the same on
 * every database.
 */
class HrReports
{
    public const SECTIONS = ['headcount', 'attendance', 'leave', 'reviews', 'conduct', 'assets', 'requisitions'];

    /**
     * @return array{key: string, title: string, description: string, columns: array<int, string>, rows: array<int, array<int, string|int|float|null>>}
     */
    public function section(string $key, CarbonInterface $from, CarbonInterface $to): array
    {
        [$title, $description, $columns, $rows] = match ($key) {
            'headcount' => $this->headcount($from, $to),
            'attendance' => $this->attendance($from, $to),
            'leave' => $this->leave($from, $to),
            'reviews' => $this->reviews($from, $to),
            'conduct' => $this->conduct($from, $to),
            'assets' => $this->assets(),
            'requisitions' => $this->requisitions($from, $to),
            default => throw new InvalidArgumentException("There is no report called {$key}."),
        };

        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(fn (string $key): array => $this->section($key, $from, $to), self::SECTIONS);
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, string>, 3: array<int, array<int, mixed>>}
     */
    protected function headcount(CarbonInterface $from, CarbonInterface $to): array
    {
        $people = User::query()->clocksIn()->get(['id', 'department_id', 'is_active', 'employment_status', 'hired_at', 'exit_date']);

        $rows = $this->byDepartment($people, fn (Collection $group): array => [
            $group->where('is_active', true)->count(),
            $group->where('is_active', true)->where('employment_status', EmploymentStatus::Probation)->count(),
            $group->filter(fn (User $u): bool => $u->hired_at !== null && $u->hired_at->betweenIncluded($from, $to))->count(),
            $group->filter(fn (User $u): bool => $u->exit_date !== null && $u->exit_date->betweenIncluded($from, $to))->count(),
        ]);

        return [
            'Headcount and movement',
            'Staff in post today, and who joined or left in the window.',
            ['Department', 'In post', 'On probation', 'Joined', 'Left'],
            $rows,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, string>, 3: array<int, array<int, mixed>>}
     */
    protected function attendance(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = Attendance::query()
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->join('users', 'users.id', '=', 'attendances.user_id')
            ->get(['attendances.status', 'attendances.late_minutes', 'attendances.worked_minutes', 'attendances.excused_at', 'users.department_id']);

        $rows = $this->byDepartment($days, function (Collection $group): array {
            $late = $group->filter(fn ($d): bool => $d->status === AttendanceStatus::Late && $d->excused_at === null);
            $worked = $group->pluck('worked_minutes')->filter();

            return [
                $group->count(),
                $late->count(),
                (int) $late->sum('late_minutes'),
                $worked->isEmpty() ? null : round($worked->avg() / 60, 1),
            ];
        });

        return [
            'Attendance',
            'Days clocked in the window. Lateness that was excused is left out.',
            ['Department', 'Days clocked', 'Late days', 'Minutes late', 'Average hours worked'],
            $rows,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, string>, 3: array<int, array<int, mixed>>}
     */
    protected function leave(CarbonInterface $from, CarbonInterface $to): array
    {
        $requests = LeaveRequest::query()
            ->with('leaveType:id,name')
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'leave_type_id', 'status', 'days']);

        $rows = $requests
            ->groupBy(fn (LeaveRequest $r): string => $r->leaveType->name ?? 'Unknown')
            ->sortKeys()
            ->map(fn (Collection $group, string $type): array => [
                $type,
                $group->count(),
                $group->where('status', RequestStatus::Approved)->count(),
                $group->where('status', RequestStatus::Rejected)->count(),
                (int) $group->where('status', RequestStatus::Approved)->sum('days'),
            ])
            ->values()
            ->all();

        return [
            'Leave',
            'Leave starting in the window, by type.',
            ['Leave type', 'Requests', 'Approved', 'Declined', 'Days approved'],
            $rows,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, string>, 3: array<int, array<int, mixed>>}
     */
    protected function reviews(CarbonInterface $from, CarbonInterface $to): array
    {
        $reviews = PerformanceReview::query()
            ->whereBetween('performance_reviews.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->join('users', 'users.id', '=', 'performance_reviews.subject_user_id')
            ->get(['performance_reviews.visibility', 'performance_reviews.rating', 'users.department_id']);

        $rows = $this->byDepartment($reviews, fn (Collection $group): array => [
            $group->count(),
            $group->where('visibility', ReviewVisibility::Public)->count(),
            $group->where('visibility', ReviewVisibility::Private)->count(),
            round((float) $group->avg('rating'), 2),
        ]);

        return [
            'Performance reviews',
            'Reviews written in the window, by the department of the person reviewed. Authors are never named here.',
            ['Department', 'Reviews', 'Shared', 'HR only', 'Average rating'],
            $rows,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, string>, 3: array<int, array<int, mixed>>}
     */
    protected function conduct(CarbonInterface $from, CarbonInterface $to): array
    {
        $actions = StaffAction::query()
            ->whereBetween('staff_actions.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->join('users', 'users.id', '=', 'staff_actions.subject_user_id')
            ->get(['staff_actions.kind', 'staff_actions.responded_at', 'users.department_id']);

        $rows = $this->byDepartment($actions, fn (Collection $group): array => [
            $group->where('kind', StaffActionKind::Query)->count(),
            $group->filter(fn ($a): bool => $a->kind === StaffActionKind::Query && $a->responded_at === null)->count(),
            $group->where('kind', StaffActionKind::Warning)->count(),
            $group->where('kind', StaffActionKind::Confirmation)->count(),
        ]);

        return [
            'Queries, warnings and confirmations',
            'Letters issued in the window.',
            ['Department', 'Queries', 'Unanswered', 'Warnings', 'Confirmations'],
            $rows,
        ];
    }

    /**
     * Where the register stands today. Assets are not a flow, so the window
     * does not apply.
     *
     * @return array{0: string, 1: string, 2: array<int, string>, 3: array<int, array<int, mixed>>}
     */
    protected function assets(): array
    {
        $assets = Asset::query()->get(['asset_category_id', 'status', 'purchase_cost']);
        $names = AssetCategory::query()->pluck('name', 'id');

        $rows = $assets
            ->groupBy('asset_category_id')
            ->map(fn (Collection $group, int $category): array => [
                $names[$category] ?? 'Unknown',
                $group->count(),
                $group->where('status', AssetStatus::Available)->count(),
                $group->where('status', AssetStatus::Assigned)->count(),
                $group->where('status', AssetStatus::InRepair)->count(),
                $group->where('status', AssetStatus::Retired)->count(),
                number_format((float) $group->where('status', '!=', AssetStatus::Retired)->sum('purchase_cost'), 2, '.', ''),
            ])
            ->sortBy(0)
            ->values()
            ->all();

        return [
            'Assets',
            'The register as it stands today, by category. Value is what was paid for everything not retired.',
            ['Category', 'Total', 'Available', 'Assigned', 'In repair', 'Retired', 'Value (NGN)'],
            $rows,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, string>, 3: array<int, array<int, mixed>>}
     */
    protected function requisitions(CarbonInterface $from, CarbonInterface $to): array
    {
        $requisitions = Requisition::query()
            ->with('retirement:id,requisition_id,amount_spent,status')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['id', 'department_id', 'amount', 'status']);

        $paid = [RequisitionStatus::Paid, RequisitionStatus::Retired];

        $rows = $this->byDepartment($requisitions, fn (Collection $group): array => [
            $group->count(),
            $this->money($group->sum(fn (Requisition $r): float => (float) $r->amount)),
            $this->money($group->filter(fn (Requisition $r): bool => in_array($r->status, $paid, true))->sum(fn (Requisition $r): float => (float) $r->amount)),
            $this->money($group->filter(fn (Requisition $r): bool => $r->retirement?->status === RetirementStatus::Accepted)->sum(fn (Requisition $r): float => (float) $r->retirement?->amount_spent)),
            $group->filter(fn (Requisition $r): bool => $r->status === RequisitionStatus::Paid)->count(),
        ]);

        return [
            'Requisitions',
            'Requisitions raised in the window, by the department of whoever raised them.',
            ['Department', 'Raised', 'Requested (NGN)', 'Paid out (NGN)', 'Spent, retired (NGN)', 'Paid, not yet retired'],
            $rows,
        ];
    }

    /**
     * Rows grouped by department, in name order, with anyone in no department
     * last, and a total row when there is more than one.
     *
     * @param  Collection<int, mixed>  $items
     * @param  callable(Collection<int, mixed>): array<int, mixed>  $summarise
     * @return array<int, array<int, mixed>>
     */
    protected function byDepartment(Collection $items, callable $summarise): array
    {
        $names = Department::query()->pluck('name', 'id');

        $rows = $items
            ->groupBy(fn ($item): string => (string) ($item->department_id ?? ''))
            ->map(fn (Collection $group, string $id): array => [
                $id === '' ? 'No department' : ($names[(int) $id] ?? 'Unknown'),
                ...$summarise($group),
            ])
            ->sortBy(fn (array $row): string => ($row[0] === 'No department' ? '~' : '').$row[0])
            ->values();

        if ($rows->count() > 1) {
            $rows->push(['All departments', ...$summarise($items)]);
        }

        return $rows->all();
    }

    protected function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
