<?php

namespace App\Services\Student;

use App\Models\StudentAccount;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class StudentGuard
{
    public function __invoke(Request $request): ?StudentAccount
    {
        $raw=$request->bearerToken();
        if (! $raw) return null;
        $token=PersonalAccessToken::findToken($raw);
        if (! $token || ! $token->tokenable instanceof StudentAccount || $token->abilities !== ['student']) return null;
        $idle=(int)config('student.session.idle_minutes',15);
        $absolute=(int)config('student.session.absolute_minutes',120);
        if (! $token->created_at || $token->created_at->lte(now()->subMinutes($absolute))
            || ($token->last_used_at ?? $token->created_at)->lte(now()->subMinutes($idle))
            || ($token->expires_at !== null && $token->expires_at->lte(now()))) {
            $token->delete(); return null;
        }
        // No session-guard fallback. Student realm accepts bearer credentials only.
        $token->forceFill(['last_used_at'=>now()])->save();
        return $token->tokenable->withAccessToken($token);
    }
}
