<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registrant — one person a registration is FOR. A guardian registering three
 * children holds one Registration and three Registrant rows.
 *
 * Contacts-first (.claude/rules/groups.md): a registrant references an existing
 * CRM Contact and never duplicates a person — no name/email columns here, ever.
 * On confirmation, T-006b materialises these rows into group_memberships in
 * offerings.group_id (with guardian_of_contact_id edges) via the existing
 * groups invariants; this row remains the registration-side record.
 *
 * Tenant-scoped by a denormalised masjid_id so BelongsToMasjid scopes reads
 * without joining through registrations (same arrangement as group_memberships).
 * The (registration_id, contact_id) unique index dedupes exactly — neither
 * column is nullable, so it needs no controller-side backstop.
 */
class Registrant extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'registration_id',
        'contact_id',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** The person this roster row is about. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
