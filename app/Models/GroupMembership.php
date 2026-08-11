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

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'consent_granted_at' => 'datetime',
        ];
    }

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
}
