<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One Manara Studio draft: a new client between "New client" and Step 3
 * (docs/manara-studio.md D7, docs/manara-studio-w1.md S2).
 *
 * Not tenant-scoped. A draft exists before its organisation does, so there is no
 * masjid to scope it to; the org it becomes is `provisioned_masjid_id`, and only
 * SuperAdmins reach it (the `super` group in routes/admin.php). It is on
 * TenantScopingCoverageTest's DECLINED list for that reason.
 *
 * `answers` is sectioned. A PATCH replaces whole sections and leaves the rest, so
 * each Studio step owns its own section and two steps autosaving cannot clobber
 * each other; `lock_version` catches two TABS saving the same section.
 *
 * The logo is on a private disk (config('studio.logo')), in a directory of the
 * draft's own, and its bytes go with the row: the `deleting` hook removes that
 * directory, so every delete must go through the model — the discard endpoint
 * and `studio:purge-drafts` both do.
 */
class StudioDraft extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PROVISIONED = 'provisioned';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PROVISIONED];

    public const STEPS = ['foundation', 'features', 'layout', 'generate'];

    /** Every section `answers` may hold; a PATCH naming any other is refused. */
    public const ANSWER_SECTIONS = ['identity', 'prayer', 'brand', 'content', 'features', 'layout', 'platforms', 'domain'];

    /**
     * BYO store credentials. They are typed at Step 3 and sent only in the
     * provision body (docs/manara-studio-w1.md R7): a secret that is never
     * stored needs no scrub, backup or rotation story. A PATCH carrying one at
     * any depth is refused, never silently dropped.
     */
    public const SECRET_KEYS = ['asc_key_p8', 'asc_key_id', 'asc_issuer_id', 'play_service_account_json'];

    /** Which platform each secret belongs to, for toProvisionPayload(). */
    private const SECRET_PLATFORM = [
        'asc_key_p8' => 'ios',
        'asc_key_id' => 'ios',
        'asc_issuer_id' => 'ios',
        'play_service_account_json' => 'android',
    ];

    /**
     * How each section's keys land in ProvisionMasjidRequest today. Keys not
     * named here stay in the draft: `vibe` never leaves it (R12), `extracted`
     * and `ink_overrides` are Studio's own, and `slug`, `description`,
     * `iqama_given`, `features`, `layout` and `domain` become request keys only
     * when S8 teaches the request to accept them.
     */
    private const PROVISION_KEYS = [
        'identity' => [
            'org_type', 'name', 'email', 'phone', 'address', 'country_id', 'city_id',
            'latitude', 'longitude', 'timezone', 'user_id', 'admin',
            'donation_link', 'donation_title', 'donation_message',
            'facebook_url', 'youtube_url', 'instagram_url', 'whatsapp_url', 'whatsapp_number',
        ],
        'prayer' => ['method', 'madhab', 'high_latitude_rule', 'iqama_type', 'iqama', 'jumaa_iqama'],
        'content' => ['about', 'mission', 'vision'],
        'platforms' => ['platforms'],
    ];

    private const BRAND_COLOURS = ['primary_color', 'secondary_color', 'accent_color', 'background_color'];

    protected $fillable = [
        'status',
        'current_step',
        'schema_version',
        'lock_version',
        'name',
        'org_type',
        'answers',
        'logo_disk',
        'logo_path',
        'logo_original_name',
        'logo_mime_type',
        'logo_size_bytes',
        'logo_width',
        'logo_height',
        'logo_sha256',
        'provisioned_masjid_id',
        'provisioned_at',
        'created_by',
        'updated_by',
    ];

    /** Where the bytes are is the server's business; the SPA gets the endpoint. */
    protected $hidden = ['logo_disk', 'logo_path'];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'lock_version' => 'integer',
            'logo_size_bytes' => 'integer',
            'logo_width' => 'integer',
            'logo_height' => 'integer',
            'provisioned_masjid_id' => 'integer',
            'provisioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // `deleting`, as .claude/rules/private-uploads.md has it, so the bytes go
        // BEFORE the row. If removing the row then fails, the row survives
        // naming a logo that is gone (hasLogo() reads only the columns, so the
        // payload still describes it while GET .../logo answers 404) until the
        // next discard or purge finishes the job. The other order could leave
        // bytes with no row to find them by, which is what the purge exists to
        // prevent.
        static::deleting(function (StudioDraft $draft) {
            $draft->deleteLogoBytes();
        });
    }

    /**
     * The sections as an array. A NULL column reads as [] so every caller can
     * index into it without a null check; an empty array is stored as NULL.
     */
    protected function answers(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? [] : (json_decode($value, true) ?: []),
            set: fn (?array $value) => ($value === null || $value === [])
                ? null
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function provisionedMasjid()
    {
        return $this->belongsTo(Masjid::class, 'provisioned_masjid_id');
    }

    public function isProvisioned(): bool
    {
        return $this->status === self::STATUS_PROVISIONED;
    }

    /** @return array<string, mixed> one section, [] when it was never saved */
    public function section(string $name): array
    {
        $section = $this->answers[$name] ?? null;

        return is_array($section) ? $section : [];
    }

    /**
     * The four brand colours, but only once every one of them has been chosen.
     *
     * A new draft has no colours (R25), and DesignTokens fills a missing colour
     * with Burlington's green; reporting on a half-chosen palette would therefore
     * grade a live client's brand, not this one's.
     *
     * @return array<string, string>|null
     */
    public function brandColours(): ?array
    {
        $brand = $this->section('brand');
        $colours = [];

        foreach (self::BRAND_COLOURS as $key) {
            $value = $brand[$key] ?? null;

            if (! is_string($value) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                return null;
            }

            $colours[$key] = $value;
        }

        return $colours;
    }

    /**
     * Whether the row names a logo. The columns only, not the disk: see the
     * `deleting` hook for the one way a row can name bytes that are gone.
     */
    public function hasLogo(): bool
    {
        return $this->logo_path !== null && $this->logo_disk !== null;
    }

    /** The disk this draft's logo was written to, which may predate a config change. */
    public function logoStorage()
    {
        return Storage::disk($this->logo_disk ?? config('studio.logo.disk', 'local'));
    }

    public function logoExists(): bool
    {
        return $this->hasLogo() && $this->logoStorage()->exists($this->logo_path);
    }

    /** Where every logo this draft is given is written, on config('studio.logo.disk'). */
    public function logoDirectory(): string
    {
        return config('studio.logo.directory', 'studio-drafts') . '/' . $this->id;
    }

    /**
     * Remove the stored bytes, leaving the row's logo columns as they are.
     *
     * The whole directory, not only the file the row names: an upload whose old
     * file failed to delete, or two uploads that overlapped, leave files nothing
     * points at, and this is the last chance anything has to find them.
     */
    public function deleteLogoBytes(): void
    {
        if ($this->hasLogo()) {
            $this->logoStorage()->delete($this->logo_path);
        }

        Storage::disk((string) config('studio.logo.disk', 'local'))->deleteDirectory($this->logoDirectory());
    }

    /** The logo columns cleared, for update(); the bytes are the caller's to delete. */
    public static function withoutLogo(): array
    {
        return [
            'logo_disk' => null,
            'logo_path' => null,
            'logo_original_name' => null,
            'logo_mime_type' => null,
            'logo_size_bytes' => null,
            'logo_width' => null,
            'logo_height' => null,
            'logo_sha256' => null,
        ];
    }

    /**
     * The draft flattened into ProvisionMasjidRequest's keys, so Step 3 can run
     * the very rules a direct POST to /onboarding/provision runs.
     *
     * A key the draft never set is omitted rather than sent as null, so the
     * request's own `required` rules name what is missing. Secrets are merged in
     * only for a platform whose account_mode is `byo`: a managed platform's
     * credentials are Hope Tech's, and anything typed for it is discarded.
     *
     * @param  array{ios?: array<string, string>, android?: array<string, string>}  $secrets
     * @return array<string, mixed>
     */
    public function toProvisionPayload(array $secrets = []): array
    {
        $payload = [];

        foreach (self::PROVISION_KEYS as $section => $keys) {
            $values = $this->section($section);

            foreach ($keys as $key) {
                if (array_key_exists($key, $values)) {
                    $payload[$key] = $values[$key];
                }
            }
        }

        $brand = array_intersect_key($this->section('brand'), array_flip(self::BRAND_COLOURS));
        if ($brand !== []) {
            $payload['brand'] = $brand;
        }

        $apps = [];
        foreach ((array) ($this->section('platforms')['apps'] ?? []) as $platform => $app) {
            if (is_array($app) && array_key_exists('account_mode', $app)) {
                $apps[$platform] = ['account_mode' => $app['account_mode']];
            }
        }

        foreach (self::SECRET_PLATFORM as $key => $platform) {
            $value = $secrets[$platform][$key] ?? null;

            if ($value !== null && ($apps[$platform]['account_mode'] ?? null) === 'byo') {
                $apps[$platform][$key] = $value;
            }
        }

        if ($apps !== []) {
            $payload['apps'] = $apps;
        }

        return $payload;
    }
}
