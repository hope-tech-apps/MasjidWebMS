<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One saved flyer. Tenant-scoped by masjid_id (BelongsToMasjid).
 *
 * `content` is the filled slot values keyed by slot name; the design they fill lives
 * on the FlyerTemplate, so a template correction does not rewrite flyers already made
 * from it. `palette` is the colours as RESOLVED when the flyer was saved, snapshotted
 * out of the masjid's theme_settings — a rebrand must not restyle a flyer the board
 * has already approved and printed.
 *
 * Rendering and background removal both happen out-of-band, which is why this row
 * carries two independent progress markers: `status` for the flyer itself, and
 * `cutout_status`/`cutout_error` for the photo's background removal.
 */
class Flyer extends Model
{
    use HasFactory, BelongsToMasjid;

    public const STATUSES = ['draft', 'rendered'];

    /**
     * Cutout lifecycle. 'none' is the resting state for a flyer with no photo, or one
     * whose photo is used as-is — it is not a synonym for "not started yet", which is
     * what 'queued' means.
     */
    public const CUTOUT_STATUSES = ['none', 'queued', 'processing', 'done', 'failed'];

    protected $fillable = [
        'uuid',
        'masjid_id',
        'flyer_template_id',
        'title',
        'content',
        'palette',
        'status',
        'rendered_path',
        'source_image_path',
        'cutout_path',
        'cutout_status',
        'cutout_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'palette' => 'array',
        ];
    }

    /**
     * An opaque public UUID, assigned if the caller didn't supply one. Kept separate
     * from the auto-increment id so a rendered flyer can be shared by link without
     * leaking how many flyers a masjid has made. Mirrors Donation.
     */
    protected static function booted(): void
    {
        static::creating(function (Flyer $flyer): void {
            if (empty($flyer->uuid)) {
                $flyer->uuid = (string) Str::uuid();
            }
        });
    }

    public function template()
    {
        return $this->belongsTo(FlyerTemplate::class, 'flyer_template_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -------------------------------------------------------------------- cutout

    /**
     * The image the renderer should composite: the cutout once it exists, otherwise
     * whatever was uploaded. Callers must not branch on cutout_status themselves —
     * a failed or still-queued cutout has to fall back to the original rather than
     * render a hole where the photo goes.
     */
    public function compositeImagePath(): ?string
    {
        if ($this->cutout_status === 'done' && $this->cutout_path) {
            return $this->cutout_path;
        }

        return $this->source_image_path;
    }

    /** Still in flight — the Studio polls while this is true. */
    public function isCutoutPending(): bool
    {
        return in_array($this->cutout_status, ['queued', 'processing'], true);
    }

    public function isRendered(): bool
    {
        return $this->status === 'rendered' && (bool) $this->rendered_path;
    }
}
