<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

class RosterImportMapping extends Model
{
    use BelongsToMasjid;

    protected $fillable = ['masjid_id', 'header_signature', 'mapping'];

    protected $casts = ['mapping' => 'array'];
}
