<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\{Masjid, StudentAccount, StudentSignInLog};
use App\Services\Student\StudentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, RateLimiter, Validator};

final class AuthController extends Controller
{
    public static function passwordRules(): array
    {
        return ['bail','required','string','min:'.config('student.password_min_length',10),'max:72','not_regex:/\\x00/','regex:/[a-zA-Z]/','regex:/[0-9]/','confirmed', function ($attribute,$value,$fail) {
            if (strlen($value)>72) $fail('Use a password of at most 72 bytes.');
        }];
    }

    public static function session(StudentAccount $account): array
    {
        return ['token'=>$account->createToken('student',['student'],now()->addMinutes(config('student.session.absolute_minutes',120)))->plainTextToken,
            'must_change_password'=>(bool)$account->must_change_password];
    }

    public function signIn(Request $request, int $masjid_id)
    {
        $school=Masjid::find($masjid_id);
        if (! StudentAccess::enabled($school)) return response()->api(404,'Student sign-in is not available.');
        app(\App\Support\TenantContext::class)->set((int)$school->id);
        $valid=Validator::make($request->all(),['username'=>['required','string','max:40','regex:/^[A-Za-z0-9._-]+$/'],'password'=>['required','string','max:128']])->passes();
        $valid=$valid && strlen($request->input('password')) <= 72 && ! str_contains($request->input('password'), chr(0));
        $username=strtolower(trim(is_string($request->input('username')) ? mb_substr($request->input('username'),0,40) : ''));
        $data=['password'=>is_string($request->input('password')) ? $request->input('password') : ''];
        return DB::transaction(function () use ($request,$masjid_id,$data,$username,$valid) {
            $school=Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            if (! StudentAccess::enabled($school)) return response()->api(404,'Student sign-in is not available.');
            $account=StudentAccount::withoutMasjidScope()->where('masjid_id',$masjid_id)->where('username',$username)->lockForUpdate()->first();
            $window=(int)config('student.login.window_seconds',900);
            $byName='student-name:'.hash('sha256',$masjid_id.':'.$username);
            $byIp='student-ip:'.hash('sha256',(string)$request->ip());
            $nameCount=RateLimiter::hit($byName,$window); $ipCount=RateLimiter::hit($byIp,$window);
            $outcome='failed'; $status=401; $sentence='The username or password is incorrect.';
            if ($nameCount > config('student.login.per_username',5) || $ipCount > config('student.login.per_ip',50)) {
                $outcome='locked'; $status=429; $sentence='Too many sign-in attempts. Try again in 15 minutes.';
            } else {
                $hash=$account?->password ?? \Illuminate\Support\Facades\Cache::remember('student-password-dummy',3600,fn()=>Hash::make(\Illuminate\Support\Str::random(64)));
                $matches=Hash::check($valid ? $data['password'] : '',$hash);
                if ($valid && $matches && $account && $account->enabled && $account->password !== null) {
                    if (! StudentAccess::eligible($masjid_id,$account->contact_id)) {
                        $outcome='ineligible'; $status=403; $sentence=StudentAccess::refusal($masjid_id);
                    } else {
                        $outcome='success'; $account->forceFill(['last_sign_in_at'=>now()])->save();
                    }
                }
            }
            StudentSignInLog::create(['masjid_id'=>$masjid_id,'contact_id'=>$account?->contact_id,'username'=>$username,
                'ip_address'=>$request->ip(),'user_agent'=>mb_substr((string)$request->userAgent(),0,2000),'outcome'=>$outcome,'attempted_at'=>now()]);
            if ($outcome === 'success') return response()->api(200,'',self::session($account),['Cache-Control'=>'private, no-store']);
            return response()->api($status,$sentence,[],['Cache-Control'=>'private, no-store']);
        });
    }

    public function password(Request $request, int $masjid_id)
    {
        $data=Validator::make($request->all(),['current_password'=>['required','string','max:128'],'password'=>self::passwordRules()])->validate();
        return DB::transaction(function () use ($request,$data,$masjid_id) {
            $school=Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            if (! StudentAccess::enabled($school)) return response()->api(404,'Student sign-in is not available.');
            $account=StudentAccount::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            // A reset/disable that won this lock revokes the authorizing token.
            if (! $account->enabled || ! $account->tokens()->whereKey($request->user()->currentAccessToken()->id)->exists()) return response()->api(401,'Student sign-in is required.');
            if (! Hash::check($data['current_password'],$account->password ?? '')) return response()->api(422,'Your current password is incorrect.');
            if (Hash::check($data['password'],$account->password)) return response()->api(422,'Choose a different password from your current password.');
            $account->forceFill(['password'=>Hash::make($data['password']),'must_change_password'=>false])->save();
            $account->tokens()->delete();
            return response()->api(200,'',self::session($account),['Cache-Control'=>'private, no-store']);
        });
    }

    public function signOut(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->api(200,'Signed out.');
    }
}
