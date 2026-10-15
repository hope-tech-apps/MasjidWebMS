<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School financial records. Tenant isolation: TuitionLedgerTest. */
class TuitionCheckout extends Model
{
    use BelongsToMasjid;

    protected $table = 'tuition_checkouts';
    protected $fillable = ['masjid_id', ...['account_id', 'payer_contact_id', 'uuid', 'amount_minor', 'balance_minor', 'currency', 'stripe_account_id', 'stripe_session_id', 'checkout_url']];
    protected $casts = ['amount_minor' => 'integer', 'balance_minor' => 'integer'];
}
