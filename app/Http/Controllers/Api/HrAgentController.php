<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReviewVisibility;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PerformanceReview;
use App\Models\Policy;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\Metrics\AttendanceTally;
use App\Services\PolicyLibrary;
use App\Support\Workdays;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only data for the TGM HR Agent, which answers staff emails and helps
 * management. Every endpoint lists everyone at once, so the agent makes one
 * request rather than one per person.
 *
 * Deliberately left out, whatever the agent asks: pay, salary profiles and
 * payslips; bank details, addresses and next of kin; offences, sanctions,
 * staff actions and termination recommendations; who wrote any review, and
 * private reviews altogether; leave reasons and medical evidence.
 */
class HrAgentController extends Controller
{
    /** The longest window one request may cover, so a typo cannot ask for decades. */
    private const MAX_DAYS = 400;

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Everyone with an account, including people who have left (marked
     * "exited"), so the agent can tell a former colleague from a stranger.
     */
    public function staff(): JsonResponse
    {
        $people = User::query()
            ->with(['department:id,name', 'team:id,name', 'location:id,name', 'manager:id,email'])
            ->orderBy('name')
            ->get();

        return response()->json($people->map(fn (User $user): array => [
            'email' => strtolower((string) $user->email),
            'name' => (string) $user->name,
            'employee_id' => $user->employee_id,
            'job_title' => (string) ($user->position ?? ''),
            'department' => (string) ($user->department->name ?? ''),
            'team' => (string) ($user->team->name ?? ''),
            'location' => (string) ($user->location->name ?? ''),
            'manager_email' => strtolower((string) ($user->manager->email ?? '')),
            'employment_status' => $user->is_active ? $user->employment_status->value : 'exited',
            'hired_on' => $user->hired_at?->toDateString(),
            'skills' => [],
        ])->values());
    }

