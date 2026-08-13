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
 * THE ONE ALLOWED MUTATION is `reassignTo()`: a merge moves a row's SUBJECT from
 * the absorbed contact to the survivor, and nothing else. It is enforced as a
 * shape (`contact_id` alone dirty, non-null to non-null) rather than trusted to
 * a caller, so no other column can ride along with it. Content is still
 * immutable — what the row says happened cannot change, only whose record it
 * hangs on, and only towards a contact that still exists.
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
     * This contact's login history was CARRIED onto the survivor of a merge.
     *
     * Written on the absorbed contact immediately before the trail is re-pointed
     * (see `reassignTo()`), so the survivor's panel explains why another
     * person's address is sitting in their history instead of leaving a reader
     * to guess. Without it the re-pointed rows are unattributed: "who is
     * amina@example.com and why is she in Fatima's file?".
     */
    public const ACTION_MERGED = 'merged';

    /**
     * This contact's credential ADDRESS was freed for another member.
     *
     * `login_email` is the one column an act can take back off a contact, and it
     * is taken back only by an operator who typed that address onto somebody
     * else and confirmed the reassignment. The row records that the mailbox
     * stopped being this member's, which the `contacts` row can no longer say
     * once the column is null.
     */
    public const ACTION_ADDRESS_RELEASED = 'address_released';

    /**
     * This contact TOOK a credential address off another member's record.
     *
     * The other half of `address_released`, and it exists because that half is
     * written on the LOSER — who, in the case the reassignment door was built
     * for, is soft-deleted and therefore invisible to every screen in this
     * application (`ContactsController::index/show` use the non-trashed scope,
     * and the history panel reads `where('contact_id', …)`). Measured, the
     * winner's panel carried one row, `enabled`, while the refusal the operator
     * had just accepted promised that "both halves go on the access history".
     *
     * Snapshotting the address here is what keeps "which mailbox used to open
     * this child's file, and whose was it before?" answerable from a screen
     * rather than from a database console — the same property `absorbOnMerge()`
     * carries a trail to preserve.
     */
    public const ACTION_ADDRESS_CLAIMED = 'address_claimed';

    /**
     * A plain string column, not an enum — adding a verb must not be an
     * `ALTER TABLE` on a live table (.claude/rules/migrations.md). The three
     * verbs below `revoked` are what that choice was made FOR; they cost a
     * constant each and no schema change.
     *
     * @var array<int, string>
     */
    public const ACTIONS = [
        self::ACTION_ENABLED,
        self::ACTION_REVOKED,
        self::ACTION_MERGED,
        self::ACTION_ADDRESS_RELEASED,
        self::ACTION_ADDRESS_CLAIMED,
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
        static::updating(function (ContactLoginEvent $event) {
            // ONE exception, and it is deliberately not a rewrite of what
            // happened: a merge moves the SUBJECT of a row from the absorbed
            // contact to the survivor. Every fact the row asserts — the verb, the
            // address, who acted, when — is untouched and unreachable from here,
            // because the allowance requires `contact_id` to be the ONLY dirty
            // attribute and requires it to move from one real contact to another
            // (never to null, which is what erasure would look like).
            //
            // The alternative was a builder-level `where(...)->update()`, which
            // fires no model events and would therefore have walked straight
            // through this hook — the hole this class already documents. A
            // narrow, named allowance keeps the hook meaning what it says.
            if ($event->isASubjectReassignment()) {
                return;
            }

            throw new RuntimeException('Contact login events are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Contact login events are append-only and cannot be deleted.');
        });
    }

    /**
     * Carry this row onto the contact that absorbed its subject.
     *
     * The only mutation this model permits. Called by
     * `FamilyAccessService::absorbOnMerge()` before the source is force-deleted:
     * the migration chose `nullOnDelete` on `contact_id` so the force-delete
     * would not ERASE the history, but a null subject is a row no screen can
     * find — the panel reads `where('contact_id', …)`. Orphaning it beyond every
     * screen is the same outcome as deleting it, one query later.
     */
    public function reassignTo(Contact $survivor): void
    {
        $this->contact_id = $survivor->getKey();
        $this->save();
    }

    /**
     * Is the pending change exactly "this row now belongs to that contact"?
     *
     * Public so the property is testable directly rather than only through the
     * merge flow that relies on it.
     */
    public function isASubjectReassignment(): bool
    {
        return array_keys($this->getDirty()) === ['contact_id']
            && $this->getOriginal('contact_id') !== null
            && $this->contact_id !== null;
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
