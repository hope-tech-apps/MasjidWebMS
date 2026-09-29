<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupPostRead — a guardian opened a class story post (owner, 2026-09-29).
 *
 * The receipt behind "Seen by 4 of 7 parents". It is written ONLY by
 * Family\GroupPostsController::markSeen, from a client POST fired when the Story
 * tab is showing the posts, and ONLY while `groups.story_reads.enabled` is on
 * (off by default until the parent-facing notice's translations are reviewed).
 * It is never written by a GET.
 *
 * WHO IT IS SHOWN TO: staff only. The serializer that builds `seen_by`,
 * `seen_count` and `audience_count` is the admin/teacher one; the family payload
 * never builds them (a test walks the family JSON). A parent is never told
 * another parent opened anything.
 *
 * Tenant-scoped through BelongsToMasjid. The controller writes with
 * insertOrIgnore (which fires no model events), so it stamps `masjid_id` itself
 * from the group it just resolved through the bound tenant — never from the
 * request.
 */
class GroupPostRead extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_post_id',
        'contact_id',
        'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(GroupPost::class, 'group_post_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
