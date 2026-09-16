<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAppUser extends Model
{
    /**
     * `app_platform`, `app_version` and `app_build` are what the handset says
     * it is running, from the `X-Manara-App` header or the request body
     * (App\Support\AppClientHeader). They are telemetry, never authorisation:
     * nothing gates on them, because a client sends them and a client can send
     * anything. NULL means "a build that predates the header", which is exactly
     * the reading `app-telemetry:builds` prints as `pre-R1`.
     */
    protected $fillable = [
        'masjid_id',
        'contact_id',
        'device_id',
        'onesignal_subscription_id',
        'user_agent',
        'app_platform',
        'app_version',
        'app_build',
        'last_active_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
    ];

    public function contactUsAccount() {
        return $this->hasOne(ContactUsAccount::class);
    }

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * The member signed in on this handset, if anybody is.
     *
     * NULL is the normal state — the app works without an account, so most
     * devices belong to nobody. A device gains a contact when a member signs in
     * on it and loses it on sign-out, which is what lets an interest-routed
     * broadcast reach the right phones without claiming a device is a person.
     */
    public function contact() {
        return $this->belongsTo(Contact::class);
    }
}
