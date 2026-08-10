<?php

namespace App\Http\Middleware;

use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(protected ApprovalService $approvals) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user()?->loadMissing('location', 'role');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $user->initials,
                    'employee_id' => $user->employee_id,
                    'department' => $user->department,
                    'position' => $user->position,
                    'role' => $user->role->slug,
                    'role_label' => $user->role->name,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'can_approve' => $user->canApprove(),
                    'clocks_in' => $user->clocksIn(),
                    'is_active' => $user->is_active,
                    'location' => $user->location === null ? null : [
                        'id' => $user->location->id,
                        'name' => $user->location->name,
                        'city' => $user->location->city,
                    ],
                ],
            ],
            'pending_approvals' => fn (): int => $user === null || ! $user->canApprove()
                ? 0
                : $this->approvals->outstandingCount(LeaveRequest::class, $user)
                    + $this->approvals->outstandingCount(LatenessRequest::class, $user),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'toast' => fn () => $request->session()->get('toast'),
                'clock' => fn () => $request->session()->get('clock'),
            ],
        ];
    }
}
