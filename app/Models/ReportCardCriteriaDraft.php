<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant curriculum configuration. Isolation: ReportCardCriteriaTest. */
class ReportCardCriteriaDraft extends Model
{
    use BelongsToMasjid;

    protected $table = 'report_card_criteria_drafts';
    protected $fillable = ['masjid_id', 'effective_on', 'effective_period', 'bands', 'actor_user_id', 'applied_at'];
    protected $casts = ['effective_on'=>'date','bands'=>'array','applied_at'=>'datetime'];
}
