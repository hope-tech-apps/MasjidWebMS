<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAppUser extends Model
{
    protected $fillable = ['masjid_id', 'device_id', 'onesignal_subscription_id', 'user_agent', 'last_active_at'];

    protected $casts = [
        'last_active_at' => 'datetime',
    ];

    public function contactUsAccount() {
        return $this->hasOne(ContactUsAccount::class);
    }

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
