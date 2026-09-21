<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\Announcement;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\UpcomingEvents;
use App\Support\WhatsNew;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        protected ApprovalService $approvals,
        protected UpcomingEvents $events,
    ) {}

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
        $user = $request->user()?->loadMissing('location', 'roles.rolePermissions', 'profile', 'department', 'team');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $user->initials,
                    'avatar_url' => $user->profile?->avatarUrl(),
                    'employee_id' => $user->employee_id,
                    'department' => $user->department?->name,
                    'department_id' => $user->department_id,
                    'team' => $user->team?->name,
                    'position' => $user->position,
                    'roles' => $user->roles
                        ->sortBy('id')
                        ->map(fn ($role): array => ['slug' => $role->slug, 'name' => $role->name])
                        ->values()
                        ->all(),
                    'role_label' => $user->primaryRole()?->name,
                    'is_super_admin' => $user->isSuperAdmin(),
                    // Everything this person's role may do, so the nav and the
                    // page buttons offer exactly the doors that will open.
                    'permissions' => array_map(
                        fn (Permission $permission): string => $permission->value,
                        $user->permissions(),
                    ),
                    'can_approve' => $user->canApprove(),
                    'can_use_approvals' => $user->usesApprovals(),
                    'heads_department' => $user->headsADepartment(),
                    'clocks_in' => $user->clocksIn(),
                    'is_active' => $user->is_active,
                    // The nav hides everything but the profile while this is
                    // false, so the rail matches what the gate will allow.
                    'profile_complete' => $user->hasCompleteProfile(),
                    'location' => $user->location === null ? null : [
                        'id' => $user->location->id,
                        'name' => $user->location->name,
                        'city' => $user->location->city,
                    ],
                ],
            ],
            'pending_approvals' => fn (): int => $user === null
                ? 0
                : $this->approvals->inboxCount($user),
            // The rail that sits beside every page: the notices in force and
            // what is coming up. Both are closures, so a page that does not
            // render the rail never pays for them, and both are cheap enough
            // to carry on the pages that do.
            'noticeboard' => fn (): ?array => $user === null ? null : [
                'announcements' => Announcement::query()
                    ->live()
                    ->inReadingOrder()
                    ->limit(6)
                    ->get()
                    ->map(fn (Announcement $announcement): array => [
                        'id' => $announcement->id,
                        'title' => $announcement->title,
                        'body' => $announcement->body,
                        'is_pinned' => $announcement->is_pinned,
                        'published_at' => $announcement->published_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'events' => $this->events->forUser($user),
            ],
            // Walkthroughs this person has already been shown.
            'tours_seen' => $user === null ? [] : ($user->tours_seen ?? []),
            // Null once this person has read the current release's notes,
            // which is what keeps the popup to one showing each.
            'whats_new' => fn (): ?array => WhatsNew::forUser($user),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'toast' => fn () => $request->session()->get('toast'),
                'clock' => fn () => $request->session()->get('clock'),
            ],
        ];
    }
}
