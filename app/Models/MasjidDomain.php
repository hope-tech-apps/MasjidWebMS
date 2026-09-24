<?php

namespace App\Models;

use App\Support\HostName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;

/**
 * One website host and the organisation it belongs to (Manara Studio W1, S3).
 *
 * The table replaces the renderer's static NUXT_TENANT_HOSTS map, and it is read
 * by two consumers that must NOT share a rule (R3):
 *
 *  - served() feeds the public by-host lookup. It includes pending hosts,
 *    because the probe that confirms a new host goes through the renderer, and
 *    the renderer can only answer for a host the lookup resolves.
 *  - corsAdmitted() feeds CORS and the payment-return allowlist (from S9). An
 *    origin is trusted only once we have seen our own site answer on it
 *    (`serving_confirmed_at`, R24), so a mistyped host, or a custom host that
 *    belongs to someone else, is never admitted and Stripe never returns a
 *    payer to it.
 *
 * `reserved` is neither: it holds a host for an organisation (an imported live
 * host the probe could not confirm, R4) so Studio can never give it to another
 * one, and nothing ever advances it.
 *
 * Not tenant-scoped (TenantScopingCoverageTest::DECLINED): the unauthenticated
 * lookup reads it with no tenant, and only SuperAdmin Studio routes write it.
 */
