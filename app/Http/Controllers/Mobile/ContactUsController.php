<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\ContactUs\StoreMobileContactMessageRequest;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Support\ContactUsNotifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile contact-us intake — the same contract as the V1 controller, and it had
 * the same defect. See App\Http\Controllers\Api\V1\ContactUsController for the
 * full reasoning; the short version:
 *
 * `device_id` is an unverified claim from an unauthenticated caller. The lookup
 * `MobileAppUser::where('device_id', $x)->first()` searched EVERY organisation,
 * and the account it found had its `name`, `email` and `phone` overwritten from
 * the request. The `exists:mobile_app_users,device_id` validation rule does not
 * close this — it is not tenant-scoped either, so a device id belonging to one
 * masjid validates happily against another masjid's endpoint and then resolves
 * to that other tenant's record.
 *
 * The lookup below is scoped to the masjid in the ROUTE, and an existing
 * account's stored details are never replaced from this endpoint.
 *
 * ## The org switcher widened WHERE a device may write, not WHO it may be
 *
 * The apps now ship an organisation switcher (MasjidsController::orgs): a
 * member's handset is registered with its HOME organisation and can walk into
 * one of that organisation's LISTED children. The device row stays pinned to
 * home — nothing re-registers it — so opening Contact Us inside a child posted
 * the home device id at the child's masjid id and got the 404 below, on iOS and
 * Android alike.
 *
 * `acceptedDeviceHomes()` therefore accepts a device registered with the route
 * organisation OR with its parent, and only when the parent's switcher would
 * actually have offered this organisation. That is the same set the switcher
 * shows and no wider: an UNLISTED child is still a 404 (nothing offers it), and
 * a device from an unrelated organisation is still a 404 — which is the scope
 * the security fix above exists for and must survive this.
 */
class ContactUsController extends Controller
{
    public function reasonsList()
    {
        $reasons = ContactUsReason::where('show_to_users', 1)->get();
        return response()->json([
            'status' => 'success',
            'data' => $reasons
        ], Response::HTTP_OK);
    }

