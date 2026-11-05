<?php

namespace App\Services;

use App\Contracts\Approvable;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PendingApprovalsReminder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A daily round-up of requests still waiting for a decision.
 *
 * Unlike escalation, which tells the people team once that something has
 * stalled, this writes to the approvers themselves, every working morning,
 * for as long as a request sits with them. Each approver gets one email
 * listing everything waiting on them, never one per request, and super admins
 * get the whole picture: every pending request and who it is sitting with.
 */
class ApprovalReminders
{
    public function __construct(protected RequestNotifier $notifier) {}

    /**
     * @return array{requests: int, approvers: int, admins: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $rows = $this->pendingRows($now);

        if ($rows->isEmpty()) {
            return ['requests' => 0, 'approvers' => 0, 'admins' => 0];
        }

        $admins = User::query()
            ->active()
            ->whereHas('roles', fn ($query) => $query->where('slug', Role::SUPER_ADMIN))
            ->get();

        $adminIds = $admins->pluck('id')->all();

        // Approvers who are not super admins get just their own list. A super
        // admin's overview already marks what is waiting on them.
        $approvers = 0;

        $rows
            ->flatMap(fn (array $row): array => $row['waiting_on_ids'])
            ->unique()
            ->reject(fn (int $id): bool => in_array($id, $adminIds, true))
            ->each(function (int $id) use ($rows, &$approvers): void {
                $approver = User::query()->active()->find($id);

                if ($approver === null) {
                    return;
                }

                $mine = $rows
                    ->filter(fn (array $row): bool => in_array($id, $row['waiting_on_ids'], true))
                    ->map(fn (array $row): array => $this->forRecipient($row, $id))
                    ->values()
                    ->all();

                $approver->notify(new PendingApprovalsReminder($mine, overview: false));
                $approvers++;
            });

        foreach ($admins as $admin) {
            $all = $rows
                // Nobody is reminded about their own request.
                ->reject(fn (array $row): bool => $row['requester_id'] === $admin->id)
                ->map(fn (array $row): array => $this->forRecipient($row, $admin->id))
                ->values()
                ->all();

            if ($all !== []) {
                $admin->notify(new PendingApprovalsReminder($all, overview: true));
            }
        }

        return [
            'requests' => $rows->count(),
            'approvers' => $approvers,
            'admins' => count($adminIds),
        ];
    }

    /**
     * Every request pending longer than the configured wait, flattened to
     * plain values so the queued notification carries no models.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function pendingRows(Carbon $now): Collection
    {
        $cutoff = $now->copy()->subHours((int) config('hr.approval_reminders.after_hours', 24));

        $rows = collect();

        foreach (RequestModule::cases() as $module) {
            /** @var class-string<Approvable&Model> $model */
            $model = $module->model();

            $model::query()
                ->where('status', RequestStatus::Pending->value)
                ->where('created_at', '<=', $cutoff)
                ->with('user')
                ->orderBy('created_at')
                ->get()
                ->each(function (Model $request) use ($rows, $now): void {
                    if (! $request instanceof Approvable) {
                        return;
                    }

                    $waitingOn = $this->notifier->waitingOn($request);

                    $rows->push([
                        'module' => $request->module()->noun(),
                        'requester_id' => $request->requester()->id,
                        'requester' => $request->requester()->name,
                        'summary' => $request->summary(),
                        'hours_waiting' => (int) $request->raisedAt()?->diffInHours($now),
                        'waiting_on_ids' => $waitingOn->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                        'waiting_on' => $waitingOn->pluck('name')->all(),
                    ]);
                });
        }

        return $rows->sortByDesc('hours_waiting')->values();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{module: string, requester: string, summary: string, hours_waiting: int, waiting_on: array<int, string>, waiting_on_you: bool}
     */
    protected function forRecipient(array $row, int $recipientId): array
    {
        return [
            'module' => $row['module'],
            'requester' => $row['requester'],
            'summary' => $row['summary'],
            'hours_waiting' => $row['hours_waiting'],
            'waiting_on' => $row['waiting_on'],
            'waiting_on_you' => in_array($recipientId, $row['waiting_on_ids'], true),
        ];
    }
}
