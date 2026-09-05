<?php

namespace App\Http\Middleware;

use App\Imports\Contracts\Importer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards one import sheet with the same permission that guards editing those
 * records by hand.
 *
 * The import permission on the route group says somebody may use the import
 * pages at all. This says which sheets are theirs, so a bulk upload is never
 * a way into a page they cannot reach: an HR manager can load staff without
 * also being handed everyone's salary.
 */
class EnsureImportIsPermitted
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $importer = $request->route('importer');
        $user = $request->user();

        abort_unless(
            $importer instanceof Importer && $user !== null && $user->hasPermission($importer->permission()),
            403,
            'You do not have access to that.',
        );

        return $next($request);
    }
}
