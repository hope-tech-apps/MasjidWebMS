<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant curriculum configuration. Isolation: ReportCardCriteriaTest. */
class ReportCardBandFreeze extends Model
{
    use BelongsToMasjid;

    protected $table = 'report_card_band_freezes';
    protected $fillable = ['masjid_id', 'band_id', 'period_key'];
    protected $casts = [];
}
