<?php

namespace App\Http\Middleware;

use App\Support\ClassSubjectMode;
use Closure;
use Illuminate\Http\Request;

/** Owns the capability memo for exactly one HTTP kernel invocation. */
final class ClassSubjectHttpRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        ClassSubjectMode::clear($request);
        $request->attributes->set(ClassSubjectMode::HTTP, true);
        try {
            return $next($request);
        } finally {
            ClassSubjectMode::clear($request);
        }
    }
}
