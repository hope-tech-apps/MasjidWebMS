<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\MobileAppUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeMessage(Request $request)
    {
        try {

            $request->validate([
                'device_id' => 'required|string',
                'email' => 'required|email',
                'name' => 'required|string',
                'phone' => 'nullable|string|regex:/^\+\d+$/',
                'reason_text' => 'required|string',
                'message' => 'required|string'
            ]);

            // Get or create mobile app user
            $mobileAppUser = MobileAppUser::where('device_id', $request['device_id'])->first();

            if (!$mobileAppUser) {
                $mobileAppUser = MobileAppUser::create([
                    'device_id' => $request['device_id'],
                    'masjid_id' => request()->header('masjid-id'),
                    'user_agent' => $request->userAgent()
                ]);
            }

            $contactUsAccountId = $this->storeUpdateContactUsAccount($mobileAppUser->id, $request['email'], $request['name'], $request['phone']);
            $contactUsAccount = ContactUsAccount::findOrFail($contactUsAccountId);
            $reason = ContactUsReason::where('text', $request['reason_text'])->first();


            if (!$reason) {
                $reason = ContactUsReason::create([
                    'text' => $request['reason_text'],
                    'show_to_users' => false
                ]);
            }

            $message = ContactUsMessage::create([
                'contact_us_account_id' => $contactUsAccount->id,
                'contact_us_reason_id' => $reason->id,
                'message' => $request['message']
            ]);

            return response()->api(200, __('api.message_sent_successfully'), $message);

        } catch (\Exception $e) {
            return response()->api(500, $e->getMessage(), null);
        }
    }

    /**
     * Store or update contact us account
     *
     * @param int $mobile_user_id
     * @param string $email
     * @param string $name
     * @param string|null $phone
     * @return int
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
            } else {
                $account = ContactUsAccount::create([
                    'mobile_app_user_id' => $mobileUser->id,
                    'email' => $email,
                    'name' => $name,
                    'phone' => $phone
                ]);
                return $account->id;
            }

        } catch (\Exception $e) {
            throw $e;
        }
    }
}

