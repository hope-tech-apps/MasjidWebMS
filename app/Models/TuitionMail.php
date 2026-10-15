<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School financial records. Tenant isolation: TuitionLedgerTest. */
class TuitionMail extends Model
{
    use BelongsToMasjid;

    protected $table = 'tuition_mails';
    protected $fillable = ['masjid_id', ...['account_id', 'guardian_contact_id', 'mail_key', 'kind', 'status', 'from_on', 'to_on', 'due_items', 'attempted_at', 'sent_at', 'failed_at', 'exception_class']];
    protected $casts = ['from_on' => 'date', 'to_on' => 'date', 'due_items' => 'array', 'attempted_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
}
