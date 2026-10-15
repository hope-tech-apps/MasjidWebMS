<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School financial records. Tenant isolation: TuitionLedgerTest. */
class TuitionChild extends Model
{
    use BelongsToMasjid;

    protected $table = 'tuition_children';
    protected $fillable = ['masjid_id', ...['account_id', 'contact_id', 'starts_on', 'ends_on']];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];
}
