<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A donor's card last-4 (never a full PAN). Tenant-scoped. Used for historical
 * "who is card 8016?" lookups and to attach an anonymous card row to a member.
 */
class ContactCard extends Model
{
    use HasFactory, BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'contact_id',
        'last4',
        'brand',
        'note',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
