<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanApprove
{
    /**
     * Guards the approvals page. Approvers always get in; so does a member of
     * staff who has been named relief officer on a request waiting on them.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->usesApprovals(), 403, 'Approver access only.');

        return $next($request);
    }
}
