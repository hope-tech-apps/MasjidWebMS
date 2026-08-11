<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Group — the second scoping level of the core: org -> group -> member.
 *
 * ONE primitive for every vertical (DECISIONS.md 2026-08-10): a School's
 * classroom, a Masjid's halaqa or weekend-school circle, a Community org's
 * volunteer team. A group belongs to exactly one organization.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * fillable so system/super code can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 *
 * Admin-facing naming NEVER comes from this class. What a group is CALLED is the
 * tenant's vocabulary — $masjid->term('groups') yields "Halaqat" / "Classrooms" /
 * "Teams" — so nothing here or in the controllers may hardcode "Classroom".
 * See .claude/rules/verticals.md.
 */
class Group extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    /**
     * What KIND of group this is. A discriminator for UI and for later
     * vertical-specific behaviour, never an authorization check.
     *
     * Deliberately PHP constants rather than a DB enum, exactly like
     * Masjid::ORG_TYPES: adding a kind must not mean ALTER TABLE on a live
     * table. See the create_groups_table migration and
     * .claude/rules/migrations.md.
     */
    public const KIND_GENERAL = 'general';
    public const KIND_CLASS = 'class';
    public const KIND_HALAQA = 'halaqa';
    public const KIND_TEAM = 'team';

    public const KINDS = [
        self::KIND_GENERAL,
        self::KIND_CLASS,
        self::KIND_HALAQA,
        self::KIND_TEAM,
    ];

    protected $fillable = [
        'masjid_id',
        'name',
        'slug',
        'kind',
        'description',
        'is_active',
        'starts_on',
        'ends_on',
    ];

    protected $attributes = [
        'kind' => self::KIND_GENERAL,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * The stored kind, degraded to `general` when unrecognized — the same
     * defensive read as Masjid::orgType(). An unknown value must never let a
     * group behave as a kind nobody granted it.
     */
    public function kind(): string
    {
        $kind = $this->attributes['kind'] ?? null;

        return in_array($kind, self::KINDS, true) ? $kind : self::KIND_GENERAL;
    }

    /**
     * Force-deleting a group must reach the disk (T-005b).
     *
     * `group_posts` and `group_post_attachments` cascade off `groups` at the DB
     * level, and a DB cascade fires NO model events — so without this hook a
     * hard-deleted group would leave every classroom photograph it ever carried
     * on disk forever, unreferenced and unpurgeable. See
     * .claude/rules/private-uploads.md.
     *
     * Only on a FORCE delete. The ordinary destroy path soft-deletes on purpose:
     * a mis-click must not destroy a roster, and it must not destroy the class
     * story either. Bytes go when the retention purge says they go.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $group): void {
            if (! $group->isForceDeleting()) {
                return;
            }

            $group->posts()->withTrashed()->get()->each->purge();
        });
    }

    /** Every membership row in this group, guardian edges included. */
    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    /**
     * This group's PRIVATE activity feed (T-005b). Never a public surface: who
     * may read it is decided per request by App\Support\GroupAudience.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(GroupPost::class);
    }

    /**
     * The people in this group. Guardians appear here too (they hold a
     * membership); read the pivot's `role` to tell them apart.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'group_memberships')
            ->withPivot(['role', 'guardian_of_contact_id', 'joined_at'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }
}