    /**
     * Each person's standing against every active leave type with an
     * allowance, for one year. Pending requests count as taken, as on
     * Workflow's own leave page: a day cannot be promised twice.
     */
    public function leaveBalances(Request $request, LeaveBalance $balances): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);
        if ($year < 2000 || $year > 2100) {
            return response()->json(['error' => 'year must be a four-digit year.'], 400);
        }

        $types = LeaveType::query()->active()->orderBy('name')->get();
        $result = [];

        foreach (User::query()->active()->get() as $user) {
            $used = $balances->usedByType($user, $year);
            $rows = [];
            foreach ($types as $type) {
                $allowance = $type->allowanceFor($user);
                if ($allowance === null) {
                    continue;
                }
                $taken = (int) ($used[$type->id] ?? 0);
                $rows[] = [
                    'leave_type' => (string) $type->name,
                    'entitled_days' => $allowance,
                    'taken_days' => $taken,
                    'remaining_days' => max(0, $allowance - $taken),
                ];
            }
            $result[strtolower((string) $user->email)] = $rows;
        }

        return response()->json(['year' => $year, 'staff' => $result]);
    }

    /**
     * Attendance totals for everyone who clocks in: days they were expected
     * (their site's working week, less public holidays and days before they
     * joined), days present, days late (unexcused), and days on approved leave.
     */
    public function attendance(Request $request): JsonResponse
    {
        $window = $this->window($request);
        if ($window instanceof JsonResponse) {
            return $window;
        }
        [$from, $to] = $window;

        $people = User::query()->active()->with('location')->get()->filter(fn (User $user): bool => $user->clocksIn());
        $tallies = AttendanceTally::over(
            Attendance::query()->whereIn('user_id', $people->modelKeys()),
            $from->toDateString(),
            $to->toDateString(),
        );
        $leave = $this->approvedLeave($from, $to, $people->modelKeys());
        $holidays = [];
        $result = [];

        foreach ($people as $user) {
            $site = $user->location_id;
            $workdays = $user->location?->workdayNumbers() ?? [1, 2, 3, 4, 5];
            $siteHolidays = $holidays[$site ?? 0] ??= PublicHoliday::datesBetween($from, $to, $site);
            $start = $user->hired_at && $user->hired_at->greaterThan($from) ? $user->hired_at->copy() : $from->copy();

            $leaveDays = 0;
            foreach ($leave->get($user->id, collect()) as $away) {
                $leaveDays += Workdays::countBetween(
                    $away->start_date->greaterThan($start) ? $away->start_date : $start,
                    $away->end_date->lessThan($to) ? $away->end_date : $to,
                    $workdays,
                    $siteHolidays,
                );
            }

            $tally = $tallies->get($user->id) ?? AttendanceTally::none($user->id);
            $result[strtolower((string) $user->email)] = [
                'working_days' => Workdays::countBetween($start, $to, $workdays, $siteHolidays),
                'present_days' => $tally->daysPresent,
                'late_days' => $tally->daysLate,
                'leave_days' => $leaveDays,
            ];
        }

        return response()->json([
            'period' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'staff' => $result,
        ]);
    }

    /**
     * Approved time away touching the window: dates and the kind of leave
     * only. Never the reason given, nor any evidence.
     */
    public function absences(Request $request): JsonResponse
    {
        $window = $this->window($request);
        if ($window instanceof JsonResponse) {
            return $window;
        }
        [$from, $to] = $window;

        $rows = LeaveRequest::query()
            ->approved()
            ->overlapping($from, $to)
            // Only people still on the books; a leave type retired since still names the leave.
            ->whereHas('user')
            ->with(['user:id,email', 'leaveType' => fn ($type) => $type->withTrashed()->select('id', 'name')])
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $away): array => [
                'email' => strtolower((string) $away->user->email),
                'start' => $away->start_date->toDateString(),
                'end' => $away->end_date->toDateString(),
                'kind' => strtolower((string) $away->leaveType->name),
            ])
            ->values();

        return response()->json($rows);
    }

    /**
     * Public reviews only: their number, average rating, and the comments the
     * subject can already read. Never who wrote them; private reviews are left
     * out entirely.
     */
    public function reviews(): JsonResponse
    {
        $result = [];

        PerformanceReview::query()
            ->public()
            ->whereHas('subject')
            ->with('subject:id,email')
            ->latest()
            ->get()
            ->filter(fn (PerformanceReview $review): bool => $review->visibility === ReviewVisibility::Public)
            ->groupBy(fn (PerformanceReview $review): string => strtolower((string) $review->subject->email))
            ->each(function ($reviews, string $email) use (&$result): void {
                $result[$email] = [
                    'review_count' => $reviews->count(),
                    'average_rating' => round((float) $reviews->avg('rating'), 1),
                    'public_comments' => $reviews->take(5)->pluck('body')->map(fn ($body): string => (string) $body)->values(),
                ];
            });

        return response()->json($result);
    }

    /**
     * Policies in force. The agent reads each document through policyFile().
     */
    public function policies(): JsonResponse
    {
        $policies = Policy::query()->active()->inForce()->orderBy('title')->get();

        return response()->json($policies->map(fn (Policy $policy): array => [
            'id' => $policy->id,
            'title' => (string) $policy->title,
            'category' => $policy->category->label(),
            'version' => (string) ($policy->version ?? ''),
            'effective_from' => $policy->effective_from->toDateString(),
            'summary' => (string) ($policy->summary ?? ''),
            'file_name' => (string) $policy->file_name,
            'mime_type' => (string) ($policy->mime_type ?? ''),
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ])->values());
    }

    /**
     * One policy document in force, so the agent can quote from it.
     */
    public function policyFile(string $policy): Response
    {
        // Answered in JSON here: the app's own error page is HTML for people.
        $found = ctype_digit($policy) ? Policy::query()->find((int) $policy) : null;
        if (! $found || ! $found->is_active || ! $found->isInForce()) {
            return response()->json(['error' => 'No policy in force with that id.'], 404);
        }
        $policy = $found;

        $disk = Storage::disk(PolicyLibrary::DISK);
        if (! $disk->exists($policy->file_path)) {
            return response()->json(['error' => 'The policy file is missing from storage.'], 404);
        }

        return $disk->response($policy->file_path, $policy->file_name, [
            'Content-Type' => $policy->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Public holidays in a year, with the sites that keep them (none listed
     * means company-wide).
     */
    public function holidays(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);
        if ($year < 2000 || $year > 2100) {
            return response()->json(['error' => 'year must be a four-digit year.'], 400);
        }

        $holidays = PublicHoliday::query()
            ->with('locations:id,name')
            ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->orderBy('date')
            ->get();

        return response()->json($holidays->map(fn (PublicHoliday $holiday): array => [
            'date' => $holiday->date->toDateString(),
            'name' => (string) $holiday->name,
            'locations' => $holiday->locations->pluck('name')->values(),
        ])->values());
    }

    /**
     * ?start=&end= as Y-m-d, both required and in order.
     *
     * @return array{0: Carbon, 1: Carbon}|JsonResponse
     */
    private function window(Request $request): array|JsonResponse
    {
        try {
            $from = Carbon::createFromFormat('!Y-m-d', (string) $request->query('start'));
            $to = Carbon::createFromFormat('!Y-m-d', (string) $request->query('end'));
        } catch (\Throwable) {
            $from = $to = null;
        }

        if (! $from instanceof Carbon || ! $to instanceof Carbon) {
            return response()->json(['error' => 'start and end must be dates written YYYY-MM-DD.'], 400);
        }
        if ($to->lessThan($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            return response()->json(['error' => 'end must be on or after start, at most '.self::MAX_DAYS.' days later.'], 400);
        }

        return [$from, $to];
    }

    /**
     * Approved leave touching the window, grouped by person.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<array-key, EloquentCollection<int, LeaveRequest>>
     */
    private function approvedLeave(Carbon $from, Carbon $to, array $userIds): Collection
    {
        return LeaveRequest::query()
            ->approved()
            ->overlapping($from, $to)
            ->whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id');
    }
}
