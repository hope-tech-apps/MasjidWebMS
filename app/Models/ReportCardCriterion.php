<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant curriculum configuration. Isolation: ReportCardCriteriaTest. */
class ReportCardCriterion extends Model
{
    use BelongsToMasjid;

    protected $table = 'report_card_criteria';
    protected $fillable = ['masjid_id', 'band_id', 'subject', 'kind', 'subject_position', 'position', 'text'];
    protected $casts = ['band_id'=>'integer','subject_position'=>'integer','position'=>'integer'];
}
