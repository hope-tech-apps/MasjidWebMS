<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Requests\Admin\Users\InviteUserRequest;
use App\Models\Masjid;
use App\Services\Auth\AccountAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * Create a staff account and email them a set-your-own-password link.
     *
     * The platform never learns the credential. A random 40-character secret is
     * written so the row is never password-less, and it is discarded unread —
     * the only way into the account is the emailed token.
     *
     * Optionally makes the new user the owning admin of one organisation, which
     * is what actually grants them access: with tenancy.multi_membership off,
     * TenantResolver derives an admin's grant from `masjids.user_id`, and an
     * org whose user_id is null cannot be reached by any MasjidAdmin at all.
     * That is the state NAFIS, MEC and Al-Razi were all left in by the
     * provisioning wizard.
     */
    public function inviteNew(InviteUserRequest $request, AccountAccessService $access)
    {
        $data = $request->safe()->only(['name', 'email', 'phone', 'type']);
        $masjidId = $request->input('masjid_id');

        $user = null;

        DB::transaction(function () use ($data, $masjidId, &$user) {
            $user = User::create($data + ['password' => Str::password(40)]);

            if ($masjidId) {
                $masjid = Masjid::withoutGlobalScopes()->findOrFail($masjidId);
                $masjid->user_id = $user->id;
                $masjid->save();
            }
        });

        $orgName = $masjidId
            ? optional(Masjid::withoutGlobalScopes()->find($masjidId))->name
            : null;

        $sent = $access->invite($user, $orgName);

        return response()->json([
            'status' => 'success',
            'message' => $sent
                ? 'Account created. An invitation to set their own password is on its way to '.$user->email.'.'
                : 'Account created, but it has no email address, so no invitation could be sent.',
            'data' => $user->fresh(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Re-send the set-your-own-password link for an existing account — the
     * "they never got it" and "they are locked out" path. Issuing a fresh token
     * invalidates the previous one, so an old link in an old inbox stops working.
     */
    public function invite($user_id, AccountAccessService $access)
    {
        $user = User::findOrFail($user_id);

        $orgName = optional(Masjid::withoutGlobalScopes()->where('user_id', $user->id)->first())->name;

        if (! $access->invite($user, $orgName)) {
            return response()->json([
                'status' => 'error',
                'message' => 'That account has no email address, so there is nowhere to send an invitation.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'An invitation is on its way to '.$user->email.'. It expires in an hour.',
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        try {
            $data = $request->safe()->only([
                'name', 'email', 'phone', 'type', 'password',
            ]);

            // An archived (soft-deleted) user may already own this email. The store
            // validation ignores trashed users, so handle that case gracefully here by
            // restoring the archived account and updating it with the submitted details.
            $archivedUser = User::onlyTrashed()->where('email', $data['email'])->first();

            if ($archivedUser) {
                $archivedUser->restore();
                $archivedUser->update($data);
                $user = $archivedUser;
                $restored = true;
            } else {
                $user = User::create($data);
                $restored = false;
            }

            if ($user && $request->hasFile('avatar')) {
                $user->clearMediaCollection('avatars');
                $user->addMediaFromRequest('avatar')->toMediaCollection('avatars');
            }

            return response()->json([
                'status' => 'success',
                'message' => $restored
                    ? 'This email belonged to an archived user. That account has been restored and updated with the new details.'
                    : 'User created successfully.',
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
