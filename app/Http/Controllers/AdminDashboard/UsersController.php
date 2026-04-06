<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\MatchOldUserPasswordRule;
use App\Rules\UserTypeRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class UsersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::where('type', 'User')->orWhere('type', 'MasjidAdmin')->with('avatar')->get();
        return response()->json([
            'status' => 'success',
            'data' => $users
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'email' => 'required|email|unique:users,email',
                'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
                'type' => ['required', new UserTypeRule()],
                'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:25600',
                'password' => [
                    'required',
                    'string',
                    'min:8',            // Minimum 8 characters
                    'max:20',           // Maximum 20 characters (optional)
                    'regex:/[A-Z]/',    // Must contain at least one uppercase letter
                    'regex:/[a-z]/',    // Must contain at least one lowercase letter
                    'regex:/[0-9]/',    // Must contain at least one number
                    'regex:/[@$!%*?&#]/', // Must contain at least one special character
                    'confirmed',        // Ensure password confirmation matches
                ]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($validator->passes()) {

                $user = User::create($request->only(
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

    /**
     * Display the specified resource.
     */
    public function show($user_id)
    {
        $user = User::with('avatar')->findOrFail($user_id);
        return response()->json([
            'status' => 'success',
            'data' => $user
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $user_id)
    {
        try {

            $user = User::findOrFail($user_id);
            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'email' => 'required|email',
                'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
                'type' => ['required', new UserTypeRule()],
                'avatar' => 'image|mimes:jpeg,png,jpg,gif,svg|max:25600',
                'old_password' => ['nullable', 'required_with:password', new MatchOldUserPasswordRule($user->id)],
                'password' => [
                    'nullable',
                    'string',
                    'min:8',                // Minimum 8 characters
                    'max:20',               // Maximum 20 characters (optional)
                    'regex:/[A-Z]/',        // Must contain at least one uppercase letter
                    'regex:/[a-z]/',        // Must contain at least one lowercase letter
                    'regex:/[0-9]/',        // Must contain at least one number
                    'regex:/[@$!%*?&#]/',   // Must contain at least one special character
                    'confirmed',            // Ensure password confirmation matches
                ]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($validator->passes()) {

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

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($user_id)
    {
        $user = User::with('avatar')->findOrFail($user_id);
        $user->forceDelete();
        return response()->json([
            'status' => 'success',
            'data' => $user
        ], Response::HTTP_OK);
    }

    /**
     * Archive the specified resource from storage.
     */
    public function moveToTrash($user_id)
    {
        $user = User::with('avatar')->findOrFail($user_id);
        $user->delete();
        return response()->json([
            'status' => 'success',
            'data' => $user
        ], Response::HTTP_OK);
    }

}
