<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * ContactLoginEvent — one act of opening or closing a family sign-in.
 *
 * The four `login_*` columns on `contacts` are STATE; this table is the RECORD
 * of the acts that produced it. See the migration for why that distinction is
 * load-bearing when what is being granted is access to a child's file.
 *
 * `BelongsToMasjid` because an audit row is tenant data of the most sensitive
 * kind — it names a family address against an organisation — and
 * .claude/rules/tenant-scoping.md admits no unscoped CRM model. Cross-tenant
 * isolation is pinned by tests/Feature/FamilyLoginEnablementTest.php.
 *
 * ---------------------------------------------------------------------------
 * APPEND-ONLY, enforced here rather than promised in a comment
 * ---------------------------------------------------------------------------
 *
 * An audit trail an application can rewrite is a log, not an audit trail. The
 * two hooks below make `update()` and `delete()` on a MODEL INSTANCE throw, so
 * a future controller that "just fixes" a row fails loudly instead of quietly.
 *
 * WHAT THIS DOES NOT STOP, said plainly rather than left to be discovered:
 *
 *  - `ContactLoginEvent::where(...)->update([...])` and the matching mass
 *    `delete()`. Eloquent fires no model events for a builder-level write, so
 *    the hooks never see it. Nothing in this application does that, and the
 *    tenant scope still confines it to the bound organisation — but it is a way
 *    through, and pretending otherwise would be worse than the hole.
 *  - raw SQL, and the `masjid_id` cascade in the migration, which is a DB-level
 *    delete. The cascade is intended: an organisation going away is not a
 *    rewrite of one contact's history.
 *
 * Closing those properly means database grants — an append-only role the web
 * user holds and the migrator does not — and the application user owns the
 * schema on the deployed host today, so a permission the deploy itself would
 * have to hold cannot be the guarantee. That is an infrastructure change, not a
 * model change. This is the half that is honest to ship here.
 */
class ContactLoginEvent extends Model
{
    use BelongsToMasjid;

    /** A login was opened, or re-opened, or moved to a different address. */
    public const ACTION_ENABLED = 'enabled';

    /** A login was withdrawn. */
    public const ACTION_REVOKED = 'revoked';

    /**
     * A plain string column, not an enum — adding a verb must not be an
     * `ALTER TABLE` on a live table (.claude/rules/migrations.md).
     *
     * @var array<int, string>
     */
    public const ACTIONS = [
        self::ACTION_ENABLED,
        self::ACTION_REVOKED,
    ];

    /**
     * Everything is fillable because nothing here is reachable from a request
     * body: rows are written in exactly one place
     * (App\Services\Family\FamilyAccessService::record), from values that
     * service derives from the authenticated actor and the contact it just
     * changed. No controller passes a payload to `create()` on this model.
     */
    protected $fillable = [
        'masjid_id',
        'contact_id',
        'action',
        'login_email',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'actor_ip',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Contact login events are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Contact login events are append-only and cannot be deleted.');
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** The staff member who acted, when they still exist. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
