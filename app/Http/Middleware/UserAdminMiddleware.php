<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

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
        if (Auth::check() && in_array(Auth::user()->type, self::ADMIN_TYPES)) {
            return $next($request);
        } else {
            return response()->json([
                'status' => 'failed',
                'data' => 'Unauthorized.'
            ], Response::HTTP_UNAUTHORIZED);
        }
    }
}
