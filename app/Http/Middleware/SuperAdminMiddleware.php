<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for SuperAdmin-only endpoints. The `type` comparison was already strict;
 * the added `instanceof User` closes the same actor-class hole documented on
 * UserAdminMiddleware — nothing here asserted the principal was a staff User
 * rather than some other authenticatable that happens to expose a `type`.
 *
 * The 401 envelope is deliberate and unchanged: this middleware answers
 * non-super callers with 401, which several clients depend on (the CRM-access
 * toggle deliberately does NOT use it precisely because it needs a 403 —
 * see .claude/rules/auth-permissions.md).
 */
class SuperAdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // `$user instanceof User` subsumes the previous Auth::check().
        if ($user instanceof User && $user->type === 'SuperAdmin') {
            return $next($request);
        } else {
            return response()->json([
                'status' => 'failed',
                'data' => 'Unauthorized.'
            ], Response::HTTP_UNAUTHORIZED);
        }
    }
}
