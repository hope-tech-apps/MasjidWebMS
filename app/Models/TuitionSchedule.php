<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School financial records. Tenant isolation: TuitionLedgerTest. */
class TuitionSchedule extends Model
{
    use BelongsToMasjid;

    protected $table = 'tuition_schedules';
    protected $fillable = ['masjid_id', ...['fee_plan_id', 'school_year_id', 'cadence', 'due_day', 'sibling_percent', 'group_ids', 'grades', 'is_active', 'created_by_user_id']];
    protected $casts = ['due_day' => 'integer', 'sibling_percent' => 'integer', 'group_ids' => 'array', 'grades' => 'array', 'is_active' => 'boolean'];
}
