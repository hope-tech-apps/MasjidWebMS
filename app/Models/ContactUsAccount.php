<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactUsAccount extends Model
{
    protected $fillable = ['mobile_app_user_id', 'email', 'name', 'phone'];

    public function mobileAppUser() {
        return $this->belongsTo(MobileAppUser::class);
    }

    public function messages() {
        return $this->hasMany(ContactUsMessage::class);
    }
}
