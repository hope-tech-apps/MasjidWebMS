<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A file kept for one class. The row is a pointer; the bytes live on a PRIVATE
 * disk, and nothing outside this model builds the path or touches the disk.
 *
 * There is NO public URL for one and no accessor that could produce one. The
 * only ways out are the two download routes, each of which re-resolves
 * masjid -> group -> resource and — on the family side — narrows by
 * `GroupAudience::readableResourcesQuery()` as a QUERY CONSTRAINT, so a file
 * this family may not have is a 404 rather than a 403 that confirms it exists.
 * See .claude/rules/private-uploads.md.
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
     *
     * `students` is the NARROW one: the file reaches only the guardians of the
     * children named in `group_resource_recipients`. It is a PHP constant and
     * the column is a plain string, for the reason `GroupMembership::ROLES` is
     * (.claude/rules/groups.md) — a fourth audience must never mean
     * `ALTER TABLE … MODIFY` on a live table.
     *
     * THE EMPTY SET IS THE EMPTY AUDIENCE. A `students` file with no recipient
     * rows left — every named child taken off the roster — reaches staff and
     * nobody else. It does NOT degrade to `families`; widening an audience as a
     * side effect of a roster edit is the one direction this feature must never
     * move in.
     */
    public const VISIBILITY_STAFF = 'staff';
    public const VISIBILITY_FAMILIES = 'families';
    public const VISIBILITY_STUDENTS = 'students';

    public const VISIBILITIES = [
        self::VISIBILITY_STAFF,
        self::VISIBILITY_FAMILIES,
        self::VISIBILITY_STUDENTS,
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

    /**
     * Files this class has chosen to share with EVERY family.
     *
     * Deliberately still means only `families`. A `students` file is shared with
     * families too, but with a NAMED set of them, and which set is a question
     * only `App\Support\GroupAudience` may answer — so this scope is no longer
     * the family realm's whole filter and must not be made to look like one.
     * @see GroupAudience::readableResourcesQuery()
     */
    public function scopeVisibleToFamilies($query)
    {
        return $query->where('visibility', self::VISIBILITY_FAMILIES);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * The students this file was addressed to — populated only while
     * `visibility` is `students`, and emptied by the controller the moment it
     * stops being.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(GroupResourceRecipient::class, 'group_resource_id');
    }

    public function isTargeted(): bool
    {
        return $this->visibility === self::VISIBILITY_STUDENTS;
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

    /**
     * The STAFF shape — the audience array plus WHO the file was addressed to.
     *
     * A separate method rather than two more keys on `toAudienceArray()`,
     * because that one is read by the FAMILY realm as well and anything added to
     * it is published to parents. The names of the other children a handout went
     * to are exactly what a parent must not be told, so the recipient block
     * never crosses into that serializer.
     *
     * `recipient_count` is served for every visibility (0 for a whole-class or
     * staff-only file) so the screen can say what a row is for without having to
     * special-case an absent key — the shape a reader is shown must not depend
     * on the value it is showing.
     */
    public function toStaffArray(): array
    {
        $ids = $this->relationLoaded('recipients')
            ? $this->recipients->pluck('group_membership_id')
            : $this->recipients()->pluck('group_membership_id');

        $ids = $ids->map(fn ($id): int => (int) $id)->values()->all();

        return $this->toAudienceArray() + [
            'recipient_membership_ids' => $this->isTargeted() ? $ids : [],
            'recipient_count' => $this->isTargeted() ? count($ids) : 0,
        ];
    }
}
