<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a class store sells for Manara Bucks (T-003.4, W6).
 *
 * `group_id` NULL is a SCHOOL-WIDE prize (the office keeps that list); a set `group_id` is
 * one class's own prize (its teachers keep that list). Which classes may REDEEM a prize is
 * decided in one place, `availableTo()`: the school-wide list plus that class's own, and
 * nothing else, so a teacher can never spend a child's bucks on another class's shelf.
 *
 * `stock` is what is left on the shelf, NULL for unlimited. A prize is retired with
 * `is_active = false` and never deleted, because the ledger names it (the ledger also
 * snapshots its title and price, so retiring or repricing restates nothing).
 *
 * Tenant-scoped (BelongsToMasjid); cross-tenant test in tests/Feature/ClassStoreTenantIsolationTest.php.
 */
class Prize extends Model
{
    use BelongsToMasjid;

    /** A price and a stock are sensible numbers, not a fat-fingered 10000000. */
    public const MAX_COST = 10000;

    public const MAX_STOCK = 100000;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'title',
        'description',
        'cost_bucks',
        'stock',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'cost_bucks' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function isSchoolWide(): bool
    {
        return $this->group_id === null;
    }

    /** Unlimited when `stock` is NULL. */
    public function inStock(): bool
    {
        return $this->stock === null || $this->stock > 0;
    }

    /**
     * The prizes a class may redeem: the school-wide list and this class's own. THE ONE
     * DEFINITION of that rule (the redemption and the shelf a teacher sees both use it).
     * Tenant scope keeps it to the school; the explicit `masjid_id` is belt and braces for a
     * caller running unbound.
     */
    public function scopeAvailableTo(Builder $query, Group $group): Builder
    {
        return $query
            ->where('masjid_id', $group->masjid_id)
            ->where(fn (Builder $q) => $q->whereNull('group_id')->orWhere('group_id', $group->id));
    }

    public function scopeSchoolWide(Builder $query): Builder
    {
        return $query->whereNull('group_id');
    }

    /** Shown on a shelf: cheapest first, then by title, so the order is the same for everyone. */
    public function scopeInShelfOrder(Builder $query): Builder
    {
        return $query->orderBy('cost_bucks')->orderBy('title')->orderBy('id');
    }
}
