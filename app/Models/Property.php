<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A rental property the masjid owns. Part of the property/rent component, which is
 * deliberately separate from the donor CRM — rent is not charitable giving.
 */
class Property extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'name',
        'address',
        'tenant_name',
        'monthly_rent',
        'notes',
        'is_active',
        'import_batch',
    ];

    protected function casts(): array
    {
        return [
            'monthly_rent' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function rentPayments()
    {
        return $this->hasMany(RentPayment::class);
    }
}
