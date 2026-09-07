<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who changed what, and when. Read-only by design: an audit trail somebody
 * can edit is not one.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'event' => $request->string('event')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'user' => $request->integer('user') ?: null,
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
        ];

        $audits = Audit::query()
            ->with('user:id,name,email')
            ->when($filters['event'], fn ($query, string $event) => $query->where('event', $event))
            ->when($filters['type'], fn ($query, string $type) => $query->where('auditable_type', $type))
            ->when($filters['user'], fn ($query, int $user) => $query->where('user_id', $user))
            ->when(
                $filters['from'],
                fn ($query, string $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()),
            )
            ->when(
                $filters['to'],
                fn ($query, string $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()),
            )
            ->newestFirst()
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Audit $audit): array => AuditTrail::payload($audit));

        return Inertia::render('admin/Audit', [
            'filters' => $filters,
            'audits' => $audits,
            // Only the types that have actually been written to, so the filter
            // never offers a choice that returns nothing.
            'types' => AuditTrail::recordedTypes(),
            'events' => AuditTrail::events(),
            'actors' => $this->actors(),
        ]);
    }

    /**
     * People who appear in the trail. Drawn from the trail itself rather than
     * the staff list, so the filter matches what is there to find.
     *
     * @return array<int, array{value: int, label: string}>
     */
    protected function actors(): array
    {
        $ids = Audit::query()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])
            ->all();
    }
}
