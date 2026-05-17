<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\Users\GetMasjidDetailsRequest;
use App\Http\Requests\Mobile\Users\StoreMobileAppUserRequest;
use App\Http\Requests\Mobile\Users\UpdateMobileAppUserRequest;
use App\Models\Masjid;
use App\Models\MobileAppUser;
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
