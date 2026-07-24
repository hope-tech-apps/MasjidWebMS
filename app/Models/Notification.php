<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Notification extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'masjid_id',
        'title',
        'message',
        'onesignal_message_id'
    ];

    public function masjid() {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * Single optional image attached to a notification. Mirrors the
     * Announcement "announcements" collection pattern.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('notifications')->singleFile();
    }

    /** Absolute URL of the attached image, or null when none is set. */
    public function getImageUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('notifications') ?: null;
    }
}
