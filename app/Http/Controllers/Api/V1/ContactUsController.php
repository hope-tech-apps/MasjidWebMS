<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ContactUs\StoreV1ContactMessageRequest;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\MobileAppUser;

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
            // Get or create mobile app user
            $mobileAppUser = MobileAppUser::where('device_id', $request->input('device_id'))->first();

            if (!$mobileAppUser) {
                $mobileAppUser = MobileAppUser::create([
                    'device_id' => $request->input('device_id'),
                    'masjid_id' => request()->header('masjid-id'),
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
        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }

    /**
     * Store or update contact us account
     */
    private function storeUpdateContactUsAccount($mobile_user_id, $email, $name, $phone)
    {
        try {
            $mobileUser = MobileAppUser::findOrFail($mobile_user_id);
            $oldAccount = ContactUsAccount::where('mobile_app_user_id', $mobileUser->id)->first();

            if ($oldAccount) {
                $oldAccount->email = $email;
                $oldAccount->name = $name;
                $oldAccount->phone = $phone;
                $oldAccount->update();
                return $oldAccount->id;
            }

            $account = ContactUsAccount::create([
                'mobile_app_user_id' => $mobileUser->id,
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
            ]);
            return $account->id;
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
