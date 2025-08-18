<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAppUser extends Model
{
    protected $fillable = ['masjid_id', 'device_id', 'user_agent'];

    public function contactUsAccount() {
        return $this->hasOne(ContactUsAccount::class);
    }

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
