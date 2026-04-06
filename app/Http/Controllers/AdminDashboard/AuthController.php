<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\MatchOldUserPasswordRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string'
        ]);

        $user = User::where('email', $request->email)->with('avatar')->first();
        if (!(Hash::check($request->password, $user->password))) {
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
                'token' => $token
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
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function logout()
    {
        if (Auth::check() && Auth::user()) {
            Auth::guard('sanctum')->user()->tokens()->delete();
        }
    }

    public function updateProfile(Request $request)
    {
        try {

            $user = Auth::user();
            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'email' => 'required|email',
                'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
                'avatar' => 'image|mimes:jpeg,png,jpg,gif,svg|max:25600',
                'old_password' => ['nullable', 'required_with:password', new MatchOldUserPasswordRule($user->id)],
                'password' => [
                    'nullable',
                    'string',
                    'min:8',            // Minimum 8 characters
                    'max:20',           // Maximum 20 characters (optional)
                    'regex:/[A-Z]/',    // Must contain at least one uppercase letter
                    'regex:/[a-z]/',    // Must contain at least one lowercase letter
                    'regex:/[0-9]/',    // Must contain at least one number
                    'regex:/[@$!%*?&#]/', // Must contain at least one special character
                    'confirmed'        // Ensure password confirmation matches
                ]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($validator->passes()) {
                $user = User::findOrFail($user->id);
                $user->update($request->only(
                    'name',
                    'email',
                    'phone',
                    'type',
                    'password'
                ));

                if ($user && $request->hasFile('avatar')) {
                    $user->addMediaFromRequest('avatar')->toMediaCollection('avatars');
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $user->load('avatar')
                ], Response::HTTP_OK);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
