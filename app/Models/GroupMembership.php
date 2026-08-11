<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = [
        'masjid_id',
        'group_id',
        'contact_id',
        'role',
        'guardian_of_contact_id',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
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

    public function isGuardian(): bool
    {
        return $this->role === self::ROLE_GUARDIAN;
    }

    public function scopeParticipants($query)
    {
        return $query->whereIn('role', self::PARTICIPANT_ROLES);
    }
}
