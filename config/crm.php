<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Require admin two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Forward-looking enforcement flag. It is intentionally FALSE by default so
    | that current login behavior is unchanged: two-factor authentication is
    | requested at login ONLY for admins who have voluntarily enrolled AND
    | confirmed it (see App\Http\Controllers\AdminDashboard\AuthController). No
    | admin is ever locked out for not having 2FA.
    |
    | This flag exists so a future release can gate the admin panel behind
    | mandatory 2FA without another code change — but it must stay false until
    | an enrollment path/UX is in place, because turning it on would require
    | admins to enroll before they can proceed.
    |
    */

    'require_admin_2fa' => env('CRM_REQUIRE_ADMIN_2FA', false),

];
