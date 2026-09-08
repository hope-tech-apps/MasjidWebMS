<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Tell me about this service" — one member, one service.
 *
 * A NOTIFICATION ROUTE AND NOTHING ELSE. An interest must never be read as
 * permission: it says where a member wants to hear from, not what they may
 * see. Anything access-bearing belongs in `group_memberships`, which already
 * models roles and guardian edges and is audited for it. If a future screen
 * ever asks "is this person allowed in?", this table is the wrong answer.
 *
 * The member owns it. Staff can see interests, but the write paths are the
 * member's own — an office that could silently subscribe congregants would be
 * building a mailing list nobody consented to, which is the failure mode SMS
 * consent (T-009) exists to prevent on the other channel.
 */
class ContactServiceInterest extends Model
{
    use BelongsToMasjid;

    protected $table = 'contact_service_interests';

    protected $fillable = [
        'masjid_id',
        'contact_id',
        'service_id',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
