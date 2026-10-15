<?php

namespace App\Http\Middleware;

use App\Models\{ClassAssignment, Masjid};
use App\Services\Student\StudentAccess;
use App\Support\ClassSubjectMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StudentMarkLock
{
    public function handle(Request $request, Closure $next)
    {
        $id=(int)$request->route('masjid_id');
        if (! StudentAccess::enabled(ClassSubjectMode::loadedOrganisation($id))) return $next($request);
        return DB::transaction(function () use ($id,$request,$next) {
            Masjid::whereKey($id)->lockForUpdate()->firstOrFail();
            ClassAssignment::where('masjid_id',$id)->where('group_id',(int)$request->route('group_id'))->whereKey((int)$request->route('assignment_id'))->lockForUpdate()->firstOrFail();
            return $next($request);
        });
    }
}
