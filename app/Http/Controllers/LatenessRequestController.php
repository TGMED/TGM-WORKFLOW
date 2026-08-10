<?php

namespace App\Http\Controllers;

use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Http\Requests\StoreLatenessRequest;
use App\Models\ApprovalSetting;
use App\Models\Attendance;
use App\Models\LatenessRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class LatenessRequestController extends Controller
{
    public function __construct(protected ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $requests = LatenessRequest::query()
            ->with('approvals.approver:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('work_date')
            ->limit(60)
            ->get();

        $explained = $requests->pluck('work_date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $today = $this->today($user);

        return Inertia::render('Lateness', [
            'requests' => $requests->map(fn (LatenessRequest $late): array => $this->payload($late))->values(),
            'today' => $today->toDateString(),
            'today_label' => $today->format('D, j M Y'),
            'explained_today' => in_array($today->toDateString(), $explained, true),
            'unexplained' => $this->unexplainedDays($user->id, $today, $explained),
            'approvers_required' => ApprovalSetting::approversRequired(RequestModule::Lateness),
            'stats' => [
                'pending' => $requests->where('status', RequestStatus::Pending)->count(),
                'excused' => $requests->where('status', RequestStatus::Approved)->count(),
            ],
        ]);
    }

    public function store(StoreLatenessRequest $request): RedirectResponse
    {
        $user = $request->user();
        $workDate = $request->workDate();

        // The attendance row, when there is one, supplies the minutes rather
        // than trusting a number typed by the person explaining themselves.
        $attendance = Attendance::query()
            ->where('user_id', $user->id)
            ->where('work_date', $workDate->toDateString())
            ->first();

        $late = LatenessRequest::query()->create([
            'user_id' => $user->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $workDate,
            'minutes_late' => $attendance->late_minutes ?? 0,
            'reason' => $request->string('reason')->toString(),
            'status' => RequestStatus::Pending,
            'approvals_required' => ApprovalSetting::approversRequired(RequestModule::Lateness),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Explanation filed for {$late->work_date->format('j M Y')}.",
        ]);
    }

    public function destroy(Request $request, LatenessRequest $lateness): RedirectResponse
    {
        abort_unless($lateness->user_id === $request->user()->id, 403);

        if (! $lateness->status->isOpen()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That explanation has already been decided and cannot be withdrawn.',
            ]);
        }

        $lateness->update([
            'status' => RequestStatus::Cancelled,
            'decided_at' => Carbon::now(),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Your explanation has been withdrawn.',
        ]);
    }

    /**
     * Today at the site this person clocks in at. Lateness is explained on the
     * day it happens, so this is the only date the page deals in.
     */
    protected function today(User $user): Carbon
    {
        $timezone = $user->loadMissing('location')->location->timezone ?? config('app.timezone');

        return Carbon::now()->setTimezone($timezone)->startOfDay();
    }

    /**
     * Today's late clock-in, when there is one and it has not been explained
     * yet, so the page can offer it rather than asking for a date.
     *
     * @param  array<int, string>  $explained
     * @return array<int, array<string, mixed>>
     */
    protected function unexplainedDays(int $userId, Carbon $today, array $explained): array
    {
        return Attendance::query()
            ->where('user_id', $userId)
            ->late()
            ->where('work_date', $today->toDateString())
            ->get()
            ->reject(fn (Attendance $day): bool => in_array($day->work_date->toDateString(), $explained, true))
            ->map(fn (Attendance $day): array => [
                'work_date' => $day->work_date->toDateString(),
                'day_label' => $day->work_date->format('D, j M Y'),
                'late_minutes' => $day->late_minutes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(LatenessRequest $late): array
    {
        return [
            'id' => $late->id,
            'work_date' => $late->work_date->toDateString(),
            'day_label' => $late->work_date->format('D, j M Y'),
            'minutes_late' => $late->minutes_late,
            'reason' => $late->reason,
            'status' => $late->status->value,
            'status_label' => $late->status->label(),
            'status_tone' => $late->status->tone(),
            'approvals_given' => $late->approvalsGiven(),
            'approvals_required' => $late->approvals_required,
            'decided_at' => $late->decided_at?->toIso8601String(),
            'created_at' => $late->created_at?->toIso8601String(),
            'trail' => $this->approvals->trail($late),
        ];
    }
}
