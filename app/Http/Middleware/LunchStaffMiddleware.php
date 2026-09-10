<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the lunch realm (routes/lunch.php): the caller must be a staff User
 * whose `users.type` is 'LunchStaff'.
 *
 * A SEPARATE gate rather than an extra entry in UserAdminMiddleware::ADMIN_TYPES,
 * for the same reason TeacherMiddleware is separate: the whole point of this login
 * is that it CANNOT reach the admin API. Widening the admin gate would hand a
 * kitchen volunteer the donation ledger, the member directory and the masjid's
 * Stripe settings in one edit.
 *
 * `instanceof User` before reading `type` because Sanctum's guard admits a live
 * `web` session before it checks a token, and a second authenticatable model (the
 * family Contact) exists — so the gate asserts the principal is a User rather than
 * merely something carrying a `type`. The 401 body is the legacy envelope the SPA
 * switches on, identical to the admin gate, so a client cannot tell which door
 * refused it.
 */
class LunchStaffMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && $user->type === User::TYPE_LUNCH_STAFF) {
            return $next($request);
        }

        return response()->json([
            'status' => 'failed',
            'data' => 'Unauthorized.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
