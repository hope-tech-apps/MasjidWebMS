<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Per-masjid splash announcement / in-app message.
 *
 * Image is stored via Spatie MediaLibrary in the 'splash_announcements'
 * collection — same pattern used by Announcement. The OneSignal IAM mirror
 * is created by App\Services\OnesignalInAppMessageService on every save.
 */
class SplashAnnouncement extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'masjid_id',
        'title',
        'body',
        'cta_label',
        'cta_url',
        'starts_at',
        'ends_at',
        'priority',
        'is_active',
        'onesignal_iam_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /** Image attached to this splash. Mirrors Announcement::image(). */
    public function image()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'splash_announcements')
            ->orderByDesc('created_at')
            ->latest();
    }

    /**
     * Scope: "is this splash live right now?"
     * Used by the public endpoint to pick the row to display.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = Carbon::now();
        return $query
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now);
    }
}
