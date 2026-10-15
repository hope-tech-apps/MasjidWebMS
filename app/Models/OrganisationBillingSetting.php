<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School billing currency and reminder policy. Isolation: TuitionLedgerTest. */
class OrganisationBillingSetting extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id', 'currency', 'minimum_payment_minor', 'remind_before_days', 'remind_after_days'];
    protected $casts = ['minimum_payment_minor' => 'integer', 'remind_before_days' => 'integer', 'remind_after_days' => 'integer'];
}
