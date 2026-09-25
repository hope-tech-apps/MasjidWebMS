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
     * The family CHOSE a password for themselves, or changed the one they had.
     *
     * Since 2026-09-16 the app's create-account and forgot-password can write it
     * too, for a contact that has a family login: the password is one per
     * person, so the portal's history must show it changed. A contact with no
     * family login gets no row (FamilyPasswordService::set).
     *
     * The first verb on this trail with no operator behind it, and that is the
     * fact worth recording: `actor_user_id`, `actor_name` and `actor_email` are
     * all NULL because no staff user was involved and none CAN be — see
     * FamilyPasswordService. A reader of the access-history panel should be able
     * to tell an act the office performed from one the family performed, and the
     * empty actor is how.
     */
    public const ACTION_PASSWORD_SET = 'password_set';

    /**
     * A password stopped existing, leaving sign-in codes only.
     *
     * Either the family removed it themselves (FamilyPasswordService::clear, no
     * actor, like `password_set`), or an operator moved the login to a different
     * address or gave the address to another member (FamilyAccessService), which
     * ends the password chosen under the old address. That second kind names the
     * operator, and its `login_email` is the address the password belonged to.
     */
    public const ACTION_PASSWORD_CLEARED = 'password_cleared';

    /**
     * A 7-day portal link was MAILED to this contact's sign-in address.
     *
     * The act that used to be invisible. `enabled` says an office opened the
     * door; until 2026-09-24 nothing said whether anybody had told the family
     * where it was, and the answer at Al-Razi was "for five of ten families,
     * no". A link is a bearer credential to a specific child's records, so
     * sending one is exactly the kind of act this trail exists for: who sent it,
     * to which mailbox, and when.
     *
     * `login_email` is the address the mail actually went to, snapshotted the
     * same way every other row snapshots it — the contact's column may have
     * moved since, and "which mailbox was handed a key" is the question.
     *
     * Deliberately NOT written when a link is REDEEMED. A parent signing in is
     * not an operator act and does not belong on a trail whose reader is asking
     * who granted what; `contacts.last_login_at` already carries it, and the
     * office panel prints it beside the invite.
     */
    public const ACTION_INVITE_SENT = 'invite_sent';

    /**
     * A 7-day portal link was MINTED AND HANDED TO AN OPERATOR, not mailed.
     *
     * The SuperAdmin-only "Copy link" (2026-09-25, DECISIONS.md). It produces
     * exactly the same credential `invite_sent` does, and it is a DIFFERENT VERB
     * on purpose rather than for tidiness.
     *
     * An emailed link went to the address on this row and nowhere else: "where
     * did the key go?" has an answer, and the answer is a mailbox the office
     * typed. A copied link went to a person in a room, by a channel this
     * application cannot see — read aloud, texted, pasted. The two acts have
     * different blast radii and different people to ask afterwards, so a trail
     * that collapsed them into one word could not answer the question it exists
     * for. `FamilyPortalInviteTest` and `FamilyPortalInviteCopyLinkTest` both
     * assert the verbs stay distinct.
     *
     * `login_email` is the address the link is BOUND to — redemption re-reads
     * `contacts.login_email` and refuses if it has moved — even though no mail
     * was sent to it. It is the fact that says which child's file the copied key
     * opens.
     *
     * `actor_*` is never null in practice here: the act is refused unless the
     * caller is a SuperAdmin `User`, which is the whole point of recording it.
     */
    public const ACTION_INVITE_LINK_COPIED = 'invite_link_copied';

    /**
     * A plain string column, not an enum — adding a verb must not be an
     * `ALTER TABLE` on a live table (.claude/rules/migrations.md). The seven
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
        self::ACTION_PASSWORD_SET,
        self::ACTION_PASSWORD_CLEARED,
        self::ACTION_INVITE_SENT,
        self::ACTION_INVITE_LINK_COPIED,
    ];

    /**
     * Everything is fillable because nothing here is reachable from a request
     * body: rows are written in exactly four places, from values each derives
     * from the authenticated actor and the contact it just changed:
     * App\Services\Family\FamilyInviteService::mint (`invite_sent` and
     * `invite_link_copied`, naming the staff member who pressed the button),
     * App\Services\Family\FamilyAccessService::record (including
     * `password_cleared` when an operator re-addresses or releases a login),
     * App\Services\Family\FamilyPasswordService::record (`password_set` and
     * `password_cleared`, no actor), and
     * App\Services\Member\MemberAccountDeletion, which writes one `revoked` row
     * with no actor when a member deleting their account ends a family login.
     * No controller passes a payload to `create()` on this model.
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
