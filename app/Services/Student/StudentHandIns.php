<?php

namespace App\Services\Student;

use App\Models\{AssignmentScore, ClassAssignment, GroupMembership, Masjid, StudentAccount, StudentHandIn, StudentWorkSetting};
use App\Support\{GroupResourceFiles, SchoolDateAuthority, StudentDates};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage, Validator};
use Illuminate\Validation\Rule;

final class StudentHandIns
{
    public static function payload(StudentHandIn $h): array
    {
        return ['id'=>(int)$h->id,'version'=>(int)$h->version,'kind'=>$h->kind,'link'=>$h->link,
            'file_name'=>$h->original_name,'mime_type'=>$h->mime_type,'size_bytes'=>$h->size_bytes,'late'=>$h->late,'submitted_at'=>$h->submitted_at->toISOString(),'submitted_at_label'=>StudentDates::instant($h->submitted_at,$h->masjid_id)];
    }

    public static function marked(ClassAssignment $work, GroupMembership $member): bool
    {
        return AssignmentScore::where('masjid_id',$work->masjid_id)->where('class_assignment_id',$work->id)->where('group_membership_id',$member->id)->exists();
    }

    public static function history(ClassAssignment $work, GroupMembership $member): array
    {
        return ['marked'=>self::marked($work,$member),'history'=>StudentHandIn::where('masjid_id',$work->masjid_id)->where('class_assignment_id',$work->id)
            ->where('group_membership_id',$member->id)->orderByDesc('version')->get()->map([self::class,'payload'])->all()];
    }

    public static function submit(Request $request, ClassAssignment $work, GroupMembership $member)
    {
        $mime=config('groups.resources.mime_types',[]);
        $data=Validator::make($request->all(),[
            'kind'=>['required',Rule::in(['file','photo','link'])],
            'link'=>['required_if:kind,link','prohibited_unless:kind,link','nullable','string','max:4000','url:http,https'],
            'file'=>['required_unless:kind,link','prohibited_if:kind,link','file','mimetypes:'.implode(',',$mime),'max:'.config('groups.resources.max_size_kb',8192)],
        ])->validate();
        if ($data['kind']==='photo' && ! in_array($request->file('file')?->getMimeType(),['image/jpeg','image/png'],true)) return response()->api(422,'A photo must be a JPEG or PNG image.');
        $written=null;
        try {
            return DB::transaction(function () use ($request,$work,$member,$data,&$written) {
                // Same school -> assignment mutex as the teacher's marking route.
                $school=Masjid::whereKey($work->masjid_id)->lockForUpdate()->firstOrFail();
                if (! StudentAccess::enabled($school)) return response()->api(404,'Student sign-in is not available.');
                $account=StudentAccount::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                if (! $account->enabled || ! $account->tokens()->whereKey($request->user()->currentAccessToken()->id)->exists()) return response()->api(401,'Student sign-in is required.');
                ClassAssignment::whereKey($work->id)->lockForUpdate()->firstOrFail();
                if (! StudentAccess::memberships($work->masjid_id,$account->contact_id)->whereKey($member->id)->exists()) return response()->api(404,'Student class not found.');
                $setting=StudentWorkSetting::where('masjid_id',$work->masjid_id)->where('class_assignment_id',$work->id)->first();
                if (! $setting?->students_hand_in) return response()->api(403,'This work does not accept student hand-ins.');
                if (self::marked($work,$member)) return response()->api(409,'This work has been marked. It can no longer be replaced.');
                $attrs=['group_id'=>$member->group_id,'masjid_id'=>$work->masjid_id,'class_assignment_id'=>$work->id,'group_membership_id'=>$member->id,'student_contact_id'=>$member->contact_id,
                    'version'=>1+(int)StudentHandIn::where('class_assignment_id',$work->id)->where('group_membership_id',$member->id)->max('version'),
                    'kind'=>$data['kind'],'link'=>$data['kind']==='link'?$data['link']:null,'submitted_at'=>now(),
                    'late'=>$setting->due_on !== null && SchoolDateAuthority::for($work->masjid_id)->today() > $setting->due_on->toDateString()];
                if ($data['kind'] !== 'link') {
                    $written=GroupResourceFiles::studentUpload($member->group,$request->file('file'));
                    $attrs=array_merge($attrs,$written);
                }
                return response()->api(200,'Work handed in.',self::payload(StudentHandIn::create($attrs)));
            });
        } catch (\Throwable $e) {
            if ($written) Storage::disk($written['disk'])->delete($written['path']);
            throw $e;
        }
    }

    public static function download(StudentHandIn $h)
    {
        abort_if($h->path === null || $h->disk !== config('groups.resources.disk','local'),404);
        abort_unless(Storage::disk($h->disk)->exists($h->path),404);
        return Storage::disk($h->disk)->download($h->path,$h->original_name,['Content-Type'=>$h->mime_type,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }
}
