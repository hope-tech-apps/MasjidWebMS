<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A dated rent payment against a property. Amount is signed cents — a negative
 * value records a vacancy / adjustment, matching how the ledger tracks it.
 */
class RentPayment extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'property_id',
        'paid_on',
        'amount',
        'payment_method',
        'check_number',
        'note',
        'import_batch',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'integer',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
