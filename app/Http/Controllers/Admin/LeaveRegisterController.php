<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every leave request in the company, whoever it belongs to.
 *
 * Read-only on purpose. Deciding on leave is the approvals inbox's job and it
 * goes through the chain; this is the register somebody reads when they need
 * to answer "who is off in March" or "where has that request got to", and
 * giving it buttons would be a second way to settle a request that the chain
 * knew nothing about.
 *
 * Filtered and paged in SQL rather than in the browser: this is the whole
 * company over any year, which is a different size of thing from the page
 * somebody reads about their own leave.
 */
class LeaveRegisterController extends Controller
{
    /**
     * The download's header row.
     */
    private const COLUMNS = [
        'Employee ID',
        'Name',
        'Department',
        'Leave type',
        'Start',
        'End',
        'Working days',
        'Status',
        'Stage',
        'Relief officer',
        'Approver',
        'Reason',
        'Filed',
        'Decided',
    ];

    public function __construct(protected ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $paginator = $this->register($filters)
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/LeaveRegister', [
            'rows' => $paginator->through(fn (LeaveRequest $leave): array => $this->row($leave)),
            'filters' => $filters,
            'departments' => Department::options(),
            'types' => LeaveType::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (LeaveType $type): array => [
                    'value' => (string) $type->id,
                    'label' => $type->name,
                ])
                ->all(),
            'statuses' => collect(RequestStatus::cases())
                ->map(fn (RequestStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->all(),
            'years' => $this->years($filters['year']),
            'summary' => $this->summary($filters),
        ]);
    }

    /**
     * The same register as a spreadsheet, over the same filters, and over
     * everyone they match rather than the page being looked at.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        return response()->streamDownload(
            function () use ($filters): void {
                $handle = fopen('php://output', 'w');

                if ($handle === false) {
                    return;
                }

                // Excel opens a UTF-8 CSV as the local codepage unless the
                // file leads with a byte order mark, which mangles any name
                // with an accent in it.
                fwrite($handle, "\xEF\xBB\xBF");

                fputcsv($handle, self::COLUMNS);

                $this->register($filters)->chunk(200, function (Collection $leave) use ($handle): void {
                    foreach ($leave as $row) {
                        fputcsv($handle, $this->csvRow($row));
                    }
                });

                fclose($handle);
            },
            "leave-{$filters['year']}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * The register under the filters asked for, newest first.
     *
     * @param  array<string, string|int>  $filters
     * @return Builder<LeaveRequest>
     */
    private function register(array $filters): Builder
    {
        return LeaveRequest::query()
            ->with([
                'user:id,name,employee_id,department_id',
                'user.department:id,name',
                'leaveType:id,name',
                'supervisor:id,name',
                'reliefOfficer:id,name',
                'teamLead:id,name',
                'head:id,name',
                'approvals.approver:id,name',
            ])
            ->inYear((int) $filters['year'])
            ->when(
                $filters['status'] !== '',
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                $filters['type'] !== '',
                fn (Builder $query) => $query->where('leave_type_id', $filters['type']),
            )
            ->when(
                $filters['department'] !== '',
                fn (Builder $query) => $query->whereHas(
                    'user',
                    fn (Builder $user) => $user->where('department_id', $filters['department']),
                ),
            )
            ->when(
                $filters['search'] !== '',
                fn (Builder $query) => $query->whereHas(
                    'user',
                    fn (Builder $user) => $user
                        ->where('name', 'like', '%'.$filters['search'].'%')
                        ->orWhere('employee_id', 'like', '%'.$filters['search'].'%'),
                ),
            )
            ->orderByDesc('start_date')
            ->orderByDesc('id');
    }

    /**
     * What the register reads off the query string. The page and the download
     * share them, so a spreadsheet can never cover a different set of requests
     * from the screen it was asked for.
     *
     * @return array{search: string, department: string, type: string, status: string, year: int}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->toString(),
            'department' => $request->string('department')->toString(),
            'type' => $request->string('type')->toString(),
            'status' => $request->string('status')->toString(),
            'year' => (int) $request->integer('year', Carbon::now()->year),
        ];
    }

    /**
     * The tallies under the filters, over everything they match rather than
     * over the page on screen. Counted in SQL: a year of the whole company is
     * more than anybody wants to add up in PHP.
     *
     * @param  array<string, string|int>  $filters
     * @return array<string, int>
     */
    private function summary(array $filters): array
    {
        $base = fn (): Builder => $this->register($filters)->reorder();

        return [
            'requests' => $base()->count(),
            'days' => (int) $base()->where('status', RequestStatus::Approved->value)->sum('days'),
            'pending' => $base()->where('status', RequestStatus::Pending->value)->count(),
            'returned' => $base()->where('status', RequestStatus::Returned->value)->count(),
            'people' => $base()->distinct()->count('user_id'),
        ];
    }

    /**
     * The years there is leave on file for, newest first, with the year being
     * looked at included even when it is empty.
     *
     * @return array<int, int>
     */
    private function years(int $year): array
    {
        $booked = LeaveRequest::query()
            ->get(['start_date'])
            ->map(fn (LeaveRequest $leave): int => $leave->start_date->year)
            ->all();

        return collect($booked)
            ->push($year)
            ->push(Carbon::now()->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LeaveRequest $leave): array
    {
        return [
            'id' => $leave->id,
            'person' => [
                'id' => $leave->user->id,
                'name' => $leave->user->name,
                'initials' => $leave->user->initials,
                'employee_id' => $leave->user->employee_id,
                'department' => $leave->user->department?->name,
            ],
            'type' => $leave->leaveType->name,
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->end_date->toDateString(),
            'range_label' => $leave->start_date->format('j M Y').' to '.$leave->end_date->format('j M Y'),
            'days' => $leave->days,
            'reason' => $leave->reason,
            'relief_officer' => $leave->reliefOfficer?->name,
            'supervisor' => $leave->supervisor?->name,
            'status' => $leave->status->value,
            'status_label' => $leave->status->label(),
            'status_tone' => $leave->status->tone(),
            // Where an open request has reached, which is the question the
            // register is most often opened to answer.
            'stage_label' => $leave->status->isOpen() ? $leave->stageLabel() : null,
            'has_evidence' => $leave->hasEvidence(),
            'filed_at' => $leave->created_at?->toIso8601String(),
            'decided_at' => $leave->decided_at?->toIso8601String(),
            'trail' => $this->approvals->trail($leave),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function csvRow(LeaveRequest $leave): array
    {
        return [
            $leave->user->employee_id ?? '',
            $leave->user->name,
            $leave->user->department->name ?? '',
            $leave->leaveType->name,
            $leave->start_date->toDateString(),
            $leave->end_date->toDateString(),
            (string) $leave->days,
            $leave->status->label(),
            $leave->status->isOpen() ? $leave->stageLabel() : '',
            $leave->reliefOfficer->name ?? '',
            $leave->supervisor->name ?? '',
            $leave->reason ?? '',
            $leave->created_at?->toDateString() ?? '',
            $leave->decided_at?->toDateString() ?? '',
        ];
    }
}
