<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MobileAppUser;
use Illuminate\Http\Request;

/**
 * Claiming and releasing a handset.
 *
 * This is the join .claude/rules/broadcasts.md said did not exist — "there is no
 * join from a person to their phone" — and without it a `service` audience
 * resolves to zero devices no matter how many members opted in.
 *
 * ---------------------------------------------------------------------------
 * THE APP MUST CALL BOTH HALVES
 * ---------------------------------------------------------------------------
 * `store` on sign-in, `destroy` on sign-out. Skipping the second one is the
 * dangerous half: a shared or handed-down phone would keep receiving the
 * previous member's interest-routed notifications, which is a disclosure about
 * what that person signed up for. Sign-out is not merely cosmetic here.
 *
 * ---------------------------------------------------------------------------
 * `MobileAppUser` IS NOT TENANT-SCOPED
 * ---------------------------------------------------------------------------
 * It is a pre-CRM model with no BelongsToMasjid trait, so the bound tenant does
 * NOT filter it and a bare lookup by `device_id` would find another
 * organisation's registration. Both actions therefore match on `masjid_id` AND
 * `device_id` explicitly, taking the masjid from the authenticated contact
 * rather than from the URL.
 */
class MemberDeviceController extends Controller
{
    /** POST — this member is signed in on this device. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:255'],
        ]);

        /** @var Contact $contact */
        $contact = $request->user();

        $device = MobileAppUser::query()
            ->where('masjid_id', $contact->masjid_id)
            ->where('device_id', $validated['device_id'])
            ->first();

        // A device the heartbeat has never registered. Answered as success
        // rather than 404: the client's next heartbeat creates the row, and
        // failing sign-in because a registration has not landed yet would be a
        // confusing error for something the member cannot act on.
        if ($device === null) {
            return response()->json([
                'status' => 'success',
                'data' => ['linked' => false],
            ]);
        }

        $device->forceFill(['contact_id' => $contact->id])->save();

        return response()->json([
            'status' => 'success',
            'data' => ['linked' => true],
        ]);
    }

    /**
     * DELETE — release this device.
     *
     * Scoped to the CALLER's own contact id, so a token can only ever release a
     * handset it currently holds. Without that clause a member could unlink
     * somebody else's device by guessing a device id, which would quietly cut
     * off another person's notifications.
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:255'],
        ]);

        /** @var Contact $contact */
        $contact = $request->user();

        MobileAppUser::query()
            ->where('masjid_id', $contact->masjid_id)
            ->where('device_id', $validated['device_id'])
            ->where('contact_id', $contact->id)
            ->update(['contact_id' => null]);

        // An empty `data` object, not an omitted key: the client decodes every
        // mobile response through one `Response<T>` envelope whose `data` is
        // non-optional, so omitting it fails on the device and nowhere else.
        return response()->json(['status' => 'success', 'data' => new \stdClass()]);
    }
}
