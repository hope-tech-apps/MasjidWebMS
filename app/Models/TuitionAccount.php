<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School financial records. Tenant isolation: TuitionLedgerTest. */
class TuitionAccount extends Model
{
    use BelongsToMasjid;

    protected $table = 'tuition_accounts';
    protected $fillable = ['masjid_id', ...['currency', 'minimum_payment_minor', 'remind_before_days', 'remind_after_days', 'created_by_user_id']];
    protected $casts = ['minimum_payment_minor' => 'integer', 'remind_before_days' => 'integer', 'remind_after_days' => 'integer'];
}
