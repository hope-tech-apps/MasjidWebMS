<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasjidMobileAppFeature extends Model
{
    protected $fillable = ['masjid_id', 'feature_id', 'is_available'];

    public function masjid() {
        return $this->belongsTo(Masjid::class, 'masjid_mobile_app_features', 'masjid_id', 'id');
    }

    public function feature() {
        return $this->belongsTo(MobileAppFeature::class, 'masjid_mobile_app_features', 'feature_id', 'id');
    }
}
