<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** School financial records. Tenant isolation: TuitionLedgerTest. */
class TuitionGuardian extends Model
{
    use BelongsToMasjid;

    protected $table = 'tuition_guardians';
    protected $fillable = ['masjid_id', ...['account_id', 'contact_id', 'confirmed_by_user_id', 'confirmed_at']];
    protected $casts = ['confirmed_at' => 'datetime'];
}
