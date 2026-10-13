<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** A retained, ordered app feature choice belonging to one organisation. */
class AppBottomTab extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'feature_id', 'position', 'saved_key', 'saved_title'];
    protected $casts = ['feature_id' => 'integer', 'position' => 'integer'];
}
