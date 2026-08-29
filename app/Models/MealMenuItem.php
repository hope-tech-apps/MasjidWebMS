<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One orderable item on a meal menu. Price is integer minor units (cents).
 */
class MealMenuItem extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'meal_menu_id',
        'name',
        'name_ar',
        'description',
        'description_ar',
        'price_minor',
        'is_available',
        'max_quantity',
        'sort_order',
    ];

    protected $attributes = [
        'is_available' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'is_available' => 'boolean',
            'max_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(MealMenu::class, 'meal_menu_id');
    }
}
