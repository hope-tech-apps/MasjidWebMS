<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file kept for one class. The row is a pointer; the bytes live on a PRIVATE
 * disk, and nothing outside this model builds the path or touches the disk.
 *
 * There is NO public URL for one and no accessor that could produce one. The
 * only ways out are the two download routes, each of which re-resolves
 * masjid -> group -> resource and — on the family side — applies
 * `visibleToFamilies()` as a SCOPE so a staff-only file is a 404 rather than a
 * 403 that confirms it exists. See .claude/rules/private-uploads.md.
 *
 * Never add `temporaryUrl()`, `url()` or a signed route here.
 * config/filesystems.php sets 'serve' => true on the local disk, which registers
 * a GET over storage/app/private; it is latent only because nothing mints a
 * signature today. One such line would hand out a link that bypasses the tenant
 * scope, the ownership chain, GroupAudience, and consent WITHDRAWAL.
 */
class GroupResource extends Model
{
    use BelongsToMasjid;

    /**
     * Who may fetch the bytes.
     *
     * `staff` is the DEFAULT and the fail-closed direction. `families` publishes
     * the file to every guardian in the class — and the server cannot read
     * inside a PDF to check what it names, so this flag is the whole control.
     */
    public const VISIBILITY_STAFF = 'staff';
    public const VISIBILITY_FAMILIES = 'families';

    public const VISIBILITIES = [
        self::VISIBILITY_STAFF,
        self::VISIBILITY_FAMILIES,
    ];

    protected $fillable = [
        'masjid_id',
        'group_id',
        'uploaded_by_user_id',
        'title',
        'description',
        'visibility',
        'original_name',
        'mime_type',
        'size_bytes',
        'disk',
        'path',
    ];

    protected $attributes = [
        'visibility' => self::VISIBILITY_STAFF,
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
     * On `deleting` rather than `deleted`, copied from GroupPostAttachment, so
     * that a failure to remove the row does not leave us having already
     * destroyed the only copy of the file.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $resource): void {
            if ($resource->path) {
                $resource->storage()->delete($resource->path);
            }
        });
    }

    /** Files this class has chosen to share with its families. */
    public function scopeVisibleToFamilies($query)
    {
        return $query->where('visibility', self::VISIBILITY_FAMILIES);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** The disk this particular file was written to, which may predate a config change. */
    public function storage()
    {
        return Storage::disk($this->disk);
    }

    public function exists(): bool
    {
        return $this->path && $this->storage()->exists($this->path);
    }

    /**
     * The shape both realms serialize. Carries NO url field, by design — the
     * only route to the bytes is a download endpoint that re-checks standing.
     */
    public function toAudienceArray(): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
