<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant curriculum configuration. Isolation: ReportCardCriteriaTest. */
class ReportCardBand extends Model
{
    use BelongsToMasjid;

    protected $table = 'report_card_bands';
    protected $fillable = ['masjid_id', 'name', 'from_position', 'to_position', 'position', 'is_default', 'archived'];
    protected $casts = ['from_position'=>'integer','to_position'=>'integer','position'=>'integer','is_default'=>'boolean','archived'=>'boolean'];
}
