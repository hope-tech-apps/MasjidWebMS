<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Fund — a donation designation (Zakat, Sadaqah, Fitra, Waqf, General).
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * fillable so system/super code can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 */
class Fund extends Model
{
    use HasFactory, BelongsToMasjid;

    public const TYPES = ['zakat', 'sadaqah', 'fitra', 'waqf', 'general'];

    protected $fillable = [
        'masjid_id',
        'name',
        'type',
        'receiptable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'receiptable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function donations()
    {
        return $this->hasMany(Donation::class);
    }
}
