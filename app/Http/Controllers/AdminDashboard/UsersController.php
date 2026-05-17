<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\StoreUserRequest;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Models\User;
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
    public function store(StoreUserRequest $request)
    {
        try {
            $user = User::create($request->safe()->only([
                'name', 'email', 'phone', 'type', 'password',
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
    public function update(UpdateUserRequest $request, $user_id)
    {
        try {
            $user = User::findOrFail($user_id);

            $user->update($request->safe()->only([
                'name', 'email', 'phone', 'type', 'password',
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
