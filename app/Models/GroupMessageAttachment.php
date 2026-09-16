<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photo sent inside a group conversation.
 *
 * The message-side twin of GroupPostAttachment, and deliberately the same in
 * every respect that matters: the row is a pointer, the bytes live on a PRIVATE
 * disk, and there is NO public URL for one and no accessor that could produce
 * one. The only ways out are the three `downloadAttachment` actions on the
 * thread controllers (staff, teacher, family), each of which re-resolves
 * masjid -> group -> thread -> message -> attachment and asks GroupAudience
 * whether this reader may have it. See .claude/rules/private-uploads.md.
 *
 * WHO MAY SEE ONE is the thread's audience, with one addition. A private
 * conversation about one child is not a broadcast, so consent is not consulted
 * there — the same reasoning GroupAudience::mayReceiveThread() documents. A
 * CLASS-WIDE conversation is a broadcast, so its photos need the media consent
 * the class story's photos need. GroupAudience::mayReceiveThreadMedia() is that
 * one decision.
 */
class GroupMessageAttachment extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_message_id',
        'original_name',
        'mime_type',
        'size_bytes',
        'disk',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * Delete the bytes with the row — on `deleting`, so a failure to remove the
     * row does not leave us having already destroyed the only copy.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment): void {
            if ($attachment->path) {
                $attachment->storage()->delete($attachment->path);
            }
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(GroupMessage::class, 'group_message_id');
    }

    /** The disk this particular file was written to, which may predate a config change. */
    public function storage()
    {
        return Storage::disk($this->disk);
    }

    public function exists(): bool
    {
        return (bool) $this->path && $this->storage()->exists($this->path);
    }

    /**
     * What an entitled reader sees for one photo. No URL: each controller adds
     * the download path for its own realm, because only it knows the route.
     *
     * @return array<string,mixed>
     */
    public function toAudienceArray(): array
    {
        return [
            'id' => (int) $this->id,
            'file_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
        ];
    }
}
