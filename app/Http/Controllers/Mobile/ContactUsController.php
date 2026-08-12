<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\ContactUs\StoreMobileContactMessageRequest;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\MobileAppUser;
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
        try {
            // Tenant comes from the ROUTE, never from the body — and the device
            // must belong to it. Previously unscoped, so a device id from
            // another masjid resolved to that masjid's record.
            $masjidId = (int) $request->route('masjid_id');

            $mobileAppUser = MobileAppUser::where('device_id', $request->input('device_id'))
                ->where('masjid_id', $masjidId)
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
                'contact_us_account_id' => $contactUsAccount->id,
                'contact_us_reason_id' => $reason->id,
                'message' => $request->input('message'),
            ]);

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
