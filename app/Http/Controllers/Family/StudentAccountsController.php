<?php

namespace App\Http\Controllers\Family;

use App\Models\{Group, Masjid, StudentAccount};
use App\Services\Student\StudentAccess;
use App\Support\TenantContext;

final class StudentAccountsController extends FamilyController
{
    public function index()
    {
        $id=(int)app(TenantContext::class)->get();
        abort_unless(StudentAccess::enabled(Masjid::find($id)),404);
        $result=[];
        foreach(Group::where('masjid_id',$id)->where('kind','class')->whereHas('memberships',fn($q)=>$q->where('contact_id',$this->contact()->id))->get() as $group) {
            // The same audience decision as the family's marks page, never name/email matching.
            foreach($group->memberships()->participants()->whereIn('contact_id',$this->wardContactIds($group))->get() as $m) {
                if (! $this->audience->mayReceiveAwardsAbout($this->contact(),$group,$m)) continue;
                $a=StudentAccount::where('masjid_id',$id)->where('contact_id',$m->contact_id)->first();
                $result[]=['membership_id'=>(int)$m->id]+\App\Http\Controllers\AdminDashboard\StudentAccountsController::status($a);
            }
        }
        return response()->api(200,'',$result,['Cache-Control'=>'private, no-store']);
    }
}
