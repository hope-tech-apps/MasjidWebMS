<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A request the assistant could not fulfil, escalated to Hope Tech Inc.
 *
 * This is the honest-failure path: rather than apologising and stopping — or
 * worse, claiming success — the assistant records what the admin actually
 * wanted and who asked, so it can become a real feature or a per-masjid
 * enablement.
 */
class AssistantFeatureRequest extends Model
{
    protected $fillable = [
        'masjid_id',
        'user_id',
        'category',
        'summary',
        'details',
        'status',
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
