<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the TGM HR Agent's server in, and nobody else. The agent sends the
 * shared token as a bearer token. With no token configured the API is off.
 */
class EnsureHrAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.hr_agent.token');

        if ($expected === '') {
            return response()->json(['error' => 'The HR Agent API is switched off (HR_AGENT_API_TOKEN is not set).'], 503);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'Not authorised.'], 401);
        }

        return $next($request);
    }
}
