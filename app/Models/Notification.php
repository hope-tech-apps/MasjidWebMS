<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'masjid_id',
        'title',
        'message',
        'onesignal_message_id'
    ];

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }
}
