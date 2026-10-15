<?php

namespace App\Http\Middleware;

use App\Models\{Masjid, StudentAccount};
use App\Services\Student\StudentAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

final class EnsureStudentAccess
{
    public function handle(Request $request, Closure $next)
    {
        $account=$request->user();
        if (! $account instanceof StudentAccount || ! $account->enabled || ! $account->tokenCan('student')) return response()->api(401,'Student sign-in is required.');
        $school=Masjid::find($account->masjid_id);
        if (! StudentAccess::enabled($school)) return response()->api(404,'Student sign-in is not available.');
        if ((int)$request->route('masjid_id') !== (int)$account->masjid_id) return response()->api(404,'Page not found.');
        app(TenantContext::class)->set((int)$account->masjid_id);
        if (! StudentAccess::eligible($account->masjid_id,$account->contact_id)) return response()->api(403,StudentAccess::refusal($account->masjid_id));
        if ($account->must_change_password && ! in_array($request->route()->getName(),['student.password','student.sign-out'],true)) return response()->api(403,'Change your one-time password before opening the student portal.');
        $response=$next($request);
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }
}
