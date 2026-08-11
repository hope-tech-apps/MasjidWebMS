<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact — a congregant record. First consumer of the CRM tenant-isolation
 * guardrail (Phase 0 of the donation/CRM build).
 *
 * The BelongsToMasjid trait supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * in $fillable so system/super code can set it while UNBOUND; when a tenant is
 * bound the creating hook overrides it regardless. See BelongsToMasjid.
 */
class Contact extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
        'is_placeholder',
        'import_batch',
    ];

    protected function casts(): array
    {
        return [
            'is_placeholder' => 'boolean',
        ];
    }

    /** Card last-4 records for this contact (historical lookup + placeholder merge). */
    public function cards()
    {
        return $this->hasMany(ContactCard::class);
    }

    /** Succeeded giving attributed to this contact (Stripe + offline alike). */
    public function donations()
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * This person's places in groups — their own leader/member rows AND the
     * guardian edges they hold over someone else. Additive: groups reference a
     * contact, they never duplicate one. See .claude/rules/groups.md.
     */
    public function groupMemberships()
    {
        return $this->hasMany(GroupMembership::class);
    }

    /** The groups this contact appears in, in any role. */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_memberships')
            ->withPivot(['role', 'guardian_of_contact_id', 'joined_at'])
            ->withTimestamps();
    }
}
