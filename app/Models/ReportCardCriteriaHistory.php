<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant curriculum audit and complete applied versions. Isolation: ReportCardCriteriaTest. */
class ReportCardCriteriaHistory extends Model
{
    use BelongsToMasjid;

    protected $table = 'report_card_criteria_histories';
    protected $fillable = ['masjid_id', 'actor_user_id', 'action', 'effective_period', 'effective_on', 'bands'];
    protected $casts = ['effective_on'=>'date', 'bands'=>'array'];
}
