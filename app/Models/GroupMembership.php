<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonInterface;
use Throwable;

/**
 * GroupMembership — one person's place in one group.
 *
 * Links an existing Contact (the CRM congregant record) to a Group. Groups never
 * duplicate a person; they reference one.
 *
 * Tenant-scoped by a denormalised masjid_id so BelongsToMasjid scopes membership
 * queries without joining through groups. See .claude/rules/tenant-scoping.md.
 *
 * GUARDIANSHIP is an explicit edge, not just a role label: a guardian row also
 * carries `guardian_of_contact_id`, naming the member it is attached to inside
 * this group. `role = guardian` on its own could not answer "guardian of whom?"
 * once a group holds two children of the same parent, so one row is one
 * (guardian, ward, group) edge. The invariant — guardian rows MUST carry a ward,
 * every other role MUST NOT — is enforced at the request boundary
 * (StoreGroupMembershipRequest) and in GroupMembershipsController.
 */
class GroupMembership extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * Membership roles. PHP constants, NOT a DB enum — the same reasoning as
     * Masjid::ORG_TYPES: adding a role must never require ALTER TABLE on a live
     * table. See .claude/rules/migrations.md.
     *
     * These are STRUCTURAL names, not admin-facing labels. What a leader is
     * called ("Teacher" in a school, "Ustadh" in a halaqa) is presentation and
     * belongs to the terminology pack, never to this constant.
     */
    public const ROLE_LEADER = 'leader';
    public const ROLE_MEMBER = 'member';
    public const ROLE_GUARDIAN = 'guardian';

    public const ROLES = [
        self::ROLE_LEADER,
        self::ROLE_MEMBER,
        self::ROLE_GUARDIAN,
    ];

    /**
     * Roles that describe a person's own place in the group, as opposed to a
     * guardian edge attached to someone else's place. A ward must hold one of
     * these before a guardian can be linked to them.
     */
    public const PARTICIPANT_ROLES = [
        self::ROLE_LEADER,
        self::ROLE_MEMBER,
    ];

    /**
     * What a guardian's recorded consent covers (T-005b).
     *
     * A guardian EDGE records a relationship; consent is a separate act, and
     * .claude/rules/groups.md requires it to be recorded against the edge and
     * checked at the point of disclosure. Absence of a record means NO consent —
     * never "unknown, assume yes".
     *
     * The two scopes are a hierarchy, not a set: `media` covers everything
     * `feed` covers. A photograph of a child is the sharper disclosure than a
     * note about the lesson, so it takes its own explicit grant rather than
     * riding along with permission to read the feed.
     *
     * PHP constants, not a DB enum — same reasoning as ROLES above.
     */
    public const CONSENT_FEED = 'feed';
    public const CONSENT_MEDIA = 'media';

    public const CONSENT_SCOPES = [
        self::CONSENT_FEED,
        self::CONSENT_MEDIA,
    ];

    /** Which scopes satisfy a request for a given disclosure. */
    private const CONSENT_COVERAGE = [
        self::CONSENT_FEED => [self::CONSENT_FEED, self::CONSENT_MEDIA],
        self::CONSENT_MEDIA => [self::CONSENT_MEDIA],
    ];

    /**
     * PROVENANCE — on whose authority this row exists.
     *
     * A `guardian` row is the single fact the parent portal reads to decide
     * whose child's behaviour, ḥifẓ and safeguarding records a credential opens.
     * It is an AUTHORIZATION GRANT, and until this column existed the table
     * recorded no grantor: "the office established this relationship" and "an
     * anonymous POST to the public registration endpoint asserted it" were the
     * same row, so every read path trusted both equally.
     *
     *   - CONFIRMED     — an authenticated staff act stands behind it
     *                     (`confirmed_by_user_id` names them). Grants exactly
     *                     what a membership granted before provenance existed.
     *   - SELF_ASSERTED — a public form's claim about a person, with no session,
     *                     no token and no proof of control of any address. It is
     *                     a ROSTER FACT and not a grant: it lists, it counts
     *                     towards capacity, a teacher may keep records about the
     *                     child it enrols — and it gives its HOLDER no standing
     *                     anywhere (see App\Support\GroupAudience::membershipsFor).
     *
     * PHP constants, not a DB enum — same reasoning as ROLES above.
     */
    public const PROVENANCE_CONFIRMED = 'confirmed';
    public const PROVENANCE_SELF_ASSERTED = 'self_asserted';

    public const PROVENANCES = [
        self::PROVENANCE_CONFIRMED,
        self::PROVENANCE_SELF_ASSERTED,
    ];

    /**
     * `provenance` and its three companions are DELIBERATELY NOT FILLABLE, for
     * the same reason `contacts`' four `login_*` columns are not: they record
     * who authorised a disclosure about a child, so no request body may set
     * them and no mass-assignment may carry them in from a payload. The two
     * writers that legitimately set them — `GroupMembershipsController` (staff,
     * confirming) and `RegistrationService` (the public form, asserting) — go
     * through `confirmedBy()` / `selfAssertedFrom()` below, which is also what
     * keeps "who may confirm" answerable by reading two call sites.
     *
     * `consent_carried_from_group_id` (2026-10-05) is not fillable either: it
     * says a consent was copied by a move and from which class, and only
     * `carriedFrom()` sets it. See THE MARKER below.
     */
    protected $fillable = [
        'masjid_id',
        'group_id',
        'contact_id',
        'role',
        // Fillable, unlike the four provenance/consent columns above it: a grade
        // is ordinary roster data the office types, not a record of who
        // authorised a disclosure about a child. Staff-writable only — the
        // teacher realm exposes no roster mutation at all.
        'grade_label',
        'guardian_of_contact_id',
        'joined_at',
        'consent_granted_at',
        'consent_scope',
    ];

    /**
     * The model states the column default rather than inheriting it from the DB.
     *
     * A row created without naming `provenance` read NULL in memory until it was
     * refreshed, while the same row read `confirmed` from the database. Any
     * writer that hands an unrefreshed model to a read path therefore got a
     * different answer from the one the row actually has — failing closed by
     * accident rather than by design, which is not a property you can rely on in
     * the other direction. `selfAssertedFrom()` overrides this explicitly on
     * every public-registration write.
     */
    protected $attributes = [
        'provenance' => self::PROVENANCE_CONFIRMED,
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'left_on' => 'date',
            'moved_on' => 'date',
            'consent_granted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * THE MARKER: `consent_carried_from_group_id`, the class a consent was
     * copied from when a student was moved (2026-10-05).
     *
     *   marker   consent columns   reads as
     *   null     set               recorded by the office for this class
     *   set      set               carried from that class: untouched since, or
     *                              recorded here since for LESS than that class
     *                              still holds (`holdsLessThanCarriedFrom()`)
     *   set      null              withdrawn here after it was carried
     *   null     null              never asked, or withdrawn where it was recorded
     *
     * Every writer that touches it:
     *
     *   - `carriedFrom()` with consent SETS it;
     *   - `GroupConsentController::update` (the office records consent) CLEARS
     *     it, also on a re-save that changes nothing: the office is now
     *     asserting the consent for this class. WITH ONE EXCEPTION: a record of
     *     LESS than the entry in the marked class holds (the class story where
     *     that one holds photographs) KEEPS it. The family reduced what was
     *     carried; with the marker gone a move back would bring the wider
     *     consent of the other class into force and nothing would refuse it;
     *   - `GroupConsentController::destroy` (a withdrawal) does NOT touch it.
     *     The kept marker beside two blank columns is the only record that a
     *     family withdrew a carried consent, and `App\Support\RosterMove`
     *     refuses a move that would bring the other class's consent back into
     *     force while that state, or the reduced one above, stands;
     *   - `unconfirm()` clears it together with a consent it clears, and leaves
     *     it on an entry that was already blank;
     *   - leaving, returning and "Put back" never touch it.
     *
     * It names the CLASS, not the source entry: a removed place takes its
     * guardian entries with it and a merge re-issues rows, so an entry id can
     * dangle. The same adult, child and class is one row by the unique index,
     * so the source is still found while it exists. No foreign key and no
     * index, as the four `moved_*` columns.
     */
    public const CONSENT_CARRIED_FROM = 'consent_carried_from_group_id';

    private const CARRY_RECHECK_SECONDS = 30;

    /** @var array{0: bool, 1: int}|null [the column exists, when it was asked] */
    private static ?array $consentCarrySeen = null;

    /**
     * Whether `migrate` has added the marker column yet.
     *
     * bin/deploy makes the new code live BEFORE it runs `php artisan migrate`.
     * Until the column exists a move is refused with a sentence
     * (`RosterMove::ready()`), the office's consent record writes its two
     * columns as it always did, `unconfirm()` leaves the key alone, and the
     * roster list loads no carried-from class. A withdrawal never asks: it
     * must not depend on a new column.
     *
     * Remembered per process, as the check for the date-of-birth column is
     * (App\Support\StudentAge): a column that exists is remembered for good,
     * one that is missing is asked about again after CARRY_RECHECK_SECONDS,
     * and a question that could not be answered is "not yet" for that call
     * only.
     */
    public static function consentCarryReady(): bool
    {
        $now = now()->getTimestamp();
        $seen = self::$consentCarrySeen;

        if ($seen !== null && ($seen[0] || $now - $seen[1] < self::CARRY_RECHECK_SECONDS)) {
            return $seen[0];
        }

        try {
            $exists = Schema::hasColumn((new self())->getTable(), self::CONSENT_CARRIED_FROM);
        } catch (Throwable) {
            return false;
        }

        self::$consentCarrySeen = [$exists, $now];

        return $exists;
    }

    /** Forget what was seen: for a test that drops or adds the column, and for nothing else. */
    public static function forgetConsentCarryReady(): void
    {
        self::$consentCarrySeen = null;
    }

    /**
     * ==========================================================================
     * WHY THERE IS NO `created` HOOK HERE, AND MUST NOT BE ONE AGAIN
     * ==========================================================================
     *
     * A previous round hung a hook here: creating a `leader`/`member` row for a
     * contact who held a live parent-portal credential REVOKED that credential,
     * on the theory that a participant edge turns a guardian's login into a
     * student login. The theory was about a real hazard; the mechanism was
     * wrong in three separate ways, all measured:
     *
     *  1. IT WAS REACHABLE BY AN ANONYMOUS STRANGER. The public registration
     *     endpoint documents "absent registrants means the payer registers
     *     themselves" and then writes the payer a `member` row — a
     *     `GroupMembership::create()`. So a POST carrying nothing but the
     *     household address printed on a class list permanently destroyed a
     *     parent's credential: `login_revoked_at` set, live token DELETED,
     *     signed out of her phone mid-session.
     *  2. IT FIRED ON ORDINARY ADMINISTRATION. `POST …/groups/{id}/members`
     *     with `role: member` for a parent joining the adult ḥalaqa, and with
     *     `role: leader` for a teacher who is also a parent being given a
     *     class, both ended a working sign-in on a 201.
     *  3. IT COULD NOT BE UNDONE OR EXPLAINED. The audit row carried no actor
     *     at all (`actor_user_id/name/email` null), so the one screen built to
     *     answer "who took my access away" read "Sign-in revoked — Unknown
     *     staff member" for an act no staff member performed; and re-enabling
     *     answered 422, because the contact now held the participant edge the
     *     enable-time rule refused. The only remedy was to un-enrol the parent
     *     from the class they had just signed up for.
     *
     * A hook that DESTROYS AN ALREADY-ISSUED CREDENTIAL as a side effect of an
     * unrelated act, and hands that destruction to an unauthenticated caller,
     * is not a containment. The hazard it was aimed at is now answered where it
     * belongs — in `App\Support\GroupAudience::membershipsFor()`, which scopes
     * what a FAMILY credential may READ to its wards and ignores the holder's
     * own participant rows, on every request, from live roster data. Scoping
     * the read needs no revocation, refuses nothing legitimate, and cannot be
     * triggered by anybody.
     *
     * The `deleting` cascade below stays: it is a different kind of act, it
     * removes STALE ACCESS rather than a credential, and nothing can fire it
     * except removing a person from a roster.
     */
    protected static function booted(): void
    {
        // Removing someone from a roster must also remove the guardian edges
        // that pointed AT them in that group, or those rows survive as
        // guardianship over a person who is no longer in the group — a stale
        // grant of access to a minor's record. A DB cascade cannot do this (the
        // FK is on contact, not on the ward's membership row), so it is done
        // here, on the way out.
        static::deleting(function (self $membership): void {
            if (! in_array($membership->role, self::PARTICIPANT_ROLES, true)) {
                return;   // a guardian row has no dependants of its own
            }

            static::query()
                ->where('group_id', $membership->group_id)
                ->where('guardian_of_contact_id', $membership->contact_id)
                ->get()
                ->each
                ->delete();
        });

        // A CHILD LEAVING TAKES THEIR GUARDIANS' STANDING WITH THEM, for the same
        // reason the cascade above exists: a guardian edge that outlives the
        // child's place in the class is a standing grant of access to a minor's
        // class — the class story, the class-wide thread, the handout — for a
        // family that left. The edge itself is KEPT (it is the relationship, and
        // the record of who confirmed it and when consent was given), it simply
        // leaves the class on the same day the child did.
        //
        // Here rather than in the controller so it holds for every caller — a
        // console command, a future importer, a rollover — exactly as the
        // deletion cascade does. It runs in both directions: a child who comes
        // back brings their guardians back with them.
        //
        // The early return for guardian rows is also what stops this recursing:
        // updating an edge fires `updated` again, and a guardian row has no
        // dependants of its own.
        static::updated(function (self $membership): void {
            if (! $membership->wasChanged('left_on')) {
                return;
            }

            if (! in_array($membership->role, self::PARTICIPANT_ROLES, true)) {
                return;
            }

            static::query()
                ->where('group_id', $membership->group_id)
                ->where('guardian_of_contact_id', $membership->contact_id)
                ->get()
                ->each(function (self $edge) use ($membership): void {
                    $edge->forceFill([
                        'left_on' => $membership->left_on,
                        'left_recorded_by_user_id' => $membership->left_recorded_by_user_id,
                    ])->save();
                });
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The person this membership is for. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** On a guardian row, the member this guardian is attached to. */
    public function guardianOf(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'guardian_of_contact_id');
    }

    /** The staff member who confirmed this row, if anybody has. */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * The registration that asserted this row, on the rows a public form wrote.
     *
     * Provenance detail, never authority: `nullOnDelete` means a purged
     * registration leaves `provenance` standing on its own.
     */
    public function sourceRegistration(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'source_registration_id');
    }

    /**
     * On the row a student LEFT BEHIND when they were moved: the class they went
     * to. `withTrashed` because a class deleted since must still be nameable as
     * "a class that was removed" instead of reading as "never moved".
     */
    public function movedTo(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'moved_to_group_id')->withTrashed();
    }

    /** On the row a student holds NOW: the class they were moved from. */
    public function movedFrom(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'moved_from_group_id')->withTrashed();
    }

    /**
     * On a guardian entry whose consent a move carried: the class it was
     * copied from. `withTrashed` for the same reason as the two above.
     */
    public function consentCarriedFrom(): BelongsTo
    {
        return $this->belongsTo(Group::class, self::CONSENT_CARRIED_FROM)->withTrashed();
    }

    // ------------------------------------------------------------- provenance

    /**
     * Does an authenticated staff act stand behind this row?
     *
     * An UNRECOGNISED stored value is NOT confirmed — the same defensive read as
     * `hasConsent()` and `Group::kind()`. A value nobody can interpret must
     * never be read as permission, so a typo, a hand edit or a future third
     * state fails closed at every read even though the column default does not.
     */
    public function isConfirmed(): bool
    {
        return $this->provenance === self::PROVENANCE_CONFIRMED;
    }

    /** A claim nobody authenticated has stood behind yet. */
    public function isPendingClaim(): bool
    {
        return ! $this->isConfirmed();
    }

    /**
     * Stamp this row as established by an authenticated staff act.
     *
     * THE ONE PLACE A ROW BECOMES A GRANT, so "who may confirm" is answerable by
     * finding the callers of this method. There are three, and a test counts
     * them (`RosterMoveTest`), so a fourth cannot be added without this list
     * being read:
     *
     *   - `GroupMembershipsController::store`   staff typing a roster row;
     *   - `GroupMembershipsController::confirm` staff working the pending queue;
     *   - `RosterImportService::apply`          the office's own roster file,
     *                                           committed by a signed-in
     *                                           administrator.
     *
     * It is deliberately NOT reachable from a request body: see `$fillable`
     * above.
     *
     * ONE MORE NAME STANDS BESIDE THIS ONE since a student can be moved to
     * another class (2026-10-04): `carriedFrom()`. It does not CREATE a
     * confirmation. It takes one this method already wrote and COPIES it,
     * unchanged, for the SAME (adult, child) pair onto a new row in another
     * class of the same organisation. Its one caller is `RosterMove::carry()`,
     * reached only by an authenticated `manage contacts` act, and only while
     * the entry has no leaving date and its student is moving in the same
     * transaction. It is the ONLY new door for a confirmation: a roster row
     * never changes class (there is no re-point), so "who may confirm" is
     * answered by two names, this one and `carriedFrom`.
     *
     * `$actor` is nullable rather than required because a console/seeder path
     * has no `users` row to name, and a confirmation with no recorded actor is
     * still better evidence than a row with no provenance at all. The audit
     * question it leaves open ("which staff member?") is visible on the screen
     * as such rather than guessed at — the same call
     * ContactFamilyLoginController makes about an actorless login event.
     */
    public function confirmedByStaff(?User $actor): static
    {
        $this->forceFill([
            'provenance' => self::PROVENANCE_CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $actor?->getKey(),
        ]);

        return $this;
    }

    /** Has this row left the class? */
    public function hasLeft(): bool
    {
        return $this->left_on !== null;
    }

    /**
     * Record that this person left the class, on a date the office states.
     *
     * Not fillable, and stamped rather than assigned, for the same reason
     * `confirmedByStaff` is: this is not roster data the office types, it is a
     * decision with a date and an author that later reads depend on. `$actor` is
     * nullable because a console or seeder path has no `users` row to name.
     *
     * The date defaults to today and is stored as a DATE: a school's question is
     * which day, never which second.
     */
    public function markLeftByStaff(?User $actor, CarbonInterface|string|null $on = null): static
    {
        // A ROW THAT IS CURRENT AND STILL CARRIES "MOVED TO" was put back by
        // hand after a move (`returnToRoster` keeps the three columns, so the
        // roster can say "put back after a move to ..."). Leaving now is a new
        // act and not that move: left as they were, the row would read as
        // moved away for good. The roster would badge it "Moved to ...", a
        // move would be told to go and open that class, and the class store
        // would refuse to undo a prize "because the student was moved" when
        // they simply left. A move stamps its own three with `markMovedOut`
        // right after this. A row that has ALREADY left keeps them: correcting
        // a moved row's leaving date does not un-move it.
        if ($this->left_on === null && $this->moved_to_group_id !== null) {
            $this->forceFill([
                'moved_to_group_id' => null,
                'moved_on' => null,
                'moved_by_user_id' => null,
            ]);
        }

        $this->forceFill([
            'left_on' => $on ?? now()->toDateString(),
            'left_recorded_by_user_id' => $actor?->getKey(),
        ]);

        return $this;
    }

    /**
     * Put them back on the roster — a family that changed their mind, or a date
     * entered against the wrong child.
     *
     * Both columns go back to null, which is the same state as never having
     * left, because that is what reversal means here and because "absent means
     * still enrolled" only works if the absent state stays reachable. The same
     * argument the consent columns make when they are withdrawn.
     */
    public function returnToRoster(): static
    {
        $this->forceFill([
            'left_on' => null,
            'left_recorded_by_user_id' => null,
        ]);

        return $this;
    }

    // ------------------------------------------------------- moving class

    /**
     * Stamp the row a student now holds in the class they were moved INTO.
     *
     * Not fillable and stamped, like `markLeftByStaff`: it is a decision with a
     * date and an author. `moved_to_group_id` goes back to null because a row
     * that is open again in this class is no longer "moved to" anywhere.
     */
    public function markMovedIn(?User $actor, int $fromGroupId, CarbonInterface|string $on): static
    {
        $this->forceFill([
            'moved_from_group_id' => $fromGroupId,
            'moved_to_group_id' => null,
            'moved_on' => $on,
            'moved_by_user_id' => $actor?->getKey(),
        ]);

        return $this;
    }

    /**
     * Stamp the row a student LEFT BEHIND: where they went, on which day, by whom.
     *
     * `moved_from_group_id` goes back to null. `moved_on` and `moved_by_user_id`
     * are one pair of columns and now describe the move OUT, so a "moved from"
     * left beside them would be printed with the wrong day and the wrong
     * author. The `roster.move` log line keeps the earlier move.
     */
    public function markMovedOut(?User $actor, int $toGroupId, CarbonInterface|string $on): static
    {
        $this->forceFill([
            'moved_from_group_id' => null,
            'moved_to_group_id' => $toGroupId,
            'moved_on' => $on,
            'moved_by_user_id' => $actor?->getKey(),
        ]);

        return $this;
    }

    /**
     * COPY what stands behind `$old` onto this NEW row, for the same person in
     * another class.
     *
     * THE ONE GUARDED PLACE A ROSTER ROW IS COPIED, and the only way besides
     * `confirmedByStaff` that a row comes to hold a confirmation. A new row
     * defaults to `confirmed` (`$attributes`), so a copy that carries a
     * confirmation is a second door onto the grant `confirmedByStaff` writes.
     * It is built to fail closed: its one caller (`RosterMove::carry`) stamps
     * the new row `selfAssertedFrom(null)` FIRST and calls this second, so a row
     * that never reaches this method is an unconfirmed claim with no consent.
     *
     * It throws unless ALL of: this row is not saved yet; `contact_id`, `role`,
     * `guardian_of_contact_id` and `masjid_id` equal the old row's; `group_id`
     * differs; the old row has no leaving date (an entry that has left never
     * travels).
     *
     * The `masjid_id` compared here is whatever the caller typed onto the
     * unsaved row, and the `creating` hook overwrites it from the bound tenant
     * at save. So it is a check on the caller, not the guard: the guard is that
     * `RosterMove::carry` compares the TARGET CLASS's organisation with the old
     * row's, and that the target class was found through the tenant scope.
     *
     * It copies `provenance` and `source_registration_id`, and `confirmed_at` /
     * `confirmed_by_user_id` ONLY when the old provenance is exactly
     * `confirmed`. Never a leaving date. The confirmer and the time stay the
     * original ones: the person who moves a student has confirmed nothing. A
     * stored provenance nobody can interpret is copied as `self_asserted`, with
     * no confirmer and no time, which is the state `selfAssertedFrom` writes.
     *
     * ## CONSENT IS CARRIED AS IT IS, when the caller says so (2026-10-05)
     *
     * The owner's words: "Carry each parent's consent as it is", on a single
     * move and on a whole-class move alike. Until then a copy never held
     * consent and every family was asked again in the new class.
     *
     * `$withConsent` defaults to FALSE: a caller that says nothing copies
     * nothing. With it, and only inside the confirmed branch:
     *
     *   - ONLY ONTO A ROW THE MOVE CREATES. This method throws on a saved row,
     *     so an entry the new class already holds is never written, whatever
     *     it holds.
     *   - ONLY FROM A CONFIRMED, CURRENT ENTRY THAT HAS CONSENT: `hasConsent()`
     *     (confirmed, dated, a known scope), never `consentColumnsAreSet()`,
     *     which is true for half a record. A source that has left throws above.
     *   - ONLY A GUARDIAN ENTRY. The move also copies the student's own place
     *     through here, and `hasConsent()` does not look at the role.
     *   - THE TWO COLUMNS UNCHANGED: the scope, and the day the family gave it.
     *     Not the move day, and never a narrower scope than was recorded.
     *   - MARKED with the class it came from (THE MARKER, above), so a carried
     *     consent can be told from a recorded one for as long as the row exists.
     *
     * The unconfirmed branch sets both consent columns to null: both are
     * fillable, so a claim must not keep whatever the caller typed onto the
     * unsaved row. Whether a consent MAY travel (the adult may already stand in
     * the new class for another child with less) is the move's decision, made
     * before it calls this: `App\Support\RosterMove::decide()`.
     */
    public function carriedFrom(self $old, bool $withConsent = false): static
    {
        if ($this->exists) {
            throw new \LogicException('Only a new roster row can carry another row\'s standing.');
        }

        if ($old->left_on !== null) {
            throw new \LogicException('A roster row that has left is not carried into another class.');
        }

        $samePerson = (int) $this->contact_id === (int) $old->contact_id
            && $this->role === $old->role
            && $this->guardian_of_contact_id == $old->guardian_of_contact_id
            && (int) $this->masjid_id === (int) $old->masjid_id;

        if (! $samePerson) {
            throw new \LogicException('A roster row carries standing only for the same person, role, child and organisation.');
        }

        if ((int) $this->group_id === (int) $old->group_id) {
            throw new \LogicException('A roster row is carried into another class, not into its own.');
        }

        if (! $old->isConfirmed()) {
            // Covers `self_asserted`, NULL and any value this build does not
            // know. No confirmer and no time travel with it, whatever the old
            // row's columns say, and no consent: a claim holds none.
            return $this->selfAssertedFrom(null)->forceFill([
                'source_registration_id' => $old->source_registration_id,
                'consent_granted_at' => null,
                'consent_scope' => null,
            ]);
        }

        $this->forceFill([
            'provenance' => self::PROVENANCE_CONFIRMED,
            'confirmed_at' => $old->confirmed_at,
            'confirmed_by_user_id' => $old->confirmed_by_user_id,
            'source_registration_id' => $old->source_registration_id,
        ]);

        if ($withConsent && $this->isGuardian() && $old->hasConsent()) {
            $this->forceFill([
                'consent_scope' => $old->consent_scope,
                'consent_granted_at' => $old->consent_granted_at,
                self::CONSENT_CARRIED_FROM => $old->group_id,
            ]);
        }

        return $this;
    }

    /**
     * Stamp this row as a public form's claim: on the record, granting nothing.
     *
     * `$registration` is what lets the office judge the claim instead of merely
     * seeing one — it carries the payer who typed it and the program they typed
     * it into.
     */
    public function selfAssertedFrom(?Registration $registration = null): static
    {
        $this->forceFill([
            'provenance' => self::PROVENANCE_SELF_ASSERTED,
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
            'source_registration_id' => $registration?->getKey(),
        ]);

        return $this;
    }

    /**
     * Return a row to the un-confirmed state, keeping how it arose.
     *
     * Used by `RosterMergeService` when a merge changes WHO a row is about and
     * the row cannot be retired and re-issued in its place: what a staff member
     * confirmed was "this adult is the guardian of THAT NAMED PERSON", and
     * changing either end makes the confirmation a statement about somebody they
     * were never asked about. A merge is a de-duplication, never an
     * authorization decision.
     *
     * ## THE CONSENT COLUMNS GO WITH IT, and leaving them was a defect
     *
     * This method cleared `provenance`, `confirmed_at` and `confirmed_by_user_id`
     * and left `consent_granted_at` / `consent_scope` standing — producing a row
     * THIS APPLICATION REFUSES TO CREATE. `GroupConsentController::update()`
     * answers 422 on an unconfirmed claim ("consent has to be recorded against a
     * relationship this organisation has stood behind"), and every read path was
     * happy to serve the state anyway. Measured, on a merge that re-pointed a
     * confirmed edge from a phantom child onto a real one:
     *
     *     EDGE AFTER: prov=self_asserted consent_scope='media'
     *                 consent_granted_at='2026-08-17 17:21:25'
     *     PUT  …/consent -> 422 "still an unconfirmed claim … Confirm it first"
     *     GET  …/consent -> 200 {"scope":"media","covers_feed":true,
     *                            "covers_media":true}
     *     CONFIRM -> 200 ; FEED -> 200 {"title":"Class photograph",
     *                                   "media_withheld":false}
     *
     * So consent obtained about ONE child governed disclosure about ANOTHER, and
     * a single confirm click opened the photograph bytes with no second decision
     * in between — the precise thing `GroupConsentController`'s own guard exists
     * to make impossible. Consent is permission to disclose something about one
     * named child to one named adult; a row that is no longer that pair holds no
     * such permission, and "absence of a record means no consent" is only true if
     * this method makes the absence true.
     *
     * WITHDRAWAL IS STILL NOT GATED (`GroupConsentController::destroy`): the one
     * direction this area may never fail in is leaving consent standing on a row
     * somebody was trying to undo, and clearing more here cannot cause that.
     *
     * ## THE MARKER GOES WITH A CONSENT THIS CLEARS, AND ONLY THEN
     *
     * When the two columns held something, "carried from that class" goes too:
     * the row is no longer that pair, nobody withdrew, and a marker left on a
     * blank unconfirmed entry would read "withdrawn after a carry". When they
     * were ALREADY blank the marker is left alone. That state records a
     * family's withdrawal, a merge is a de-duplication of the same child, and
     * forgetting it would let a later return bring the old class's consent
     * back into force. The key is written only once its column exists.
     */
    public function unconfirm(): static
    {
        $heldConsent = $this->consentColumnsAreSet();

        $this->forceFill([
            'provenance' => self::PROVENANCE_SELF_ASSERTED,
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
            'consent_granted_at' => null,
            'consent_scope' => null,
        ]);

        if ($heldConsent && self::consentCarryReady()) {
            $this->forceFill([self::CONSENT_CARRIED_FROM => null]);
        }

        return $this;
    }

    /**
     * On a PARTICIPANT row, this student's behaviour/recognition records
     * (T-013). Guardian edges never carry awards of their own — an award is
     * given to a person, and a guardian row is a relationship.
     *
     * Never serialized with the membership: who may see these is decided per
     * request by App\Support\GroupAudience, and a roster listing is read by
     * people who may not see any of them.
     */
    public function behaviorAwards(): HasMany
    {
        return $this->hasMany(BehaviorAward::class, 'group_membership_id');
    }

    /**
     * On a PARTICIPANT row, this student's ḥifẓ recitation records (T-014).
     * Guardian edges never carry entries of their own — a recitation is heard
     * from a person, and a guardian row is a relationship.
     *
     * A student's current position in the muṣḥaf is DERIVED from the sabak rows
     * here (App\Support\HifzProgress); there is deliberately no position column
     * on this model to fall out of step with them.
     *
     * Never serialized with the membership: who may see these is decided per
     * request by App\Support\GroupAudience, and a roster listing is read by
     * people who may not see any of them.
     */
    public function hifzEntries(): HasMany
    {
        return $this->hasMany(HifzEntry::class, 'group_membership_id');
    }

    /**
     * On a PARTICIPANT row, this student's marks (T-gradebook).
     *
     * Never serialized with the membership, for the same reason as the two
     * relations above: a roster listing is read by people entitled to none of
     * these, and who may see one is decided per request by GroupAudience.
     *
     * Reading these WITHOUT joining `assignment` is a defect: class_assignments
     * soft-deletes, so this relation can name rows whose parent no longer
     * resolves. Every consumer either joins it or uses whereHas('assignment').
     */
    public function assignmentScores(): HasMany
    {
        return $this->hasMany(AssignmentScore::class, 'group_membership_id');
    }

    public function isGuardian(): bool
    {
        return $this->role === self::ROLE_GUARDIAN;
    }

    /**
     * DOES THIS EDGE CARRY CONSENT THAT GRANTS ANYTHING?
     *
     * Three conditions, and the third was missing.
     *
     * BOTH COLUMNS MUST BE MEANINGFUL. A granted_at with an unrecognised scope
     * grants nothing: a value nobody can interpret must not be read as
     * permission, the same defensive read as Group::kind() degrading an unknown
     * kind rather than letting it behave as one nobody granted.
     *
     * AND THE EDGE MUST BE CONFIRMED. `GroupConsentController::update()` has
     * always refused to WRITE consent onto a pending claim — "consent has to be
     * recorded against a relationship this organisation has stood behind" — and
     * until this round every READ path was happy to serve the state anyway. A
     * server that refuses to write a state it happily reads has not got a rule;
     * it has a form validation. Measured, against a row left in that state by a
     * merge before `unconfirm()` learned to clear the columns:
     *
     *     GET  …/consent -> 200 {"scope":"media","covers_feed":true,
     *                            "covers_media":true}
     *     PUT  …/consent -> 422 "still an unconfirmed claim … Confirm it first"
     *     CONFIRM        -> {"confirmed":1,"skipped":0}
     *     edge after     -> {"provenance":"confirmed","consent_scope":"media"}
     *     family feed    -> 200 [{"title":"Class photograph", …}]
     *
     * — consent obtained about ONE child governing disclosure about ANOTHER, and
     * ONE Confirm click opening the photographs with no second decision in
     * between. `unconfirm()` clearing the columns fixes the rows a merge writes
     * FROM NOW ON and reaches nothing already on disk; a read that consults
     * provenance holds for every row, including the ones a future writer forgets
     * about. Both were needed, and the rows already on disk are cleared by
     * `…_clear_consent_on_unconfirmed_group_memberships`.
     *
     * THE GATE IS HERE AND NOT IN THE CONTROLLER on purpose. `consentCovers()`,
     * `GroupConsentController::show()` and `App\Support\GroupAudience` are three
     * surfaces reading one fact, and this round's whole lesson is that a
     * safeguard written on one surface gets believed about all of them.
     */
    public function hasConsent(): bool
    {
        return $this->isConfirmed()
            && $this->consent_granted_at !== null
            && in_array($this->consent_scope, self::CONSENT_SCOPES, true);
    }

    /**
     * ARE THE TWO CONSENT COLUMNS CARRYING ANYTHING? — a question about BYTES.
     *
     * NOT A PERMISSION CHECK, and nothing that decides a disclosure may call it:
     * it deliberately ignores provenance, so it answers `true` for exactly the
     * corrupt state `hasConsent()` exists to refuse.
     *
     * It has two callers, and both ask about the record, never about what the
     * record granted:
     *
     *   - `RosterMergeService::describe()`, which reports to the operator what a
     *     merge is about to ERASE from a row. Telling an office "consent
     *     withdrawn: false" while deleting a `consent_scope` column would be a
     *     different lie from the one that round was fixing.
     *   - `App\Support\RosterMove` (2026-10-04), which says whether the place a
     *     moved student leaves behind may be removed afterwards. Removing it
     *     takes the guardian entries beside it and whatever consent they carry,
     *     so ANY byte in these columns, on a confirmed entry or not, means "do
     *     not offer Remove". The safe direction for this method's answer.
     */
    public function consentColumnsAreSet(): bool
    {
        return $this->consent_granted_at !== null || $this->consent_scope !== null;
    }

    /**
     * HOW MUCH A SCOPE OPENS, as a number to compare two consents by: 0 for
     * none (or a scope nobody can read), 1 for the class story, 2 for the
     * class story and photographs.
     */
    public static function consentRankOf(?string $scope): int
    {
        return match ($scope) {
            self::CONSENT_MEDIA => 2,
            self::CONSENT_FEED => 1,
            default => 0,
        };
    }

    /** How much THIS entry's consent opens now: 0 when none is in force (`hasConsent()`). */
    public function consentRank(): int
    {
        return $this->hasConsent() ? self::consentRankOf($this->consent_scope) : 0;
    }

    /**
     * THE ENTRY A CARRIED CONSENT WAS COPIED FROM: the same adult and child in
     * the class THE MARKER names, current or closed, or null when this entry
     * is not marked or that class holds no entry for them any more (the place
     * there was removed, or a merge re-issued the row). One row by the unique
     * index. The organisation is named in the query as well as scoped by the
     * model, so it holds where no tenant is bound.
     */
    public function carriedConsentSource(): ?self
    {
        $from = $this->{self::CONSENT_CARRIED_FROM};

        if ($from === null || ! $this->isGuardian()) {
            return null;
        }

        return self::query()
            ->where('masjid_id', $this->masjid_id)
            ->where('group_id', $from)
            ->where('role', self::ROLE_GUARDIAN)
            ->where('contact_id', $this->contact_id)
            ->where('guardian_of_contact_id', $this->guardian_of_contact_id)
            ->first();
    }

    /**
     * IS THIS A CARRIED CONSENT THAT NOW STANDS FOR LESS THAN THE CLASS IT CAME
     * FROM HOLDS? Marked, in force, and narrower than `$source` (the class
     * story here, photographs there).
     *
     * It is how a consent the family REDUCED after a move reads, and it is a
     * comparison of two rows, not a memory of an act: an untouched copy whose
     * source was recorded WIDER afterwards reads the same. So every sentence
     * built on it says what the two classes hold and never "was reduced".
     */
    public function holdsLessThanCarriedFrom(?self $source): bool
    {
        return $this->{self::CONSENT_CARRIED_FROM} !== null
            && $source !== null
            && $this->hasConsent()
            && $this->consentRank() < $source->consentRank();
    }

    /**
     * Does this edge's recorded consent cover the disclosure being asked for?
     *
     * Only meaningful on a guardian row — a leader/member row is the person
     * themselves, and a person needs no consent to be shown their own group.
     * This is the check that .claude/rules/groups.md requires at the point of
     * disclosure; App\Support\GroupAudience is the only caller.
     */
    public function consentCovers(string $disclosure): bool
    {
        if (! $this->isGuardian() || ! $this->hasConsent()) {
            return false;
        }

        $accepted = self::CONSENT_COVERAGE[$disclosure] ?? null;

        // An unknown disclosure is never covered — a typo must fail closed.
        return $accepted !== null && in_array($this->consent_scope, $accepted, true);
    }

    public function scopeParticipants($query)
    {
        return $query->whereIn('role', self::PARTICIPANT_ROLES);
    }

    /**
     * Rows still ON the roster — the answer to "who is in this class", which is
     * what every register, gradebook, points, ḥifẓ and letters read wants.
     *
     * Deliberately a SEPARATE scope from `participants()` rather than folded
     * into it: the record surfaces must keep resolving a departed child's own
     * history (a report card written in October is still that child's), so
     * "which rows are people in this class" and "which of them are still here"
     * are two different questions and a caller has to say which it is asking.
     */
    public function scopeCurrent($query)
    {
        return $query->whereNull('left_on');
    }

    /** The departed — for a roster that wants to show them under their own heading. */
    public function scopeWithdrawn($query)
    {
        return $query->whereNotNull('left_on');
    }

    /**
     * Guardian edges whose consent GRANTS something (in any scope).
     *
     * `->confirmed()` is part of the definition for the same reason
     * `scopePendingClaims()` spells NULL out in SQL: the class docblock promises
     * that a scope and its row-by-row method are one definition, and the
     * direction of a disagreement here is the dangerous one — a query-level
     * consent check that counted rows `hasConsent()` refuses would be a
     * disclosure decided by which of the two a caller happened to reach for.
     */
    public function scopeConsented($query)
    {
        return $query->where('role', self::ROLE_GUARDIAN)
            ->confirmed()
            ->whereNotNull('consent_granted_at')
            ->whereIn('consent_scope', self::CONSENT_SCOPES);
    }

    /**
     * Rows an authenticated staff act stands behind — the only rows that grant
     * anything. Expressed as a scope so the QUERY-level constraints in
     * `GroupAudience` and `FamilyAccessService` use the same definition
     * `isConfirmed()` uses row by row.
     */
    public function scopeConfirmed($query)
    {
        return $query->where('provenance', self::PROVENANCE_CONFIRMED);
    }

    /**
     * Claims a public form made that nobody has stood behind yet.
     *
     * NULL IS PENDING, and it has to be said in SQL because SQL will not say it
     * for us: `provenance != 'confirmed'` is UNKNOWN for a NULL and the row
     * drops out of the result. `isPendingClaim()` has always treated NULL as
     * pending — anything that is not exactly `confirmed` is — and the class
     * docblock promises the two are one definition. They were not, and the
     * direction of the disagreement is the dangerous one: a NULL row would read
     * as pending everywhere a human looked and be invisible to the queue that
     * exists to clear it. Unreachable today (NOT NULL + a default), and one
     * nullable column or one import away from being an invisible queue.
     */
    public function scopePendingClaims($query)
    {
        return $query->where(function ($q) {
            $q->where('provenance', '!=', self::PROVENANCE_CONFIRMED)
                ->orWhereNull('provenance');
        });
    }
}
