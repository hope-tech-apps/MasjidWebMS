<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the teacher API (routes/teacher.php): the caller must be a staff User
 * whose `users.type` is 'Teacher'.
 *
 * The exact counterpart of UserAdminMiddleware, and deliberately a SEPARATE gate
 * rather than adding 'Teacher' to UserAdminMiddleware::ADMIN_TYPES — a teacher must
 * NOT reach the admin API at all (donations, roster mutation, properties). This is
 * the whole-realm boundary; per-CLASS authority ("is this teacher assigned to THIS
 * group") is a second, finer check done in the controllers via GroupAudience.
 *
 * `instanceof User` before reading `type` for the same reason UserAdminMiddleware
 * documents: Sanctum's guard admits a live `web` session before it checks a token,
 * and a second authenticatable model (the family Contact) now exists, so the gate
 * asserts the principal is a User and not merely something carrying a `type`. The
 * 401 body is the legacy envelope the SPA switches on — identical to the admin gate,
 * so a client cannot tell which door refused it.
 */
class TeacherMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && $user->type === 'Teacher') {
            return $next($request);
        }

        return response()->json([
            'status' => 'failed',
            'data' => 'Unauthorized.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
