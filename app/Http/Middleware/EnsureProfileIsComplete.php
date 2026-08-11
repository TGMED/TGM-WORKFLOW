<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileIsComplete
{
    /**
     * Routes someone with an unfinished profile may still reach. Anything that
     * would leave them stuck otherwise: the profile pages themselves, changing
     * a password, and getting back out.
     *
     * @var list<string>
     */
    protected const ALLOWED = [
        'profile.*',
        'password.*',
        'logout',
    ];

    /**
     * Nobody uses the app on a half-filled record. An employee who has not
     * finished their profile is sent back to it, whatever they asked for.
     *
     * Super admins are exempt: they administer the system rather than appear
     * on the payroll, so there is no HR record for them to keep.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $request->routeIs(...self::ALLOWED) || $user->hasCompleteProfile()) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            return redirect()
                ->route('profile.edit')
                ->with('toast', [
                    'type' => 'info',
                    'message' => 'Finish your profile to carry on. We only need a few details.',
                ]);
        }

        abort(403, 'Finish your profile before using the rest of the app.');
    }
}
