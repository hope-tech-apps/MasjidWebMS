<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ContactUs\StoreV1ContactMessageRequest;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Public contact-us intake.
 *
 * ## `device_id` is a claim, not proof of identity
 *
 * This endpoint is unauthenticated, and the only thing identifying the sender is
 * a `device_id` string in the request body. It used to be looked up with
 * `MobileAppUser::where('device_id', $x)->first()` — across ALL organisations —
 * and whatever it found then had its stored `name`, `email` and `phone`
 * OVERWRITTEN with the values in the request. Anyone who knew or guessed another
 * person's device id could therefore rewrite that person's contact record, in
 * any tenant, and file a message into their masjid's admin inbox under their
 * name.
 *
 * Two changes close that, and neither can be dropped without reopening it:
 *
 *  1. The lookup is scoped to the RESOLVED tenant, so a device id from one
 *     organisation can never match a record in another.
 *  2. An existing account's details are NEVER replaced from an unauthenticated
 *     request — only blanks are filled (see fillOnlyBlanks below). A person
 *     changing their real details does so through an authenticated surface;
 *     nothing here is trustworthy enough to overwrite a stored record with.
 *
 * The endpoint is also throttled by name (`throttle:contact-us`) — see the
 * limiter in AppServiceProvider for why one request is more expensive than it
 * looks.
 */
class ContactUsController extends Controller
{
    /**
     * Get list of contact us reasons
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reasonsList()
    {
        $reasons = ContactUsReason::where('show_to_users', 1)->get();
        return response()->api(200, __('api.success'), $reasons);
    }

    /**
     * Store a contact us message
     */
    public function storeMessage(StoreV1ContactMessageRequest $request)
    {
        try {
            // The organisation must be named and must exist. Without this the
            // `masjid_id` written below was whatever the header happened to
            // contain, including a nonexistent id.
            $masjidId = $this->resolveTenantId($request);

            // Scoped to the resolved tenant: a device id is only ever matched
            // against records belonging to the organisation being written to.
            $mobileAppUser = MobileAppUser::where('device_id', $request->input('device_id'))
                ->where('masjid_id', $masjidId)
                ->first();

            if (!$mobileAppUser) {
                $mobileAppUser = MobileAppUser::create([
                    'device_id' => $request->input('device_id'),
                    'masjid_id' => $masjidId,
                    'user_agent' => $request->userAgent(),
                ]);
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

            return response()->api(200, __('api.message_sent_successfully'), $message);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }

    /**
     * The `masjid-id` header contract, matching ZakatCalculatorController: 400
     * when no organisation is named, 404 when the named one does not exist.
     */
    private function resolveTenantId($request): int
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            throw new HttpResponseException(
                response()->api(400, 'A masjid must be specified.', null)
            );
        }

        if (! Masjid::whereKey($masjidId)->exists()) {
            throw new HttpResponseException(
                response()->api(404, 'That organization was not found.', null)
            );
        }

        return $masjidId;
    }

    /**
     * Store the contact account, or fill in what is still missing on it.
     *
     * NOTE the asymmetry, which is the security property: a NEW account is
     * created from the request, but an EXISTING one is never overwritten by it.
     * See the class docblock — the caller is unauthenticated and `device_id` is
     * an unverified claim, so replacing stored details here let anyone deface
     * anyone else's record. Blanks are still filled, so a person who submitted
     * without a phone number once and with one later is not stuck.
     */
    private function storeUpdateContactUsAccount($mobile_user_id, $email, $name, $phone)
    {
        $mobileUser = MobileAppUser::findOrFail($mobile_user_id);
        $oldAccount = ContactUsAccount::where('mobile_app_user_id', $mobileUser->id)->first();

        if ($oldAccount) {
            $this->fillOnlyBlanks($oldAccount, [
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
            ]);

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

    /**
     * Write each value only where the stored one is absent, and save only if
     * something actually changed.
     *
     * @param  array<string,mixed>  $values
     */
    private function fillOnlyBlanks(ContactUsAccount $account, array $values): void
    {
        $changed = false;

        foreach ($values as $column => $value) {
            if (($value === null || $value === '') || ! in_array($account->{$column}, [null, ''], true)) {
                continue;
            }

            $account->{$column} = $value;
            $changed = true;
        }

        if ($changed) {
            $account->save();
        }
    }
}
