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
 * one, and nothing ever advances it. Nor is a `redirect` row (W2 S5), which
 * Cloudflare answers with a 301 before the renderer or this API ever sees it.
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

    /**
     * Studio is removing what it created for the host in Cloudflare (W2 S3).
     * Not served and not trusted from the moment it is set; the row itself
     * goes once every object Studio made is gone, and `domains:reconcile`
     * retries a removal that stopped part-way.
     */
    public const STATUS_DETACHING = 'detaching';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_NAMESERVERS,
        self::STATUS_PROVISIONING,
        self::STATUS_ACTIVE,
        self::STATUS_MANUAL,
        self::STATUS_FAILED,
        self::STATUS_RESERVED,
        self::STATUS_DETACHING,
    ];

    /** What the by-host lookup answers for. Not `failed` or `detaching`, never `reserved`. */
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

    /**
     * What a host does (W2 S5). A `serving` host is a Pages custom domain the
     * renderer answers on. A `redirect` host answers only with Cloudflare's 301
     * to the serving host it names (`redirect_to_id`), uses no Pages slot, and
     * is in neither served() nor corsAdmitted().
     */
    public const ROLE_SERVING = 'serving';
    public const ROLE_REDIRECT = 'redirect';
    public const ROLES = [self::ROLE_SERVING, self::ROLE_REDIRECT];

    /** The `ref` of the redirect rule Studio adds for a row, completed with the row id. */
    public const REDIRECT_RULE_REF = 'manara-studio-redirect-';

    public const SOURCE_STUDIO = 'studio';
    public const SOURCE_IMPORTED = 'imported';

    public const VERIFIED_BY_CLOUDFLARE = 'cloudflare';
    public const VERIFIED_BY_PROBE = 'probe';

    /** What a row is waiting on (`waiting_on`), set by App\Services\Domains\DomainAttacher. */
    public const WAITING_ON = ['token', 'token_scope', 'nameservers', 'certificate', 'capacity', 'canonical'];

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
        'role' => self::ROLE_SERVING,
        'source' => self::SOURCE_STUDIO,
        'cf_zone_created' => false,
        'cf_dns_record_created' => false,
        'cf_pages_domain_created' => false,
        'serving_miss_count' => 0,
    ];

    protected $fillable = [
        'masjid_id',
        'host',
        'kind',
        'role',
        'redirect_to_id',
        'zone_apex',
        'status',
        'waiting_on',
        'source',
        'cf_zone_id',
        'cf_dns_record_id',
        'cf_pages_domain_id',
        'cf_redirect_rule_id',
        'cf_zone_created',
        'cf_dns_record_created',
        'cf_pages_domain_created',
        'adopted_from_import_at',
        'nameservers',
        'last_error',
        'last_checked_at',
        'next_check_at',
        'stage_started_at',
        'verified_at',
        'serving_confirmed_at',
        'serving_last_seen_at',
        'serving_missed_since',
        'serving_miss_count',
        'verified_by',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'cf_zone_created' => 'boolean',
            'cf_dns_record_created' => 'boolean',
            'cf_pages_domain_created' => 'boolean',
            'adopted_from_import_at' => 'datetime',
            'nameservers' => 'array',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'stage_started_at' => 'datetime',
            'verified_at' => 'datetime',
            'serving_confirmed_at' => 'datetime',
            'serving_last_seen_at' => 'datetime',
            'serving_missed_since' => 'datetime',
            'serving_miss_count' => 'integer',
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

            if (! in_array($domain->role, self::ROLES, true)) {
                throw new LogicException("Unknown masjid_domains role [{$domain->role}].");
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

    /** The serving host a redirect row sends visitors to (W2 S5). */
    public function redirectTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redirect_to_id');
    }

    public function isRedirect(): bool
    {
        return $this->role === self::ROLE_REDIRECT;
    }

    public function redirectRuleRef(): string
    {
        return self::REDIRECT_RULE_REF . $this->id;
    }

    /**
     * Whether Studio may write a redirect rule or placeholder record in this
     * row's zone (W2 S5): a zone Studio itself created, recorded on any row of
     * it, or one the owner listed in `cloudflare.redirect_zones`. Everything
     * else, including every zone in the account before S5, is refused.
     */
    public function redirectZoneAllowed(): bool
    {
        if (in_array($this->zone_apex, array_map('strtolower', (array) config('cloudflare.redirect_zones', [])), true)) {
            return true;
        }

        return self::query()->where('zone_apex', $this->zone_apex)->where('cf_zone_created', true)->exists();
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
        return $query->whereIn('status', self::SERVED)
            ->where('role', self::ROLE_SERVING)
            ->whereHas('masjid');
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
     * Whether the daily serving re-probe owns this row (W2 S4): an active or
     * manual host seen serving, or one demoted after a run of misses and
     * waiting to be seen again. DomainAttacher::reconfirm() probes it.
     */
    public function underReconfirmation(): bool
    {
        return $this->role === self::ROLE_SERVING
            && in_array($this->status, self::TRUSTED, true)
            && ($this->serving_confirmed_at !== null || $this->serving_missed_since !== null);
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

        if ($this->isRedirect() && $this->status !== self::STATUS_DETACHING) {
            return $this->redirectSteps();
        }

        if ($this->status === self::STATUS_DETACHING) {
            return [
                "Studio is removing {$this->host} from Cloudflare, and the site no longer answers for this organisation on it.",
                ($this->last_error ? "The last attempt stopped: {$this->last_error} " : '')
                    . 'Studio tries again every five minutes; press Detach to try now.',
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
                match (true) {
                    $this->deletableThroughStudio() => "Once the cause is fixed, press Check now: it starts setting up {$this->host} again. Or remove this domain if it is not wanted.",
                    $this->ownedByStudio() => "Once the cause is fixed, press Check now: it starts setting up {$this->host} again. If it is not wanted, press Detach: Cloudflare holds records Studio made or found for it, and Detach takes off the ones Studio made before it lets the address go.",
                    default => "Once the cause is fixed, press Check now: it starts setting up {$this->host} again. It cannot be removed through Studio, because Cloudflare holds records Studio made or found for it.",
                },
            ];
        }

        if ($this->serving_confirmed_at !== null && in_array($this->status, self::TRUSTED, true)) {
            return [];
        }

        // Demoted by the daily re-probe (W2 S4): it was serving, then stopped.
        if ($this->underReconfirmation()) {
            return [
                "{$this->host} stopped answering for this organisation ({$this->serving_miss_count} checks in a row since "
                    . $this->serving_missed_since?->toDateTimeString() . ' UTC), so CORS and card-payment returns no longer trust it.',
                'Check the site and its DNS. Studio asks again every day and trusts it again as soon as it answers; press Check now to ask now.',
            ];
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
     * manualSteps() for a redirect row (W2 S5): one Cloudflare Single Redirect
     * and the proxied record it needs, by hand, for whatever Studio cannot do
     * itself (no token, the token's missing redirect scope, or a zone Studio
     * may not write in).
     *
     * @return list<string>
     */
    private function redirectSteps(): array
    {
        $target = $this->redirectTo?->host;
        $to = $target ?? 'its canonical host';

        if ($this->status === self::STATUS_MANUAL && $this->verified_at !== null) {
            return [];
        }

        if ($this->waiting_on === 'canonical') {
            return ["Waiting for {$to} to be attached first. Studio adds the redirect once it is."];
        }

        $byHand = [
            "In Cloudflare, open the {$this->zone_apex} zone, then Rules, then Redirect Rules, and create a Single Redirect: when the hostname equals {$this->host}, redirect dynamically to concat(\"https://{$to}\", http.request.uri.path) with status 301, preserving the query string.",
            "{$this->host} needs a proxied DNS record for the rule to answer: if it has none, add an A record for {$this->host} pointing to " . config('cloudflare.redirect_placeholder_address') . ', proxied.',
            "Then press Check now. Studio confirms it once https://{$this->host}/ answers 301 to {$to}.",
        ];

        if ($this->status === self::STATUS_FAILED) {
            return array_merge(['Adding the redirect failed' . ($this->last_error ? ": {$this->last_error}" : '.')], $byHand);
        }

        if (blank(config('cloudflare.studio_token'))) {
            return array_merge(['Without CLOUDFLARE_STUDIO_TOKEN Studio adds nothing in Cloudflare. By hand:'], $byHand);
        }

        if ($this->waiting_on === 'token_scope') {
            return array_merge([
                'Cloudflare refused CLOUDFLARE_STUDIO_TOKEN for redirect rules. Give the token Zone › Single Redirect: Edit (called Dynamic URL Redirects Write in the API) on this account, and Studio adds the redirect itself within half an hour. Or by hand:',
            ], $byHand);
        }

        return [
            "Nothing to do by hand: Studio is adding the redirect from {$this->host} to {$to} through Cloudflare and checks it every five minutes.",
        ];
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
            && $this->cf_pages_domain_id === null
            && $this->cf_redirect_rule_id === null;
    }

    /**
     * Whether this row is Studio's to detach (W2 S3): it was added through
     * Studio and never came from the live host map, directly or through S6's
     * `adopt`. An imported or adopted row is how a live organisation was
     * reached before Studio (R28), and keeps that protection for its life.
     */
    public function ownedByStudio(): bool
    {
        return $this->source === self::SOURCE_STUDIO && $this->adopted_from_import_at === null;
    }

    /**
     * Whether the screens offer Detach: a row Studio owns that DELETE would
     * refuse because Cloudflare holds something for it, or one whose detach
     * stopped part-way. A row with nothing in Cloudflare is simply removed.
     */
    public function detachableThroughStudio(): bool
    {
        return $this->ownedByStudio()
            && ($this->status === self::STATUS_DETACHING || ! $this->deletableThroughStudio());
    }

    /**
     * What an operator does by hand before this row can go, for the 409 that
     * refuses its deletion. For a row Studio owns, that is only what Studio did
     * not create itself, then Detach, which removes the rest (W2 S3). An
     * imported row is not Studio's to remove at all.
     *
     * @return list<string>
     */
    public function removalSteps(): array
    {
        if (! $this->ownedByStudio()) {
            return [
                "{$this->host} was imported from the live host map: it is how this organisation is reached today, so Studio does not remove it.",
                'If it really must go, the platform owner removes it with the renderer map and the CORS list in mind; it is not a Studio action.',
            ];
        }

        $steps = [];

        if ($this->cf_pages_domain_id !== null && ! $this->cf_pages_domain_created) {
            $steps[] = $this->pagesDomainRemovalStep();
        }

        if ($this->cf_dns_record_id !== null && ! $this->cf_dns_record_created) {
            $steps[] = $this->dnsRecordRemovalStep();
        }

        if ($this->cf_zone_created) {
            $steps[] = $this->zoneRemovalStep();
        } elseif ($this->cf_zone_id !== null) {
            $steps[] = "Studio found the {$this->zone_apex} zone on Cloudflare and did not create it; leave the zone itself alone.";
        }

        $made = array_values(array_filter([
            $this->cf_redirect_rule_id !== null ? 'the redirect rule' : null,
            $this->cf_pages_domain_id !== null && $this->cf_pages_domain_created ? 'the Pages custom domain' : null,
            $this->cf_dns_record_id !== null && $this->cf_dns_record_created ? 'the DNS record' : null,
        ]));

        $steps[] = $made === []
            ? 'Then press Detach: Studio stops serving the address and forgets it. Nothing it created is left in Cloudflare.'
            : 'Then press Detach: Studio removes what it created in Cloudflare (' . implode(' and ', $made) . ') and forgets the address.';

        return $steps;
    }

    /**
     * What detaching this row would do, from its flags alone and with no
     * request (W2 S3): the objects Studio created, which it removes if a fresh
     * read shows them unchanged, and the steps left for a person. The Detach
     * dialog and `domains:release`'s dry run both show this.
     *
     * @return array{would_remove: list<string>, manual_steps: list<string>}
     */
    public function detachPlan(): array
    {
        $remove = [];
        $manual = [];

        if ($this->cf_redirect_rule_id !== null) {
            $remove[] = "the redirect rule for {$this->host} in the {$this->zone_apex} zone, if it is still Studio's";
        }

        if ($this->cf_pages_domain_id !== null && $this->cf_pages_domain_created) {
            $remove[] = "the {$this->host} custom domain on the " . config('cloudflare.pages_project') . ' Pages project, if unchanged';
        } elseif ($this->cf_pages_domain_id !== null) {
            $manual[] = $this->pagesDomainRemovalStep();
        }

        if ($this->cf_dns_record_id !== null && $this->cf_dns_record_created) {
            $remove[] = "the {$this->host} DNS record in the {$this->zone_apex} zone, if unchanged";
        } elseif ($this->cf_dns_record_id !== null) {
            $manual[] = $this->dnsRecordRemovalStep();
        }

        if ($this->cf_zone_created) {
            $manual[] = $this->zoneRemovalStep();
        }

        return ['would_remove' => $remove, 'manual_steps' => $manual];
    }

    /** The dashboard step for a Pages custom domain Studio may not delete. */
    public function pagesDomainRemovalStep(): string
    {
        return 'In Cloudflare, open Workers & Pages, then the ' . config('cloudflare.pages_project')
            . " project, then Custom domains, and remove {$this->host}.";
    }

    /** The dashboard step for a DNS record Studio may not delete. */
    public function dnsRecordRemovalStep(): string
    {
        $record = $this->isRedirect() ? 'DNS record' : 'CNAME record';

        return "In Cloudflare, open the {$this->zone_apex} zone, then DNS, and delete the {$record} for {$this->host}.";
    }

    /** A zone is never deleted by Studio, even one it created (W2 S3). */
    public function zoneRemovalStep(): string
    {
        return "Studio added the {$this->zone_apex} zone to Cloudflare. It holds all of that domain's DNS, email included: remove it only after its owner agrees.";
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
            'role' => $this->role,
            'redirect_to_id' => $this->redirect_to_id !== null ? (int) $this->redirect_to_id : null,
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
            'serving_last_seen_at' => $this->serving_last_seen_at?->toIso8601String(),
            'serving_missed_since' => $this->serving_missed_since?->toIso8601String(),
            'serving_miss_count' => (int) $this->serving_miss_count,
            'live_url' => $this->liveUrl(),
            'manual_steps' => $this->manualSteps(),
            'deletable' => $this->deletableThroughStudio(),
            'detachable' => $this->detachableThroughStudio(),
            'detach_plan' => $this->detachableThroughStudio() ? $this->detachPlan() : null,
        ];
    }
}
