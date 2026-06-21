<?php

return [
    'api_url' => env('ONESIGNAL_REST_API_URL'),
    'app_id' => env('ONESIGNAL_APP_ID'),

    // App-scoped REST API key. Used for push notifications (OnesignalService).
    'app_rest_api_key' => env('ONESIGNAL_REST_API_KEY'),

    // Account-scoped "User Auth Key" (newer name: "Organization REST API Key"
    // or "Personal REST API Key"). OneSignal's In-App Message management API
    // requires this credential — the app-scoped REST key returns
    // "Please include a case-sensitive header of Authorization: Basic
    // <YOUR-USER-AUTH-KEY-HERE>" when used against /apps/{id}/in_app_messages.
    //
    // Find at: https://dashboard.onesignal.com → click your name top right
    // → "Account" → "Keys & IDs" tab → "Organization API Key" (or
    // "User Auth Key" on older accounts).
    //
    // Without this, OnesignalInAppMessageService logs an error and the IAM
    // mirror is skipped. Local row + /mobile/.../splash endpoint still work.
    'user_auth_key' => env('ONESIGNAL_USER_AUTH_KEY'),
];