class MasjidDomain extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_NAMESERVERS = 'awaiting_nameservers';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_MANUAL = 'manual';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RESERVED = 'reserved';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_NAMESERVERS,
        self::STATUS_PROVISIONING,
        self::STATUS_ACTIVE,
        self::STATUS_MANUAL,
        self::STATUS_FAILED,
        self::STATUS_RESERVED,
    ];

    /** What the by-host lookup answers for. Not `failed`, never `reserved`. */
    public const SERVED = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_NAMESERVERS,
        self::STATUS_PROVISIONING,
        self::STATUS_ACTIVE,
        self::STATUS_MANUAL,
    ];

    /** Still moving: the attach job (S7) re-checks these. */
    public const NON_TERMINAL = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_NAMESERVERS,
        self::STATUS_PROVISIONING,
    ];

    /** The statuses that may be CORS-admitted, once serving is confirmed. */
    public const TRUSTED = [
        self::STATUS_ACTIVE,
        self::STATUS_MANUAL,
    ];

    public const KIND_MANAGED_SUBDOMAIN = 'managed_subdomain';
    public const KIND_CUSTOM = 'custom';
    public const KINDS = [self::KIND_MANAGED_SUBDOMAIN, self::KIND_CUSTOM];

    public const SOURCE_STUDIO = 'studio';
    public const SOURCE_IMPORTED = 'imported';

    public const VERIFIED_BY_CLOUDFLARE = 'cloudflare';
    public const VERIFIED_BY_PROBE = 'probe';

    /**
     * ONE fixed key for the CORS origin list. Production's cache store is the
     * database, which deletes an expired row only when that key is read again,
     * so a key a caller could vary (by host, by origin) would grow the table
     * without bound; see App\Http\Middleware\TrustedHosts::claimLogLine for the
     * time that happened.
     */
    public const CORS_ORIGINS_CACHE_KEY = 'masjid-domains:cors-origins:v1';

    public const CORS_ORIGINS_TTL = 300;

    /** The column defaults, so an unsaved row already reads as the database will store it. */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'source' => self::SOURCE_STUDIO,
        'cf_zone_created' => false,
    ];

    protected $fillable = [
        'masjid_id',
        'host',
        'kind',
        'zone_apex',
        'status',
        'waiting_on',
        'source',
        'cf_zone_id',
        'cf_dns_record_id',
        'cf_pages_domain_id',
        'cf_zone_created',
        'nameservers',
        'last_error',
        'last_checked_at',
        'next_check_at',
        'verified_at',
        'serving_confirmed_at',
        'verified_by',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'cf_zone_created' => 'boolean',
            'nameservers' => 'array',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'verified_at' => 'datetime',
            'serving_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MasjidDomain $domain) {
            if (! in_array($domain->status, self::STATUSES, true)) {
                throw new LogicException("Unknown masjid_domains status [{$domain->status}].");
            }

            if (! in_array($domain->kind, self::KINDS, true)) {
                throw new LogicException("Unknown masjid_domains kind [{$domain->kind}].");
            }

            // `active` means Cloudflare told us the domain and its certificate
            // are live. A probe proves only that our site answered, so a probed
            // host is `manual`; letting anything else write `active` would make
            // the status claim more than anyone checked.
            if ($domain->status === self::STATUS_ACTIVE
                && ($domain->verified_by !== self::VERIFIED_BY_CLOUDFLARE || $domain->verified_at === null)) {
                throw new LogicException(
                    "masjid_domains row for {$domain->host} cannot be active without Cloudflare verification."
                );
            }
        });

        static::saved(fn () => Cache::forget(self::CORS_ORIGINS_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CORS_ORIGINS_CACHE_KEY));
    }

    /**
     * Stored normalised, always. A value HostName cannot normalise is a bug in
     * the caller (every write path validates first), so it throws rather than
     * storing something the lookup could never match.
     */
    protected function host(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizedOrThrow($value, 'host'));
    }

    protected function zoneApex(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizedOrThrow($value, 'zone_apex'));
    }

    private static function normalizedOrThrow(?string $value, string $column): string
    {
        $normalized = HostName::normalize($value);

        if ($normalized === null) {
            throw new InvalidArgumentException("masjid_domains.{$column} is not a host name: " . var_export($value, true));
        }

        return $normalized;
    }

    public function masjid(): BelongsTo
    {
        return $this->belongsTo(Masjid::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Rows the public by-host lookup answers for: a served status, and an
     * organisation that has not been offboarded (Masjid soft-deletes, and
     * whereHas applies its SoftDeletes scope). Read by the lookup ONLY.
     */
    public function scopeServed(Builder $query): Builder
    {
        return $query->whereIn('status', self::SERVED)->whereHas('masjid');
    }

    /**
     * Rows whose origin CORS and the payment-return allowlist may trust: served,
     * active or manual, and seen serving our own site (R3, R24). Read by CORS
     * and payment returns ONLY; nothing reads it until S9.
     */
    public function scopeCorsAdmitted(Builder $query): Builder
    {
        return $query->served()
            ->whereIn('status', self::TRUSTED)
            ->whereNotNull('serving_confirmed_at');
    }

    /**
     * `https://<host>` for every CORS-admitted row.
     *
     * Cached for five minutes under one fixed key and forgotten whenever a row
     * is saved or deleted. An organisation being trashed does not touch this
     * table, so its hosts leave the list when the entry expires, at most five
     * minutes later.
     *
     * @return list<string>
     */
    public static function corsOrigins(): array
    {
        return Cache::remember(self::CORS_ORIGINS_CACHE_KEY, self::CORS_ORIGINS_TTL, function () {
            return self::query()
                ->corsAdmitted()
                ->orderBy('host')
                ->pluck('host')
                ->map(fn (string $host) => 'https://' . $host)
                ->values()
                ->all();
        });
    }

    /**
     * The address to open the live site at, only once a server-side probe has
     * seen our site answer on this host for this organisation (R24). An active
     * certificate alone does not prove it: the renderer's lookup cache can
     * still be serving the host's previous answer.
     */
    public function liveUrl(): ?string
    {
        return $this->serving_confirmed_at !== null ? 'https://' . $this->host : null;
    }

    /**
     * What an operator does by hand for this row, in order, built here so the
     * SPA never restates Cloudflare's instructions and cannot drift from them.
     *
     * @return list<string>
     */
    public function manualSteps(): array
    {
        $project = (string) config('cloudflare.pages_project');
        $target = (string) config('cloudflare.pages_target');

        if ($this->status === self::STATUS_RESERVED) {
            return [
                "{$this->host} is held for this organisation and is not served. Nothing needs doing unless it should go live: then remove the reservation and add it again.",
            ];
        }

        if ($this->status === self::STATUS_FAILED) {
            return [
                "Setting up {$this->host} failed" . ($this->last_error ? ": {$this->last_error}" : '.'),
                'Remove this domain and add it again once the cause is fixed.',
            ];
        }

        if ($this->serving_confirmed_at !== null && in_array($this->status, self::TRUSTED, true)) {
            return [];
        }

        $confirm = "Press Check now. The site is confirmed once https://{$this->host} answers for this organisation.";

        if ($this->kind === self::KIND_MANAGED_SUBDOMAIN) {
            $zone = (string) config('cloudflare.managed_zone');
            $name = substr($this->host, 0, -strlen('.' . $zone));

            return [
                "In Cloudflare, open the {$zone} zone, then DNS, and add a CNAME record named {$name} pointing to {$target}, proxied.",
                "In Cloudflare, open Workers & Pages, then the {$project} project, then Custom domains, and add {$this->host}.",
                $confirm,
            ];
        }

        $steps = [];

        if ($this->waiting_on === 'nameservers' && ! empty($this->nameservers)) {
            $steps[] = "At the registrar for {$this->zone_apex}, replace its nameservers with: "
                . implode(', ', (array) $this->nameservers) . '. This can take up to a day to take effect.';
        }

        $steps[] = $this->host === $this->zone_apex
            ? "In the DNS for {$this->zone_apex}, point the apex at {$target} with a CNAME (Cloudflare flattens it) or the provider's ALIAS/ANAME record."
            : "In the DNS for {$this->zone_apex}, add a CNAME record for {$this->host} pointing to {$target}.";
        $steps[] = "In Cloudflare, open Workers & Pages, then the {$project} project, then Custom domains, and add {$this->host}.";
        $steps[] = $confirm;

        return $steps;
    }
}
