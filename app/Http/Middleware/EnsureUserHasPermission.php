<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route with one of the permissions on the roles page. Several may be
 * listed, and holding any one of them is enough — a page that several roles
 * reach by different routes is the exception, not the rule.
 */
class EnsureUserHasPermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        $held = $user !== null && collect($permissions)
            ->map(fn (string $permission): Permission => Permission::from($permission))
            ->contains(fn (Permission $permission): bool => $user->hasPermission($permission));

        abort_unless($held, 403, 'You do not have access to that.');

        return $next($request);
    }
}
