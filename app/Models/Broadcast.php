<?php

namespace App\Models;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastChannel;
use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Broadcast — one composed message plus the record that it was sent (T-008).
 *
 * A broadcast is NOT a fifth content type competing with announcements, splash
 * messages, notifications and the signage board. It is the compose action, and
 * the audit trail of what it produced in each of those existing places. Every
 * channel driver writes through the model that channel already owned:
 * `Announcement` for the feed, `Notification` + SendMasjidNotificationJob for
 * push. Signage is the exception, and only because it had nowhere to write —
 * the `tv-config`/signage endpoint did not exist before this slice
 * (docs/recon-2026-08-11.md), so the board reads live broadcasts directly.
 *
 * ## Tenant scoping
 *
 * `BelongsToMasjid`, per .claude/rules/tenant-scoping.md: this is a NEW model,
 * so it gets the global scope and the server-derived `creating` hook rather than
 * the hand-filtering the pre-CRM models (Announcement, Notification) still use.
 * Cross-tenant behaviour is pinned by BroadcastTenantIsolationTest.
 *
 * The PUBLIC signage endpoint runs unbound (routes/api.php never applies the
 * tenant middleware), so it filters by the masjid_id in its URL explicitly —
 * the same contract every other mobile controller honours.
 *
 * ## The image lives here, once
 *
 * An admin attaches one image and up to four channels want it: the announcement
 * needs a Spatie media row of its own, push needs a public URL for big_picture,
 * signage and email need a URL. Storing it on the broadcast in the `broadcasts`
 * collection means it is uploaded once and each driver takes what it needs —
 * the announcement driver copies it into the `announcements` collection so that
 * row stays a completely ordinary announcement, indistinguishable from one typed
 * on the announcements screen.
 */
class Broadcast extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, BelongsToMasjid;

    /** Media collection holding the single optional composer image. */
    public const MEDIA_COLLECTION = 'broadcasts';

    /** Composed, nothing attempted yet. */
    public const STATUS_PENDING = 'pending';

    /** Accepted for a future send; a delayed queue job holds it. */
    public const STATUS_SCHEDULED = 'scheduled';

    /** Every selected channel succeeded (or had nothing to deliver to). */
    public const STATUS_SENT = 'sent';

    /** Some channels went out and some did not — see the delivery rows. */
    public const STATUS_PARTIAL = 'partial';

    /** Every selected channel failed. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'masjid_id',
        'created_by_user_id',
        'title',
        'body',
        'link',
        'starts_on',
        'ends_on',
        'audience',
        'audience_contact_ids',
        'scheduled_at',
        'dispatched_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'audience_contact_ids' => 'array',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(BroadcastDelivery::class);
    }

    /** The admin who composed it; null once that account is deleted. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function audienceType(): BroadcastAudience
    {
        return BroadcastAudience::tryFrom((string) $this->audience) ?? BroadcastAudience::EVERYONE;
    }

    /** Public URL of the composer image, or null when none was attached. */
    public function imageUrl(): ?string
    {
        return $this->getFirstMediaUrl(self::MEDIA_COLLECTION) ?: null;
    }

    /** Absolute filesystem path of the composer image, for channels that copy it. */
    public function imagePath(): ?string
    {
        $media = $this->getFirstMedia(self::MEDIA_COLLECTION);

        return $media ? $media->getPath() : null;
    }

    /**
     * Scope: broadcasts the signage board should be showing right now.
     *
     * Three conditions, all of them necessary: the signage channel was actually
     * selected AND its delivery succeeded (a broadcast whose signage delivery
     * failed must not appear), and the display window is open. A null window is
     * treated as open-ended in that direction — a notice with no end date stays
     * on the board, which is what "until further notice" means.
     */
    public function scopeLiveOnSignage(Builder $query, ?Carbon $at = null): Builder
    {
        $at = $at ?? Carbon::now();
        $today = $at->toDateString();

        return $query
            ->whereHas('deliveries', function (Builder $delivery) {
                $delivery
                    ->where('channel', BroadcastChannel::SIGNAGE->value)
                    ->where('status', BroadcastDelivery::STATUS_SENT);
            })
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }
}
