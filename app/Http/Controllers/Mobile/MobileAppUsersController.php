<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\Users\GetMasjidDetailsRequest;
use App\Http\Requests\Mobile\Users\StoreMobileAppUserRequest;
use App\Http\Requests\Mobile\Users\UpdateMobileAppUserRequest;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Support\AppClientHeader;
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

            // What the handset says it is running, body first then the
            // X-Manara-App header, and ONLY the fields that carry a value — a
            // pre-R1 build sends none of them and must not blank what a later
            // build recorded. See App\Support\AppClientHeader::resolve.
            $client = AppClientHeader::resolve($request);

            if ($user === null) {
                try {
                    $user = MobileAppUser::create([
                        'masjid_id' => $masjid->id,
                        'device_id' => $deviceId,
                        'user_agent' => $request->userAgent(),
                        'last_active_at' => now(),
                    ] + $client);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // Two registrations of the same install raced and the other won.
                    $user = MobileAppUser::where('device_id', $deviceId)->firstOrFail();
                }
            }

            if (! $user->wasRecentlyCreated) {
                $user->forceFill([
                    'user_agent' => $request->userAgent(),
                    'last_active_at' => now(),
                ] + $client)->save();
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->devicePayload($user)
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

            // Assigned one by one rather than filled, so the absent fields are
            // never touched: this verb is how an app re-points a handset at
            // another organisation, and a build too old to send its identity
            // must not erase the identity a newer one recorded.
            foreach (AppClientHeader::resolve($request) as $field => $value) {
                $user->{$field} = $value;
            }

            $user->update();

            return response()->json([
                'status' => 'success',
                'data' => $this->devicePayload($user)
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
     *
     * It also carries the build telemetry (`app_platform`/`app_version`/
     * `app_build`, body or `X-Manara-App`), because it is the one call an
     * install that never re-registers still makes — so it is the only place the
     * reading stays current as phones update. Optional in every sense: a
     * request that carries none of them writes none of them, and a malformed
     * header is ignored rather than refused. Nothing here may ever start
     * REJECTING a heartbeat over telemetry — a skipped heartbeat means the
     * prayer backstop treats a live phone as dark and double-notifies it.
     */
    public function heartbeat(Request $request)
    {
        // Deliberately NOT extended with AppClientHeader::rules(). The two
        // registration verbs validate the telemetry fields and may 422 on them;
        // this one must not. A 422 here is a heartbeat that did not land, and a
        // heartbeat that does not land is a live phone the prayer backstop
        // treats as dark and double-notifies. AppClientHeader::resolve() applies
        // the same platform and length limits by DROPPING what it cannot use,
        // so nothing oversized reaches a column down this path either.
        $request->validate([
            'device_id' => 'required|string',
            'onesignal_subscription_id' => 'nullable|string',
        ]);

        $update = ['last_active_at' => now()];

        // The heartbeat is the ONLY call a settled install makes regularly, so
        // it is where the build reading is kept current — and where the
        // never-null rule matters most, because it runs on every launch. Merged
        // as a subset: a field the request does not carry is not in the UPDATE
        // statement at all.
        $update += AppClientHeader::resolve($request);

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

    /**
     * What a device row looks like on the wire.
     *
     * Hand-built, not the model. `mobile_app_users` also carries `contact_id`
     * — which member is signed in on this handset — and that member's push
     * identity in `onesignal_subscription_id`. These endpoints are
     * UNAUTHENTICATED and `device_id` is only ever checked for existence, so
     * returning the model handed both to anyone who knew or guessed an id, and
     * would hand over every column added to the table from here on. `store`
     * makes it worse than it looks: registering an id that already exists
     * returns the EXISTING row, so the leak needs no write of one's own. The
     * member realm hand-builds its contact payload for the same reason
     * (MemberAuthController::verify). Found by review, 2026-09-15 (PF-4).
     *
     * The keys are exactly the ones the shipped apps decode. Two of them are
     * non-optional in builds already on phones and MUST keep appearing:
     *   `id`         iOS `DeviceId.id` (Int); Android `DeviceRegistrationData.id` (Int)
     *   `device_id`  iOS `DeviceId.deviceId` (String); Android `…deviceId`
     *                (String, non-null on `master`, which Play vc13 came from)
     * The other four are optional on both platforms, and kept because both
     * still decode them. Dropped, and read by neither app on any branch:
     * `contact_id`, `onesignal_subscription_id`, `last_active_at`.
     *
     * Dates go out through the model's own serializer, so the strings are the
     * ones those builds have always received.
     */
    private function devicePayload(MobileAppUser $user): array
    {
        return [
            'id' => (int) $user->id,
            'device_id' => (string) $user->device_id,
            'masjid_id' => $user->masjid_id === null ? null : (int) $user->masjid_id,
            'user_agent' => $user->user_agent,
            'created_at' => $user->created_at?->toJSON(),
            'updated_at' => $user->updated_at?->toJSON(),
        ];
    }
}
