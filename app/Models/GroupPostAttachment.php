<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One image attached to a group feed post — a photograph of a child.
 *
 * The row is a pointer; the bytes live on a PRIVATE disk. Nothing outside this
 * model builds the path or touches the disk, so "where is it and who may read
 * it" has exactly one answer.
 *
 * There is NO public URL for one and no accessor that could produce one. The
 * only way out is GroupPostsController::downloadAttachment, behind
 * auth:sanctum + admin + tenant + crm + permission, which re-resolves
 * masjid -> group -> post -> attachment AND asks GroupAudience whether this
 * reader may receive media at all. See .claude/rules/private-uploads.md.
 *
 * Tenant-scoped with BelongsToMasjid rather than relying on the parent chain:
 * .claude/rules/tenant-scoping.md makes the trait mandatory for every
 * tenant-scoped model, and a child's photograph is the last row that should
 * depend on someone remembering to join through its parent.
 */
class GroupPostAttachment extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_post_id',
        'original_name',
        'mime_type',
        'size_bytes',
        'disk',
        'path',
        // Only ever set for a VIDEO, whose window (90 days) is shorter than
        // the post or thread it hangs on (365). Null for a photograph, which
        // still dies exactly when its parent does. See App\Support\GroupMedia.
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'retained_until' => 'date',
        ];
    }

    /**
     * Delete the bytes with the row.
     *
     * On `deleting` rather than `deleted` so that a failure to remove the row
     * does not leave us having already destroyed the only copy of the image.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment): void {
            if ($attachment->path) {
                $attachment->storage()->delete($attachment->path);
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(GroupPost::class, 'group_post_id');
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
     * What an entitled reader sees for one image. No URL is included here — the
     * controller builds the download link, because only it knows the route, and
     * only it has already decided that this reader may receive media at all.
     *
     * @return array<string,mixed>
     */
    public function toAudienceArray(): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            // Stated rather than left for each client to re-derive from the
            // mime string. Three renderers (office, teacher, family) and two
            // native apps read this payload, and a video rendered by an <img>
            // is a silent broken image rather than an error anyone sees.
            'is_video' => \App\Support\GroupMedia::isVideo($this->mime_type),
            // The attachment's OWN window, when it has one (video only). Null
            // means it dies with its post or thread, which is every photograph.
            'retained_until' => optional($this->retained_until)->toDateString(),
            'size_bytes' => $this->size_bytes,
            'uploaded_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Rows whose OWN retention window has closed — the video sweep.
     *
     * A null `retained_until` is never due: it means nobody set a window (every
     * photograph), not "purge me now". Identical in shape to
     * GroupPost::scopeDueForPurge and GroupThread's, deliberately — retention
     * over a group's content is one policy, and a fourth spelling of the same
     * query is how the fourth one drifts.
     */
    public function scopeDueForPurge($query, ?string $asOf = null)
    {
        return $query
            ->whereNotNull('retained_until')
            ->whereDate('retained_until', '<=', $asOf ?? now()->toDateString());
    }

    /**
     * Destroy the row AND the bytes.
     *
     * A plain delete() already reaches the disk here (this model is not
     * soft-deleted and its `deleting` hook removes the file), so this exists for
     * the name: `groups:purge-feed` says `purge()` on every model it sweeps, and
     * a sweep that said delete() on one of the four would read as a different
     * kind of removal than it is.
     */
    public function purge(): void
    {
        $this->delete();
    }
}
