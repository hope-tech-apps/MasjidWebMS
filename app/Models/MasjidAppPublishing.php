<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-masjid app-publishing configuration (see the masjid_app_publishing
 * migration).
 *
 * SECURITY: the secret credential columns are `encrypted` casts (ciphertext at
 * rest) AND `$hidden`, so they never appear in toArray()/toJson() output.
 * Nothing in the codebase should ever echo them back over the API. Read paths
 * expose only the derived booleans `has_asc_key` / `has_play_service_account` /
 * `has_onesignal_key` (see $appends) so a caller can tell whether credentials
 * are on file without ever receiving them.
 *
 * The `onesignal_app_id` is the ONE non-secret credential here: it is embedded
 * in the mobile client, so it is neither encrypted nor hidden and IS readable.
 * Only `onesignal_rest_api_key` is a secret (encrypted + hidden).
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
        // Apple Developer Team ID for iOS signing. NOT a secret (embedded in
        // every distributed build) — plain, non-hidden. Feeds the provisioning
        // dispatch's `development_team`; null for managed-tier masjids, which
        // fall back to config('services.github.development_team').
        'development_team',
        'play_service_account_json',
        // Per-masjid OneSignal push config. The app id is a public identifier;
        // the REST key is a secret (encrypted + hidden, see below).
        'onesignal_app_id',
        'onesignal_rest_api_key',
    ];

    /**
     * Encrypt the secret credentials at rest. The `encrypted` cast transparently
     * decrypts on read for server-side use, but combined with $hidden below the
     * plaintext never crosses the API boundary. (onesignal_app_id is NOT a
     * secret — it is embedded in the mobile client — so it is left as-is.)
     */
    protected $casts = [
        'asc_key_p8' => 'encrypted',
        'asc_key_id' => 'encrypted',
        'asc_issuer_id' => 'encrypted',
        'play_service_account_json' => 'encrypted',
        'onesignal_rest_api_key' => 'encrypted',
    ];

    /**
     * Never serialize the secrets. Any accidental ->toJson()/->toArray() (e.g. a
     * model returned straight from a controller) drops them entirely.
     * onesignal_app_id is deliberately NOT hidden (safe to expose).
     */
    protected $hidden = [
        'asc_key_p8',
        'asc_key_id',
        'asc_issuer_id',
        'play_service_account_json',
        'onesignal_rest_api_key',
    ];

    /**
     * Safe presence flags surfaced in place of the secrets, so a client can show
     * "credentials on file" without ever receiving them.
     */
    protected $appends = [
        'has_asc_key',
        'has_play_service_account',
        'has_onesignal_key',
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

    /**
     * True when this masjid has its OWN OneSignal REST API key on file. Checks
     * the raw (still-encrypted) column so we never decrypt just to test presence.
     */
    public function getHasOnesignalKeyAttribute(): bool
    {
        return filled($this->getRawOriginal('onesignal_rest_api_key'));
    }

    /**
     * True only when BOTH the app id and REST key are present — i.e. this masjid
     * can send push through its OWN OneSignal app. Both are required: the app id
     * identifies the app, the REST key authorizes the send. Used by
     * OnesignalService to decide per-masjid vs shared-app routing.
     */
    public function hasOwnOnesignalApp(): bool
    {
        return filled($this->onesignal_app_id)
            && filled($this->getRawOriginal('onesignal_rest_api_key'));
    }
}
