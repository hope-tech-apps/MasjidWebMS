<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin API: the caller must be a staff User of an admin `type`.
 *
 * `users.type` remains the source of truth (.claude/rules/auth-permissions.md);
 * the two hardenings below do not change who passes today, they remove the two
 * ways this check could stop meaning what it says once a SECOND authenticatable
 * model exists:
 *
 *  - `instanceof User` — the check reads a `type` property off whatever the
 *    active guard resolved. Nothing here previously asserted that the principal
 *    was even a User, so any authenticatable carrying a `type` of 'SuperAdmin'
 *    would have passed. `auth.guards.sanctum.provider` is now pinned to `users`
 *    (config/auth.php), so a foreign tokenable is already rejected inside
 *    Sanctum before reaching here — this is the second, independent layer, and
 *    it also covers guards that are NOT sanctum (a session, or the `family`
 *    guard planned in T-015c, which rebinds the default guard `Auth::user()`
 *    reads from).
 *  - strict `in_array` — a non-strict comparison against a string list is a
 *    loose-comparison hazard; it holds today only because PHP 8 changed
 *    string<->non-string juggling. Pin it rather than rely on that.
 *
 * The 401 body is the legacy envelope the admin SPA switches on; it must not
 * change shape. See docs/t015-parent-identity-design.md §5.
 */
class UserAdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */

    const ADMIN_TYPES = ['SuperAdmin', 'MasjidAdmin'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // `$user instanceof User` subsumes the previous Auth::check(): an
        // unauthenticated request resolves to null, which is not an instance.
        if ($user instanceof User && in_array($user->type, self::ADMIN_TYPES, true)) {
            return $next($request);
        } else {
            return response()->json([
                'status' => 'failed',
                'data' => 'Unauthorized.'
            ], Response::HTTP_UNAUTHORIZED);
        }
    }
}
