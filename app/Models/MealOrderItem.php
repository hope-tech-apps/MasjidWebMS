<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line on a meal order. `item_name` and `unit_price_minor` are snapshotted at
 * order time so the order stays truthful after the menu item changes or is
 * deleted.
 */
class MealOrderItem extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'meal_order_id',
        'meal_menu_item_id',
        'item_name',
        'unit_price_minor',
        'quantity',
        'line_total_minor',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MealOrder::class, 'meal_order_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MealMenuItem::class, 'meal_menu_item_id');
    }
}
