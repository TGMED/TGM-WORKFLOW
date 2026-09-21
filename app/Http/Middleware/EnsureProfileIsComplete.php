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
     * a password, and getting back out. Putting away the release notes and a
     * walkthrough go through too: both are things the page does around them,
     * write nothing to their record, and would otherwise fail in their face.
     *
     * Deciding on a request is here for somebody else's sake. A person named
     * as cover, or as the approver, holds a colleague's leave up for as long
     * as they cannot answer it, and the colleague has no way to hand it to
     * anybody else. Answering what was asked of you is not the same as using
     * the app on a half-filled record, so it goes through. Filing a request
     * for somebody else is, and stays behind the gate.
     *
     * @var list<string>
     */
    protected const ALLOWED = [
        'profile.*',
        'password.*',
        'logout',
        'whats-new.*',
        'tours.*',
        'approvals.index',
        'approvals.store',
    ];

    /**
     * Nobody uses the app on a half-filled record. An employee who has not
     * finished their profile is sent to the guided setup, whatever they asked
     * for: a step at a time, starting with the part that is required.
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

        // No toast: the setup page says why they are there.
        if ($request->isMethod('GET')) {
            return redirect()->route('profile.setup');
        }

        abort(403, 'Finish your profile before using the rest of the app.');
    }
}
