<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-masjid app-publishing configuration (see the masjid_app_publishing
 * migration).
 *
 * SECURITY: the four credential columns are `encrypted` casts (ciphertext at
 * rest) AND `$hidden`, so they never appear in toArray()/toJson() output.
 * Nothing in the codebase should ever echo them back over the API. Read paths
 * expose only the derived booleans `has_asc_key` / `has_play_service_account`
 * (see $appends) so a caller can tell whether BYO credentials are on file
 * without ever receiving them.
 */
class MasjidAppPublishing extends Model
{
    // The table name is explicit: the model name pluralizes to
    // "masjid_app_publishings", but the table is "masjid_app_publishing".
    protected $table = 'masjid_app_publishing';

    protected $fillable = [
        'masjid_id',
        'ios_account_mode',
        'android_account_mode',
        'web_account_mode',
        'asc_key_p8',
        'asc_key_id',
        'asc_issuer_id',
        'play_service_account_json',
    ];

    /**
     * Encrypt the BYO credentials at rest. The `encrypted` cast transparently
     * decrypts on read for server-side use, but combined with $hidden below the
     * plaintext never crosses the API boundary.
     */
    protected $casts = [
        'asc_key_p8' => 'encrypted',
        'asc_key_id' => 'encrypted',
        'asc_issuer_id' => 'encrypted',
        'play_service_account_json' => 'encrypted',
    ];

    /**
     * Never serialize the secrets. Any accidental ->toJson()/->toArray() (e.g. a
     * model returned straight from a controller) drops them entirely.
     */
    protected $hidden = [
        'asc_key_p8',
        'asc_key_id',
        'asc_issuer_id',
        'play_service_account_json',
    ];

    /**
     * Safe presence flags surfaced in place of the secrets, so a client can show
     * "credentials on file" without ever receiving them.
     */
    protected $appends = [
        'has_asc_key',
        'has_play_service_account',
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /** True when a BYO Apple App Store Connect key is stored. */
    public function getHasAscKeyAttribute(): bool
    {
        return filled($this->getRawOriginal('asc_key_p8'));
    }

    /** True when a BYO Google Play service-account JSON is stored. */
    public function getHasPlayServiceAccountAttribute(): bool
    {
        return filled($this->getRawOriginal('play_service_account_json'));
    }
}
