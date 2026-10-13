<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class AppVolunteerSetting extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id','title','url'];
    protected $casts = [];
}
