<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order, FROZEN at checkout — a snapshot, never a live reference,
 * so a later price edit or a deleted dish cannot change what a receipt says was
 * bought. `record_type`/`record_id` point at the real record the line's own
 * service created; `recorded_as` keeps the historical_orders vocabulary.
 */
class OrderItem extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'order_id',
        'masjid_id',
        'buyable_type',
        'buyable_id',
        'recorded_as',
        'label',
        'quantity',
        'unit_amount_minor',
        'total_minor',
        'currency',
        'record_type',
        'record_id',
    ];

    protected function casts(): array
    {
        return [
            'buyable_id' => 'integer',
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'total_minor' => 'integer',
            'record_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
