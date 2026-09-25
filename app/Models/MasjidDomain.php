<?php

namespace App\Models;

use App\Services\Domains\DomainAttacher;
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

    /** Still moving: the attacher (S7) re-checks these. */
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

    /** What a row is waiting on (`waiting_on`), set by App\Services\Domains\DomainAttacher. */
    public const WAITING_ON = ['token', 'token_scope', 'nameservers', 'certificate', 'capacity'];

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
        'stage_started_at',
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
            'stage_started_at' => 'datetime',
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

            // `reserved` holds a live host for an organisation without trusting
            // it (R4), and nothing advances it. Every reserved row is imported,
            // so Studio cannot delete it either (R28) and its host cannot be
            // added again: changing one is a platform-level act, not a Studio
            // one (manualSteps() says so).
            if ($domain->exists
                && $domain->getOriginal('status') === self::STATUS_RESERVED
                && $domain->isDirty('status')) {
                throw new LogicException(
                    "masjid_domains row for {$domain->host} is reserved and cannot become {$domain->status}."
                );
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
     * and payment returns ONLY (S9: App\Http\Middleware\HandleCorsWithDomains
     * through corsOrigins(), and App\Support\FormPaymentReturn::allowedOrigin).
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
     * Without CLOUDFLARE_STUDIO_TOKEN these are the whole job: the DNS record
     * and the Pages custom domain made in the dashboard, then "Check now",
     * whose probe is the only thing that can mark the host live. With the
     * token they shrink to what only a person can do (the registrar's
     * nameservers, the token's scopes, the project's domain limit), because the
     * attacher does the rest and saying otherwise would send an operator to
     * make records Studio is about to make.
     *
     * A custom apex has no CNAME or ALIAS route without the token: Cloudflare
     * Pages serves an apex only from a zone on the account that holds the
     * project, reached by moving the domain's nameservers, and makes the DNS
     * record itself once the domain is added in Pages
     * (https://developers.cloudflare.com/pages/configuration/custom-domains/,
     * read 2026-09-24, page last updated 2026-04-21). A subdomain can stay at
     * any DNS provider with a CNAME.
     *
     * @return list<string>
     */
    public function manualSteps(): array
    {
        $project = (string) config('cloudflare.pages_project');
        $target = (string) config('cloudflare.pages_target');

        if ($this->status === self::STATUS_RESERVED) {
            return [
                "{$this->host} is held for this organisation, which is already reached at it through the live host map, so no other organisation can take it. Studio does not check or change it and cannot release it.",
                'Nothing needs doing here. If it should go live through Studio or be let go, that is a platform-level change for the platform owner, not a Studio action in W1.',
            ];
        }

        // Check now is the way forward for every failed row (it starts the
        // row again from pending). Removing it is offered only when Studio
        // would allow it: a row Cloudflare holds records for is refused a
        // DELETE (409) and its host a new POST (422), so telling the operator
        // to remove and re-add it would be a dead end.
        if ($this->status === self::STATUS_FAILED) {
            return [
                "Setting up {$this->host} failed" . ($this->last_error ? ": {$this->last_error}" : '.'),
                $this->deletableThroughStudio()
                    ? "Once the cause is fixed, press Check now: it starts setting up {$this->host} again. Or remove this domain if it is not wanted."
                    : "Once the cause is fixed, press Check now: it starts setting up {$this->host} again. It cannot be removed through Studio, because Cloudflare holds records Studio made or found for it.",
            ];
        }

        if ($this->serving_confirmed_at !== null && in_array($this->status, self::TRUSTED, true)) {
            return [];
        }

        $confirm = "Press Check now. The site is confirmed once https://{$this->host} answers for this organisation.";
        $nameservers = "At the registrar for {$this->zone_apex}, replace its nameservers with: "
            . implode(', ', (array) $this->nameservers) . '. This can take up to a day to take effect.';

        if (filled(config('cloudflare.studio_token'))) {
            if ($this->waiting_on === 'token_scope') {
                return [
                    'Cloudflare refused CLOUDFLARE_STUDIO_TOKEN for this step. Give the token Account › Cloudflare Pages: Edit, Zone › Zone: Edit and Zone › DNS: Edit, on all zones in the account. Studio tries again every half hour.',
                ];
            }

            if ($this->waiting_on === 'capacity') {
                return [
                    "The {$project} Pages project has reached its limit of " . config('cloudflare.pages_domain_ceiling')
                    . ' custom domains. Raise the limit on the Cloudflare plan, or remove a custom domain nobody uses. Studio tries again every hour.',
                ];
            }

            if ($this->waiting_on === 'nameservers' && ! empty($this->nameservers)) {
                return [$nameservers, 'Studio checks the zone every half hour and attaches the site itself once the nameservers are live.'];
            }

            if ($this->status === self::STATUS_ACTIVE) {
                return ["Cloudflare reports {$this->host} as attached. {$confirm}"];
            }

            if (in_array($this->status, self::NON_TERMINAL, true)) {
                return [
                    "Nothing to do by hand: Studio is attaching {$this->host} through Cloudflare and checks it every five minutes.",
                    $confirm,
                ];
            }
        }

        if ($this->kind === self::KIND_MANAGED_SUBDOMAIN) {
            $zone = (string) config('cloudflare.managed_zone');
            $name = substr($this->host, 0, -strlen('.' . $zone));

            $steps = [
                "In Cloudflare, open the {$zone} zone, then DNS, and add a CNAME record named {$name} pointing to {$target}, proxied.",
                "In Cloudflare, open Workers & Pages, then the {$project} project, then Custom domains, and add {$this->host}.",
                $confirm,
            ];
        } else {
            $steps = [];

            if ($this->waiting_on === 'nameservers' && ! empty($this->nameservers)) {
                $steps[] = $nameservers;
            }

            if ($this->host !== $this->zone_apex) {
                $steps[] = "In the DNS for {$this->zone_apex}, add a CNAME record for {$this->host} pointing to {$target}.";
                $steps[] = "In Cloudflare, open Workers & Pages, then the {$project} project, then Custom domains, and add {$this->host}.";
            } else {
                if (empty($steps)) {
                    $steps[] = "Pages serves an apex only from a zone on the Cloudflare account that holds the {$project} project: in Cloudflare, add {$this->zone_apex} as a domain, then at its registrar replace the nameservers with the two Cloudflare gives. This can take up to a day to take effect.";
                    $steps[] = "Changing nameservers moves all of {$this->zone_apex}'s DNS to Cloudflare, email (MX) included: check the records Cloudflare imported before the registrar switches. Cloudflare deletes a zone left pending for "
                        . DomainAttacher::ZONE_PENDING_LIMIT_DAYS . ' days.';
                }

                $steps[] = "Once the zone is active, in Cloudflare, open Workers & Pages, then the {$project} project, then Custom domains, and add {$this->host}. Cloudflare makes its DNS record itself.";
            }

            $steps[] = $confirm;
        }

        if (blank(config('cloudflare.studio_token')) && $this->source !== self::SOURCE_IMPORTED) {
            $steps[] = 'Or, instead of the steps above: once CLOUDFLARE_STUDIO_TOKEN is on the server, Studio makes the DNS record and the custom domain itself within five minutes.';
        }

        return $steps;
    }

    /**
     * Whether Studio may delete this row (DELETE .../domains/{id}): only when
     * nothing about it lives anywhere but this table. A row that carries a
     * Cloudflare id, whose zone Studio created, or that was imported from the
     * live host map is refused (R28): the first two would leave records in
     * Cloudflare nobody tracks any more, and an imported row is how a live
     * organisation is looked up today (and, from S9, admitted by CORS).
     */
    public function deletableThroughStudio(): bool
    {
        return $this->source !== self::SOURCE_IMPORTED
            && ! $this->cf_zone_created
            && $this->cf_zone_id === null
            && $this->cf_dns_record_id === null
            && $this->cf_pages_domain_id === null;
    }

    /**
     * What an operator does by hand before this row can go, for the 409 that
     * refuses its deletion. Studio never removes anything from Cloudflare
     * (CloudflareService has no delete), so this is the only way it happens.
     *
     * @return list<string>
     */
    public function removalSteps(): array
    {
        if ($this->source === self::SOURCE_IMPORTED) {
            return [
                "{$this->host} was imported from the live host map: it is how this organisation is reached today, so Studio does not remove it.",
                'If it really must go, the platform owner removes it with the renderer map and the CORS list in mind; it is not a Studio action in W1.',
            ];
        }

        $project = (string) config('cloudflare.pages_project');
        $steps = [];

        if ($this->cf_pages_domain_id !== null) {
            $steps[] = "In Cloudflare, open Workers & Pages, then the {$project} project, then Custom domains, and remove {$this->host}.";
        }

        if ($this->cf_dns_record_id !== null) {
            $steps[] = "In Cloudflare, open the {$this->zone_apex} zone, then DNS, and delete the CNAME record for {$this->host}.";
        }

        if ($this->cf_zone_created) {
            $steps[] = "Studio added the {$this->zone_apex} zone to Cloudflare. It holds all of that domain's DNS, email included: remove it only after its owner agrees.";
        } elseif ($this->cf_zone_id !== null) {
            $steps[] = "Studio found the {$this->zone_apex} zone on Cloudflare and did not create it; leave the zone itself alone.";
        }

        $steps[] = 'Then ask the platform owner to remove this row. Detaching a host is not a Studio action in W1.';

        return $steps;
    }

    /**
     * The row as the SuperAdmin domain screens read it. An explicit list, so a
     * column added later is not published by accident, and `live_url` and
     * `manual_steps` are computed here rather than in the SPA.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'masjid_id' => (int) $this->masjid_id,
            'host' => $this->host,
            'kind' => $this->kind,
            'zone_apex' => $this->zone_apex,
            'status' => $this->status,
            'waiting_on' => $this->waiting_on,
            'source' => $this->source,
            'nameservers' => $this->nameservers,
            'last_error' => $this->last_error,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'next_check_at' => $this->next_check_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->verified_by,
            'serving_confirmed_at' => $this->serving_confirmed_at?->toIso8601String(),
            'live_url' => $this->liveUrl(),
            'manual_steps' => $this->manualSteps(),
            'deletable' => $this->deletableThroughStudio(),
        ];
    }
}