    public function storeMessage(StoreMobileContactMessageRequest $request)
    {
        // Refused BEFORE the try block, and RETURNED rather than thrown: the
        // catch (\Exception) below would swallow an HttpResponseException and
        // answer 500. An organisation that switched Contact Requests off has no
        // inbox to read this in, so nothing is written and nobody is emailed.
        // Same status and words as the V1 door. moduleIsOff is fail-open, so a
        // stale config cache mid-deploy refuses nobody.
        if (Masjid::find((int) $request->route('masjid_id'))?->moduleIsOff('contact_requests') === true) {
            return response()->json([
                'status' => 'error',
                'message' => 'This organisation is not taking messages here right now.',
                // The iOS client decodes every body as Response<T> and needs a `data` key to reach the message.
                'data' => new \stdClass(),
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            // Tenant comes from the ROUTE, never from the body — and the device
            // must belong to it, or to the organisation that published it as a
            // child. Previously unscoped, so a device id from another masjid
            // resolved to that masjid's record; see the class docblock for both
            // halves.
            $masjidId = (int) $request->route('masjid_id');

            $mobileAppUser = MobileAppUser::where('device_id', $request->input('device_id'))
                ->whereIn('masjid_id', $this->acceptedDeviceHomes($masjidId))
                ->first();

            // Answered explicitly rather than dereferenced. The old code went
            // straight to `$mobileAppUser->id`, which raised a TypeError and
            // surfaced as a 500 — a device_id existence oracle.
            if ($mobileAppUser === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This device is not registered with this organization.',
                ], Response::HTTP_NOT_FOUND);
            }

            $contactUsAccountId = $this->storeUpdateContactUsAccount(
                $mobileAppUser->id,
                $request->input('email'),
                $request->input('name'),
                $request->input('phone')
            );
            $contactUsAccount = ContactUsAccount::findOrFail($contactUsAccountId);

            $reason = ContactUsReason::where('text', $request->input('reason_text'))->first();
            if (!$reason) {
                $reason = ContactUsReason::create([
                    'text' => $request->input('reason_text'),
                    'show_to_users' => false,
                ]);
            }

            $message = ContactUsMessage::create([
                // The organisation is FILED from the same variable the notifier
                // is handed below, so the inbox and the email can no longer
                // disagree. Deriving it from the device instead — which is what
                // the admin inbox used to do — files a switched member's message
                // under their HOME organisation while emailing the child they
                // actually wrote to.
                'masjid_id' => $masjidId,
                'contact_us_account_id' => $contactUsAccount->id,
                'contact_us_reason_id' => $reason->id,
                'message' => $request->input('message'),
            ]);

            // BOTH doors notify, or the mobile app's messages go unannounced
            // while the website's do not — a difference nobody in the office
            // would ever guess at, and the reason
            // ContactUsNotificationTest::staff_are_emailed_when_a_message_arrives_through_the_mobile_app
            // exists alongside its website twin (the FormDoorEquivalenceTest
            // precedent). Everything else about this call is the V1
            // controller's; read the comment there for why it is a clone and
            // why the failure of this line cannot reach the caller.
            ContactUsNotifier::received(
                (clone $message)
                    ->setRelation('contacter', $contactUsAccount)
                    ->setRelation('reason', $reason),
                Masjid::find($masjidId),
                ContactUsNotifier::SOURCE_MOBILE_APP
            );

            return response()->json([
                'status' => 'success',
                'data' => $message
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The organisations a device may be registered with and still be allowed to
     * write to $masjidId.
     *
     * Always $masjidId itself. Plus its PARENT, but only when the parent's
     * switcher would have offered $masjidId — that is the one and only way a
     * member arrives here holding a device registered somewhere else.
     *
     * The membership test goes THROUGH `Masjid::listedChildren()` rather than
     * re-reading `listed_at` here on purpose. What the switcher offers and what
     * this endpoint accepts are one rule, and a second copy of a rule is the
     * shape that drifts (.claude/rules/shipping.md — "when a rule is written
     * down in several places, it has already broken"). Asking the relationship
     * means an unlisted child cannot become reachable here without also
     * appearing in the switcher.
     *
     * Derived entirely from server state: the caller supplies a masjid id in the
     * URL, never the set it is checked against.
     *
     * @return array<int,int>
     */
    private function acceptedDeviceHomes(int $masjidId): array
    {
        $parent = Masjid::find($masjidId)?->parent;

        if ($parent !== null && $parent->listedChildren()->whereKey($masjidId)->exists()) {
            return [$masjidId, (int) $parent->id];
        }

        return [$masjidId];
    }

    /**
     * Store the contact account, or fill in what is still missing on it.
     *
     * A NEW account is created from the request; an EXISTING one is never
     * overwritten by it — that asymmetry is the security property. See the class
     * docblock.
     */
    public function storeUpdateContactUsAccount($mobile_user_id, $email, $name, $phone)
    {
        $mobileUser = MobileAppUser::findOrFail($mobile_user_id);
        $oldAccount = ContactUsAccount::where('mobile_app_user_id', $mobileUser->id)->first();

        if ($oldAccount) {
            $changed = false;

            foreach (['email' => $email, 'name' => $name, 'phone' => $phone] as $column => $value) {
                if (($value === null || $value === '') || ! in_array($oldAccount->{$column}, [null, ''], true)) {
                    continue;
                }

                $oldAccount->{$column} = $value;
                $changed = true;
            }

            if ($changed) {
                $oldAccount->save();
            }

            return $oldAccount->id;
        }

        $account = ContactUsAccount::create([
            'mobile_app_user_id' => $mobileUser->id,
            'email' => $email,
            'name' => $name,
            'phone' => $phone,
        ]);

        return $account->id;
    }
}
