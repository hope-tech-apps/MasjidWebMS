<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A membership: this user administers/belongs to this masjid, in this role.
 *
 * The `masjid_user` pivot (S2 of docs/multi-tenant-admin-design.md) is the table
 * that will become the sole AUTHORIZATION source for admin access, replacing the
 * single-tenant `masjids.user_id` -> `User::masjid()` `hasOne` that cannot express
 * a consultant running two masjids or a parent who is staff at a school and a
 * member at a masjid.
 *
 * **Nothing consumes it yet.** In this slice the rows exist, the relations exist,
 * and tenant resolution still runs entirely off `masjids.user_id` exactly as
 * before. `ResolveMasjidTenant`, `TenantContext::setFromMembership()` and the
 * fail-closed rules are S3.
 *
 * ------------------------------------------------------------------------------
 * This model deliberately does NOT use `BelongsToMasjid`
 * ------------------------------------------------------------------------------
 *
 * .claude/rules/tenant-scoping.md requires that trait on every tenant-scoped CRM
 * model, and this is the one table it must not be applied to. `BelongsToMasjid`
 * filters queries BY the bound tenant — but this table is what the tenant will be
 * derived FROM. Scoping it would make "which masjids may this user act on?"
 * answerable only from inside a masjid the user is already bound to: a
 * multi-tenant admin could never see, or switch to, their second membership, and
 * the S3 resolver would be asking the tenant scope a question it is supposed to
 * answer. Its `creating` hook would also stamp `masjid_id` from the bound tenant
 * and silently write memberships into the wrong organisation.
 *
 * Isolation for this table is therefore an authorization concern (S3's resolver +
 * the API surface in S4), not a global-scope concern.
 */
class MasjidUser extends Model
{
    /**
     * Laravel would pluralise this to `masjid_users`; the table is the
     * conventional pivot name for Masjid ↔ User.
     */
    protected $table = 'masjid_user';

    protected $fillable = [
        'masjid_id',
        'user_id',
        'role',
        'is_default',
    ];

    /**
     * `default_key` is the MySQL STORED generated column that carries the
     * one-default-per-user unique index (`create_masjid_user_table`). The database
     * derives it from `is_default`/`user_id`, nothing writes it, and it does not
     * exist on the SQLite the suite runs on — where the same predicate is a native
     * partial index. Hiding it keeps a serialized membership identical on both
     * drivers, which matters as soon as S4 returns `memberships[]` to the SPA.
     */
    protected $hidden = [
        'default_key',
    ];

    protected function casts(): array
    {
        return [
            // Advisory-only flag in this slice, but a real DB constraint: at most
            // one row per user may be true.
            'is_default' => 'boolean',
        ];
    }

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
