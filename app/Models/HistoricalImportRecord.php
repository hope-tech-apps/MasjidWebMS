<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/**
 * One row the order-history import CREATED around the orders themselves — a
 * contact, a fund, the shared intake form, an offering, a fee plan, an email
 * hold — so `crm:import-wix-orders --undo` removes exactly what a batch made
 * and nothing that was there before it.
 *
 * `record_updated_at` is the row's own `updated_at` when the import finished
 * with it. If the row has moved since, somebody has built on it (the contact
 * import filled in a phone number, an admin edited a fund) and undo keeps it
 * and says so, rather than destroying their work.
 */
class HistoricalImportRecord extends Model
{
    use BelongsToMasjid;

    public const TYPE_CONTACT = 'contact';
    public const TYPE_FUND = 'fund';
    public const TYPE_FORM = 'form';
    public const TYPE_OFFERING = 'offering';
    public const TYPE_FEE_PLAN = 'fee_plan';
    public const TYPE_EMAIL_HOLD = 'email_hold';

    protected $fillable = [
        'masjid_id',
        'import_batch',
        'record_type',
        'record_id',
        'record_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
            'record_updated_at' => 'datetime',
        ];
    }
}
