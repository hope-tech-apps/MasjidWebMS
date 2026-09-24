<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student a class file was addressed to.
 *
 * A join row and nothing else: it carries no permission of its own, and no code
 * anywhere reads it to DECIDE anything. `App\Support\GroupAudience` is the only
 * place that turns a recipient set into an answer, which is what keeps a
 * targeted handout under the same rule as a participant thread, an award and a
 * ḥifẓ entry rather than under a second one.
 *
 * `membership` is the relation name on purpose — it is the name
 * `GroupAudience::constrainToOwnStudents()` requires of every model whose
 * subject is a student, so this row can be constrained by the same code.
 */
class GroupResourceRecipient extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_resource_id',
        'group_membership_id',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(GroupResource::class, 'group_resource_id');
    }

    /** The student. Named `membership` to match every other record-about-a-student model. */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(GroupMembership::class, 'group_membership_id');
    }
}
