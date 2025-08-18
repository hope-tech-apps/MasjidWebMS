<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\MobileAppUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

    public function storeMessage(Request $request)
    {
        try {

            $request->validate([
                'device_id' => 'required|exists:mobile_app_users,device_id',
                'email' => 'required|email',
                'name' => 'required|string',
                'phone' => 'nullable|string|regex:/^\+\d+$/',
                // 'reason_id' => 'nullable|exists:contact_us_resons,id',
                'reason_text' => 'required|string',
                'message' => 'required|string'
            ]);

            $mobileAppUser = MobileAppUser::where('device_id', $request['device_id'])->first();
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

            return response()->json([
                'status' => 'success',
                'data' => $message
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function storeUpdateContactUsAccount($mobile_user_id, $email, $name, $phone)
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
