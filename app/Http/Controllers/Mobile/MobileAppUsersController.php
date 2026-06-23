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

            $user = MobileAppUser::create([
                'masjid_id' => $masjid->id,
                'device_id' => $request->input('device_id'),
                'user_agent' => $request->userAgent(),
                'last_active_at' => now(),
            ]);

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

        return response()->json(['status' => 'success'], Response::HTTP_OK);
    }

    public function masjidDetails(GetMasjidDetailsRequest $request)
    {
        try {
            $user = MobileAppUser::where('device_id', $request->input('device_id'))
                ->with('masjid.logo')
                ->first();

            return response()->json([
                'status' => 'success',
                'data' => $user->masjid
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
