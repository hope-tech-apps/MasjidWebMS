<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\Users\GetMasjidDetailsRequest;
use App\Http\Requests\Mobile\Users\StoreMobileAppUserRequest;
use App\Http\Requests\Mobile\Users\UpdateMobileAppUserRequest;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileAppUsersController extends Controller
{
    public function store(StoreMobileAppUserRequest $request)
    {
        try {
            $masjid = Masjid::findOrFail($request->input('masjid_id'));
            $deviceId = (string) $request->input('device_id');

            // Registration is idempotent per install. `device_id` is UNIQUE, so an
            // install that never saw its first reply (a dropped connection, a 429
            // on the way back) used to hit the index and get a 500 on every later
            // launch, spending its network's allowance each time. An existing row
            // is refreshed and returned as it is: its masjid and its member claim
            // are not re-pointed here (PUT /user does that deliberately).
            $user = MobileAppUser::where('device_id', $deviceId)->first();

            if ($user === null) {
                try {
                    $user = MobileAppUser::create([
                        'masjid_id' => $masjid->id,
                        'device_id' => $deviceId,
                        'user_agent' => $request->userAgent(),
                        'last_active_at' => now(),
                    ]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // Two registrations of the same install raced and the other won.
                    $user = MobileAppUser::where('device_id', $deviceId)->firstOrFail();
                }
            }

            if (! $user->wasRecentlyCreated) {
                $user->forceFill([
                    'user_agent' => $request->userAgent(),
                    'last_active_at' => now(),
                ])->save();
            }

            return response()->json([
                'status' => 'success',
                'data' => $user
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateMobileAppUserRequest $request)
    {
        try {
            $user = MobileAppUser::where('device_id', $request->input('device_id'))->first();
            $masjid = Masjid::findOrFail($request->input('masjid_id'));

            $user->masjid_id = $masjid->id;
            $user->device_id = $request->input('device_id');
            $user->user_agent = $request->userAgent();
            $user->last_active_at = now();
            $user->update();

            return response()->json([
                'status' => 'success',
                'data' => $user
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lightweight heartbeat: marks a device active "now" so the server-side
     * prayer backstop knows it's NOT dark and skips it (its precise local
     * notifications are firing). Called by the app on launch + background
     * refresh. Idempotent, no body beyond device_id, fail-soft for unknown ids.
     */
    public function heartbeat(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'onesignal_subscription_id' => 'nullable|string',
        ]);

        $update = ['last_active_at' => now()];

        // The OneSignal subscription id is the only reliable push target
        // (external_id aliases don't resolve), so capture it whenever the app
        // reports it.
        if ($request->filled('onesignal_subscription_id')) {
            $update['onesignal_subscription_id'] = $request->input('onesignal_subscription_id');
        }

        MobileAppUser::where('device_id', $request->input('device_id'))->update($update);

        // Match the app's {status, data} envelope so the client decodes cleanly.
        return response()->json([
            'status' => 'success',
            'data' => ['updated' => true],
        ], Response::HTTP_OK);
    }

    public function masjidDetails(GetMasjidDetailsRequest $request)
    {
        try {
            $user = MobileAppUser::where('device_id', $request->input('device_id'))
                ->with('masjid.logo')
                ->first();

            return response()->json([
                'status' => 'success',
                'data' => $user->masjid?->makeHidden(Masjid::PUBLIC_DIRECTORY_DENYLIST)
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
