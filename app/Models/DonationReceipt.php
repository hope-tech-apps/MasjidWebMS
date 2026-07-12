<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DonationReceipt — the official tax receipt for a succeeded donation.
 * Tenant-scoped by masjid_id (BelongsToMasjid).
 *
 * `serial_number` is a gap-free per-masjid sequence allocated by
 * App\Services\Receipts\ReceiptService inside a transaction; the DB-level
 * unique (masjid_id, serial_number) is the backstop. Amounts are minor units
 * and `eligible_amount = gross_amount − advantage_amount`.
 *
 * Receipts are issued from the (unbound) webhook context, so masjid_id is set
 * explicitly by the service rather than by the creating hook.
 */
class DonationReceipt extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'donation_id',
        'serial_number',
        'issue_date',
        'gross_amount',
        'advantage_amount',
        'eligible_amount',
        'currency',
        'jurisdiction',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'issue_date' => 'date',
            'gross_amount' => 'integer',
            'advantage_amount' => 'integer',
            'eligible_amount' => 'integer',
        ];
    }

    public function donation()
    {
        return $this->belongsTo(Donation::class);
    }
}
