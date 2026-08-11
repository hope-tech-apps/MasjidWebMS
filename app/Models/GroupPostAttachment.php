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
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
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
            'size_bytes' => $this->size_bytes,
            'uploaded_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
