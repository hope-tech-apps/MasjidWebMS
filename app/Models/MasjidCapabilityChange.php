<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One SuperAdmin switch flip on one organisation: a catalogue capability
 * (config/capabilities.php), the CRM or Assistant column switch, or the public
 * directory listing (`directory_listing`). Written only by
 * App\Support\CapabilityLedger, inside the same transaction as the save.
 *
 * NOT `BelongsToMasjid`: it is written and read only by SuperAdmin endpoints,
 * where the tenant context is unbound by design, so the global scope would add
 * nothing, and its creating hook must never stamp a row with a bound tenant
 * other than the one flipped. Listed in TenantScopingCoverageTest's DECLINED.
 *
 * Out of scope, said plainly: the opt-in demo-tenant seeder writes
 * capability_overrides directly for its demo tenant, and the mobile app drawer
 * (masjid_mobile_app_features) is its own switch with no ledger.
 *
 * APPEND-ONLY, with the same honest limit as MasjidFormsCardLinkLog: the hooks
 * fire for model instances only, so a query-builder update or raw SQL still gets
 * through. Nothing in this application does either. CapabilityChangeLedgerTest
 * pins the hooks.
 */
class MasjidCapabilityChange extends Model
{
    /** Rows carry a single `created_at`; nothing ever updates one. */
    public const UPDATED_AT = null;

    /**
     * Fillable because nothing here comes from a request body: every value is
     * derived from the organisation before and after the save and from the
     * authenticated actor.
     */
    protected $fillable = [
        'masjid_id',
        'capability',
        'enabled_before',
        'enabled_after',
        'override_before',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'masjid_id' => 'integer',
            'enabled_before' => 'boolean',
            'enabled_after' => 'boolean',
            'override_before' => 'boolean',
            'actor_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Capability change rows are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Capability change rows are append-only and cannot be deleted.');
        });
    }
}
