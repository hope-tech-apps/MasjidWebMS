<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable flyer design: the named slots, their types, and their defaults.
 *
 * Two kinds of row live here. SYSTEM templates ship with the product — seeded, shared
 * by every masjid, and not tenant-editable — and carry masjid_id = null. A masjid can
 * also author its own, which carries its masjid_id and is private to it.
 *
 * The schema is data for the same reason a Form's is: the Studio editor and the
 * server-side validation of a flyer's content both read it, so a hand-posted payload
 * cannot introduce a slot the design never defined.
 */
class FlyerTemplate extends Model
{
    use HasFactory, BelongsToMasjid;

    /** Design families. Each has its own slot vocabulary and its own renderer. */
    public const KINDS = ['food', 'event', 'janazah'];

    protected $fillable = [
        'key',
        'name',
        'kind',
        'schema',
        'is_active',
        'is_system',
        'masjid_id',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Widen the tenant scope so shared templates stay visible.
     *
     * This model keeps BelongsToMasjid — for the creating hook that stamps a
     * server-derived masjid_id, for masjid(), and for the withoutMasjidScope() bypass —
     * but its `masjid_id = <tenant>` filter is wrong HERE, and wrong in the dangerous
     * direction: system templates have masjid_id = null, so under the plain scope a
     * bound MasjidAdmin would see an empty template picker while a SuperAdmin (unbound,
     * no filter) saw a full one. The bug reads as "flyers are broken for everyone but
     * you", which is the kind that survives a demo.
     *
     * So the scope is re-registered under the SAME identifier — booted() runs after
     * bootTraits(), and addGlobalScope keyed by string overwrites — to mean "shared OR
     * mine" instead of "mine". Registering it under the trait's key rather than a new
     * one is deliberate: withoutMasjidScope() must still drop it, or the documented
     * super/system bypass would silently leave a tenant filter in place.
     *
     * The widening is READ-side only, and the write side does not take it on trust:
     * masjid_id and is_system are both mass-assignable, so "visible is not editable"
     * is enforced by the saving/deleting guards below rather than left to whichever
     * controller comes next. A controller that lists a tenant's own designs should
     * still go through ownedByTenant() (below) so the wrong row 404s instead of 403s.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::MASJID_TENANT_SCOPE, function (Builder $builder): void {
            $tenant = app(TenantContext::class);

            // Unbound (SuperAdmin, seeders, system jobs) still means no filter at all.
            if (! $tenant->hasTenant()) {
                return;
            }

            $column = $builder->getModel()->qualifyColumn('masjid_id');

            $builder->where(function (Builder $query) use ($column, $tenant): void {
                $query->whereNull($column)
                    ->orWhere($column, $tenant->get());
            });
        });

        static::saving(function (FlyerTemplate $template): void {
            self::assertTenantMayWrite($template);

            $tenant = app(TenantContext::class);

            if (! $tenant->hasTenant()) {
                return;
            }

            // Server-derived, exactly as masjid_id is: a design authored while a tenant
            // is bound is that tenant's own, never a system one, whatever was posted.
            $template->is_system = false;

            // masjid_id is re-stamped on EVERY write, not just on insert. The trait's
            // creating hook covers only creates, so without this an update of the
            // tenant's own row could carry it off to another masjid — both columns are
            // mass-assignable, and this is the pair that decides who owns the design.
            $template->masjid_id = $tenant->get();

            // The key is GLOBALLY unique and the seeder, the Assistant's flyer tools and
            // the Studio all resolve a design by key alone — so a tenant's key must not
            // be able to name a system design. The namespace is stamped rather than
            // hoped for, which is what makes the migration's unique() correct instead of
            // a collision waiting for the first masjid that types 'food.classic'.
            $prefix = 'm' . $tenant->get() . '.';

            if (! str_starts_with((string) $template->key, $prefix)) {
                $template->key = $prefix . ltrim((string) $template->key, '.');
            }
        });

        static::deleting(function (FlyerTemplate $template): void {
            self::assertTenantMayWrite($template);
        });
    }

    /**
     * A bound tenant may write only a row it already owns.
     *
     * Ownership is judged on the row as STORED, never on the incoming attributes, so
     * flipping masjid_id or is_system in the same save is not what buys the write. A
     * create needs no check: BelongsToMasjid's creating hook stamps masjid_id from the
     * same context, and the saving hook above forces is_system false.
     *
     * Unbound callers (the seeder, system jobs, SuperAdmin) are exactly what maintains
     * the shared rows, so they are left alone. 403 rather than 404 because the row is
     * legitimately VISIBLE to this tenant — it is the write that is refused.
     */
    private static function assertTenantMayWrite(self $template): void
    {
        $tenant = app(TenantContext::class);

        if (! $tenant->hasTenant() || ! $template->exists) {
            return;
        }

        $owner = $template->getRawOriginal('masjid_id');

        if ($owner === null || (int) $owner !== $tenant->get() || (bool) $template->getRawOriginal('is_system')) {
            abort(403, 'A shared flyer design cannot be changed by a masjid.');
        }
    }

    // ------------------------------------------------------------------- scopes

    /** Only templates an admin has switched on. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    /**
     * The rows the CURRENT tenant may modify: its own, never the shared ones.
     *
     * Any update/delete lookup must go through this rather than the plain
     * findOrFail() the rest of the CRM uses, because the widened read scope above
     * deliberately makes shared rows visible — visible is not editable.
     */
    public function scopeOwnedByTenant($query)
    {
        return $query->whereNotNull('masjid_id')->where('is_system', false);
    }

    /** Shared with every tenant — the designs that ship with the product. */
    public function scopeShared($query)
    {
        return $query->whereNull('masjid_id');
    }

    // -------------------------------------------------------------------- schema

    /** @return array<int,array<string,mixed>> The design's slots, always an array. */
    public function slots(): array
    {
        $slots = $this->schema['slots'] ?? [];

        return is_array($slots) ? $slots : [];
    }

    /**
     * Slot name => default value, for seeding a brand-new flyer's content.
     * A slot with no declared default starts empty rather than absent, so the editor
     * always renders every zone the design has.
     *
     * @return array<string,mixed>
     */
    public function defaultContent(): array
    {
        $content = [];

        foreach ($this->slots() as $slot) {
            if (is_array($slot) && isset($slot['name'])) {
                $content[$slot['name']] = $slot['default'] ?? null;
            }
        }

        return $content;
    }

    public function flyers()
    {
        return $this->hasMany(Flyer::class);
    }
}
