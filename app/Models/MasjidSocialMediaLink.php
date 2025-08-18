<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

class MasjidSocialMediaLink extends Model
{
    use SearchableTrait;
    
    // Fillable
    protected $fillable = ['masjid_id', 'type', 'value'];

    protected $searchableFields = ['type', 'value'];

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }

    public static function updateOrStoreSocialMediaLink($masjid_id, string $type, string $value) {
        $link = self::where('masjid_id', $masjid_id)->where('type', $type)->first();
        if ($link) {
            $link->update(['value' => $value]);
        } else {
            self::create([
                'masjid_id' => $masjid_id,
                'type' => $type,
                'value' => $value
            ]);
        }
    }

}
