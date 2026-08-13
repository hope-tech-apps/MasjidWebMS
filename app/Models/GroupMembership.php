<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     */
    protected $fillable = [
        'masjid_id',
        'group_id',
        'contact_id',
        'role',
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
            'consent_granted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
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
     * finding the callers of this method: `GroupMembershipsController::store`
     * (staff typing a roster row) and `::confirm` (staff working the pending
     * queue). It is deliberately NOT reachable from a request body — see
     * `$fillable` above.
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
     * Used by `RosterMergeService` when a merge re-points a CONFIRMED guardian
     * edge at a DIFFERENT ward: what a staff member confirmed was "this adult is
     * the guardian of THAT NAMED PERSON", and re-pointing changes the person, so
     * the confirmation no longer describes the row it sits on. A merge is a
     * de-duplication, never an authorization decision.
     */
    public function unconfirm(): static
    {
        $this->forceFill([
            'provenance' => self::PROVENANCE_SELF_ASSERTED,
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
        ]);

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

    public function isGuardian(): bool
    {
        return $this->role === self::ROLE_GUARDIAN;
    }

    /**
     * Has consent been recorded on this edge at all?
     *
     * BOTH columns must be meaningful. A granted_at with an unrecognised scope
     * grants nothing: a value nobody can interpret must not be read as
     * permission, the same defensive read as Group::kind() degrading an unknown
     * kind rather than letting it behave as one nobody granted.
     */
    public function hasConsent(): bool
    {
        return $this->consent_granted_at !== null
            && in_array($this->consent_scope, self::CONSENT_SCOPES, true);
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

    /** Guardian edges with consent recorded (in any scope). */
    public function scopeConsented($query)
    {
        return $query->where('role', self::ROLE_GUARDIAN)
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
