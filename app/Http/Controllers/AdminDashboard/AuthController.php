<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Requests\Admin\Auth\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->input('email'))->with('avatar')->first();
        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'invalid credentials']);
        }

        $token = $user->createToken('login-token')->plainTextToken;

        if ($user->type === 'MasjidAdmin') {
            $user->masjid;
            if ($user->masjid) {
                $user->masjid->logo = $user->masjid->logo()->first();
            } else {
                Auth::logout();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Sorry, you don't have a related masjid to your account."
                ], Response::HTTP_OK);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ], Response::HTTP_OK);
    }

    public function user()
    {
        try {
            if (Auth::check() && Auth::user()) {
                $user = Auth::user();

                $user->avatar = $user->avatar()->first();

                if ($user->type === 'MasjidAdmin') {
                    $user->masjid;
                    if ($user->masjid) {
                        $user->masjid->logo = $user->masjid->logo()->first();
                    } else {
                        Auth::logout();
                        return response()->json([
                            'status' => 'failed',
                            'message' => "Sorry, you don't have a related masjid to your account."
                        ], Response::HTTP_OK);
                    }
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $user
                ], Response::HTTP_OK);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function logout()
    {
        if (Auth::check() && Auth::user()) {
            Auth::guard('sanctum')->user()->tokens()->delete();
        }
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        try {
            $authUser = Auth::user();
            $user = User::findOrFail($authUser->id);

            $user->update($request->safe()->only([
                'name', 'email', 'phone', 'password',
            ]));

            if ($user && $request->hasFile('avatar')) {
                $user->addMediaFromRequest('avatar')->toMediaCollection('avatars');
            }

            return response()->json([
                'status' => 'success',
                'data' => $user->load('avatar')
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
