<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{Contact, Group, Masjid, StudentAccount, StudentSignInLog, StudentSignInSetting};
use App\Services\Student\StudentAccess;
use App\Support\{GradeLevel, StudentDates, TenantContext};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StudentAccountsController extends Controller
{
    private function school(): Masjid
    {
        $school=Masjid::findOrFail(app(TenantContext::class)->get());
        abort_unless(StudentAccess::enabled($school),404);
        return $school;
    }

    public function settings()
    {
        $school=$this->school();
        return response()->api(200,'',['from_grade'=>StudentAccess::fromGrade($school->id),'grades'=>GradeLevel::LEVELS]);
    }

    public function saveSettings(Request $request)
    {
        $school=$this->school();
        $data=Validator::make($request->all(),['from_grade'=>['required',Rule::in(GradeLevel::LEVELS)]])->validate();
        return DB::transaction(function () use ($school,$data) {
            Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            StudentSignInSetting::updateOrCreate(['masjid_id'=>$school->id],$data);
            // Threshold changes sign everyone out, including already-open sessions.
            foreach(StudentAccount::where('masjid_id',$school->id)->get() as $a) $a->tokens()->delete();
            return response()->api(200,'Student sign-in settings saved.',$data);
        });
    }

    public static function status(?StudentAccount $a): array
    {
        return ['has_account'=>$a !== null,'username'=>$a?->username,'enabled'=>$a !== null && $a->enabled,'last_sign_in_at'=>$a?->last_sign_in_at?->toISOString(),'last_sign_in_label'=>$a ? StudentDates::instant($a->last_sign_in_at,$a->masjid_id) : null];
    }

    public function index(int $masjid_id, int $group_id)
    {
        $school=$this->school(); $group=Group::where('masjid_id',$school->id)->where('kind','class')->findOrFail($group_id);
        $members=$group->memberships()->participants()->where(fn($q)=>$q->whereNull('left_on')->orWhereIn('contact_id',StudentAccount::where('masjid_id',$school->id)->select('contact_id')))->with('contact:id,first_name,last_name')->get();
        $accounts=StudentAccount::where('masjid_id',$school->id)->whereIn('contact_id',$members->pluck('contact_id'))->get()->keyBy('contact_id');
        return response()->api(200,'',$members->map(fn ($m) => ['contact_id'=>(int)$m->contact_id,'name'=>$m->contact ? trim($m->contact->first_name.' '.$m->contact->last_name) : 'Former student',
            'grade'=>$m->grade_label,'eligible'=>!$m->hasLeft() && StudentAccess::eligible($school->id,$m->contact_id),'account'=>self::status($accounts->get($m->contact_id))])->values());
    }

    private function issue(Masjid $school, Contact $child, string $username): array
    {
        if (! StudentAccess::eligible($school->id,$child->id)) throw new \DomainException(StudentAccess::refusal($school->id));
        $password='S6'.Str::random(14);
        $a=StudentAccount::where('masjid_id',$school->id)->where('contact_id',$child->id)->lockForUpdate()->first();
        if (! $a) $a=new StudentAccount(['masjid_id'=>$school->id,'contact_id'=>$child->id]);
        $a->forceFill(['username'=>$username,'password'=>Hash::make($password),'enabled'=>true,'must_change_password'=>true])->save();
        $a->tokens()->delete();
        return self::status($a)+['name'=>trim($child->first_name.' '.$child->last_name),'one_time_password'=>$password];
    }

    public function reset(Request $request, int $masjid_id, int $contact_id)
    {
        $school=$this->school(); $child=Contact::where('masjid_id',$school->id)->findOrFail($contact_id);
        $data=Validator::make($request->all(),['username'=>['required','string','min:3','max:40','regex:/^[A-Za-z0-9._-]+$/']])->validate();
        $username=strtolower(trim($data['username']));
        try {
            return DB::transaction(function () use ($school,$child,$username) {
                $locked=Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
                if (! StudentAccess::enabled($locked)) return response()->api(404,'Student sign-in is not available.');
                if (StudentAccount::where('masjid_id',$school->id)->where('username',$username)->where('contact_id','!=',$child->id)->exists()) return response()->api(422,'That username is already used at this school.');
                return response()->api(200,'Print or copy this password now. It will not be shown again.',$this->issue($school,$child,$username),['Cache-Control'=>'private, no-store']);
            });
        } catch (\DomainException $e) { return response()->api(403,$e->getMessage()); }
    }

    public function disable(int $masjid_id, int $contact_id)
    {
        $school=$this->school();
        return DB::transaction(function () use ($school,$contact_id) {
            Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $a=StudentAccount::where('masjid_id',$school->id)->where('contact_id',$contact_id)->lockForUpdate()->firstOrFail();
            $a->forceFill(['enabled'=>false])->save(); $a->tokens()->delete();
            return response()->api(200,'Student account turned off.');
        });
    }

    /** Called under the school lock so concurrent slip/reset requests cannot claim the same name. */
    private function defaultUsername(Masjid $school, Contact $child): string
    {
        $clean=fn ($name) => preg_replace('/[^a-z0-9]/','',strtolower(Str::ascii((string)$name)));
        // Keep both names when either is long, with room for the first collision suffix.
        $base=trim(substr($clean($child->first_name),0,19).'.'.substr($clean($child->last_name),0,19),'.');
        if (strlen($base) < 3) $base='student'.($base !== '' ? '.'.$base : '');
        $base=substr($base,0,40);
        $username=$base; $suffix=2;
        while (StudentAccount::where('masjid_id',$school->id)->where('username',$username)->exists()) {
            $number=(string)$suffix++;
            $username=substr($base,0,40-strlen($number)).$number;
        }
        return $username;
    }

    public function slips(Request $request, int $masjid_id, int $group_id)
    {
        $school=$this->school(); $group=Group::where('masjid_id',$school->id)->where('kind','class')->findOrFail($group_id);
        $data=Validator::make($request->all(),['contact_ids'=>['required','array','min:1','max:100'],'contact_ids.*'=>['required','integer','distinct']])->validate();
        try {
            return DB::transaction(function () use ($school,$group,$data) {
                $locked=Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
                if (! StudentAccess::enabled($locked)) return response()->api(404,'Student sign-in is not available.');
                $members=$group->memberships()->participants()->current()->whereIn('contact_id',$data['contact_ids'])->with('contact')->get();
                if ($members->count() !== count($data['contact_ids'])) return response()->api(404,'Student not found in this class.');
                $slips=[];
                foreach ($members as $m) {
                    $username=StudentAccount::where('masjid_id',$school->id)->where('contact_id',$m->contact_id)->value('username') ?? $this->defaultUsername($school,$m->contact);
                    $slips[]=$this->issue($school,$m->contact,$username);
                }
                return response()->api(200,'These passwords replace the selected students’ old passwords. Print them now.',['class_name'=>$group->name,'slips'=>$slips],['Cache-Control'=>'private, no-store']);
            });
        } catch (\DomainException $e) { return response()->api(403,$e->getMessage()); }
    }

    public function logs()
    {
        $school=$this->school();
        return response()->api(200,'',StudentSignInLog::where('masjid_id',$school->id)->orderByDesc('id')->limit(200)->get(['username','outcome','attempted_at']));
    }
}
