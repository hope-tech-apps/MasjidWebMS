<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\AppVolunteerSetting;
use App\Support\{AppVolunteer, TenantContext};
use Illuminate\Http\Request;

class AppVolunteerController extends Controller
{
    public function index()
    {
        $row=AppVolunteerSetting::where('masjid_id',app(TenantContext::class)->get())->first();
        return response()->json(['status'=>'success','data'=>['title'=>$row?->title ?? 'Volunteer','url'=>$row?->url]]);
    }
    public function update(Request $request)
    {
        $data=$request->validate([
            'title'=>['required','string','max:30'],
            'url'=>['present','nullable','string','max:500',function ($attribute,$value,$fail) {
                $parts=is_string($value) ? parse_url($value) : false;
                $host=$parts['host'] ?? '';
                if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !filter_var($value,FILTER_VALIDATE_URL) || !filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[^\x21-\x7e]/',$value)) $fail('Enter a valid https address with a host and no sign-in details.');
            }],
        ]);
        AppVolunteer::save(app(TenantContext::class)->get(),$data);
        return $this->index();
    }
}
