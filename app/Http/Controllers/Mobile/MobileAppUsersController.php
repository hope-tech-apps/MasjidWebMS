<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileAppUsersController extends Controller
{
    public function store(Request $request)
    {
        try {

            $request->validate([
                'masjid_id' => 'required|exists:masjids,id',
                'device_id' => 'required|string'
            ]);

            $masjid = Masjid::findOrFail($request['masjid_id']);

            $user = MobileAppUser::create([
                'masjid_id' => $masjid->id,
                'device_id' => $request['device_id'],
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $user
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request)
    {
        try {

            $request->validate([
                'masjid_id' => 'required|exists:masjids,id',
                'device_id' => 'required|exists:mobile_app_users,device_id'
            ]);

            $user = MobileAppUser::where('device_id', $request['device_id'])->first();
            $masjid = Masjid::findOrFail($request['masjid_id']);

            $user->masjid_id = $masjid->id;
            $user->device_id = $request['device_id'];
            $user->user_agent = $request->userAgent();
            $user->update();

            return response()->json([
                'status' => 'success',
                'data' => $user
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function masjidDetails(Request $request)
    {
        try {

            $request->validate([
                'device_id' => 'required|exists:mobile_app_users,device_id'
            ]);

            $user = MobileAppUser::where('device_id', $request['device_id'])->with('masjid.logo')->first();

            return response()->json([
                'status' => 'success',
                'data' => $user->masjid
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
