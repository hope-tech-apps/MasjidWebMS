<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant-owned saved period cell; isolation is exercised in PeriodAttendanceTest. */
class PeriodAttendanceRecord extends Model
{
    use BelongsToMasjid;
    protected $guarded = ['id'];
    protected function casts(): array { return ['session_date'=>'date','teacher_ids'=>'array']; }
    public function membership(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(GroupMembership::class,'group_membership_id'); }
}
