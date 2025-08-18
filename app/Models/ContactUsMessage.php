<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactUsMessage extends Model
{
    protected $fillable = ['contact_us_account_id', 'contact_us_reason_id', 'message'];

    public function contacter() {
        return $this->belongsTo(ContactUsAccount::class);
    }

    public function reason() {
        return $this->belongsTo(ContactUsReason::class);
    }
}
