<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class StudentRealmFence
{
    public function handle(Request $request, Closure $next)
    {
        $bearer=$request->bearerToken();
        $secret=$bearer !== null ? (str_contains($bearer,'|') ? explode('|',$bearer,2)[1] : $bearer) : '';
        if (str_starts_with($secret,'student_') && ! $request->is('api/student/*')) {
            return response()->api(403,'Student sessions can only use the student portal.');
        }
        return $next($request);
    }
}
