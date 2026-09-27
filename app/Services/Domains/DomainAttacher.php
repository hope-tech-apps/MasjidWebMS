<?php

namespace App\Services\Domains;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Moves one `masjid_domains` row one step closer to serving (Manara Studio W1,
 * S7; D17). Called by the AttachMasjidDomain job right after a host is added,
 * by `domains:reconcile` every five minutes, and by the admin "Check now".
 *
 * ## The steps, with a token
 *
 *   pending (managed)    -> DNS step in the managed zone
 *   pending (custom)     -> find the zone; active: DNS step; pending: wait for
 *                           nameservers; absent: create it, then wait
 *   awaiting_nameservers -> zone active: DNS step; still pending after 28 days
 *                           or gone: failed (Cloudflare deletes such a zone)
 *   DNS step             -> ensureCname; a record Studio did not create fails
 *                           the row and is never touched
 *   Pages step           -> ensurePagesDomain; at the ceiling the row waits on
 *                           `capacity`; otherwise provisioning
 *   provisioning         -> Pages says active: `active`, verified by Cloudflare,
 *                           then the probe; error/blocked/deactivated: failed
 *                           with Cloudflare's words; anything else (an
 *                           unrecognised status included) waits, is retried once
 *                           at 24 h and fails at 72 h
 *   active, unconfirmed  -> the probe only
 *
 * ## Without a token
 *
 * Nothing is sent to Cloudflare. A row that is still moving records
 * `waiting_on = token`, and then the probe asks the host itself. A match makes
 * a pending row `manual`, verified by the probe and seen serving: that is how a
 * host the owner attached by hand in the dashboard goes live while the token is
 * absent. The probe is the only outbound request, and it goes to the row's own
 * host.
 *
 * ## Rows this never writes to Cloudflare for
 *
 *  - rows of a trashed organisation are not touched at all (W2 S2): no
 *    request, no write, whichever entry point asked. A restore resumes them.
 *
 *  - `reserved` rows are never advanced at all: no probe, no read, no write
 *    (R4). They hold a live tenant's host without trusting it.
 *  - `imported` rows and `manual` rows, when there is a token, are promoted by
 *    READS only: if Cloudflare's Pages project already lists the host as
 *    active, the row becomes `active`. Nothing is created, changed or retried
 *    for them, whatever Cloudflare says, and they are never failed.
 *  - `detaching` rows belong to DomainDetacher (W2 S3) and are never advanced.
 *  - `failed` rows are left alone; "Check now" resets one to pending first
 *    (restart()), which is also the only way forward for a failed row that
 *    Cloudflare holds records for, since DELETE refuses that row (R28); Detach
 *    (W2 S3, DomainDetacher) takes a Studio row off Cloudflare instead.
 *
 * ## One writer at a time
 *
 * Each row is advanced under Cache::lock('masjid-domain:<id>', LOCK_SECONDS)
 * (lockFor()), and the row is re-read inside the lock. If the lock is held (the
 * job and the schedule reached the same row), advance() does nothing: the
 * holder is doing the work, and "Check now" answers 409 to try again
 * (checkNow()). DELETE takes the same lock, because a step keeps what it made
 * in Cloudflare in memory until its one save at the end: a row deleted mid-step
 * would leave a CNAME, a Pages domain or a whole zone that nothing records.
 *
 * ## Re-confirmation (W2 S4)
 *
 * A host once seen serving is probed again at most once a day
 * (config `cloudflare.reconfirm`), by reconfirm(). A match records
 * `serving_last_seen_at`; a miss counts toward `serving_miss_count` from
 * `serving_missed_since`. A Studio row loses `serving_confirmed_at`, and with it
 * CORS and card-payment-return admission, only after three misses in a row
 * spanning at least 72 hours; its status is unchanged, so the lookup keeps
 * answering and a later match re-confirms it. An imported or adopted row is
 * never demoted: at its third miss the owner is emailed through `monitors`.
 */
class DomainAttacher
{
    /**
     * The most Cloudflare requests one step can send. A custom host whose zone
     * the account lacks: createZone() reads (1) and posts (2), and if that zone
     * came back active the same step goes on to the DNS step, ensureCname()
     * reads (3), posts (4) and, when the post loses an "already exists" race,
     * reads again (5), then to the Pages step, ensurePagesDomain() reads the
     * domain (6), counts the project (7) and posts (8). Every other path sends
     * fewer: a zone found or waited for skips the zone post (7 at most), and a
     * step that probes sends at most two. CloudflareService never retries.
     */
    public const MAX_CLOUDFLARE_REQUESTS_PER_STEP = 8;

    /**
     * How long one step may hold its row. It must outlive the slowest step, or
     * a second writer (the schedule, a Check now, a DELETE) takes the row while
     * the first still holds what it made in Cloudflare only in memory.
     *
     * The slowest step is MAX_CLOUDFLARE_REQUESTS_PER_STEP requests, each
     * allowed config('cloudflare.timeout') to connect and the same again as its
     * total timeout (CloudflareService::client()): 8 x (15 + 15) = 240 s.
     * Counting both in full over-counts, since curl's total includes the
     * connect, which is the side to err on. A step that probes sends at most
     * two Cloudflare requests (60 s) plus the probe's DNS lookup and its
     * 5 + 5 s fetch, well inside that. The last 60 s are for the database
     * writes and a slow worker. Pinned by DomainAttacherTest against the
     * configured timeout, so raising the timeout without this fails a test.
     */
    public const LOCK_SECONDS = self::MAX_CLOUDFLARE_REQUESTS_PER_STEP * (15 + 15) + 60;

    /** Cloudflare deletes a Free-plan zone left pending longer than this. */
    public const ZONE_PENDING_LIMIT_DAYS = 28;

    /** Pages is asked to validate again once, this long after the domain was added. */
    public const CERTIFICATE_RETRY_AFTER_HOURS = 24;

    /** A certificate still not issued this long after the domain was added fails the row. */
    public const CERTIFICATE_LIMIT_HOURS = 72;

    /** Cloudflare rate-limits the activation check; once per row per this many hours. */
    public const ACTIVATION_CHECK_EVERY_HOURS = 6;

    /** How long an imported or manual row waits before its next read-only look. */
    public const READ_ONLY_RECHECK_HOURS = 6;

    /** Per row: the one Pages retry of a provisioning stage has been sent. */
    private const PAGES_RETRY_KEY = 'masjid-domain:pages-retry:';

    /** Per row: an activation check went in the last ACTIVATION_CHECK_EVERY_HOURS. */
    private const ACTIVATION_CHECK_KEY = 'masjid-domain:activation-check:';

    /** Per row: the serving re-probe ran in the last `cloudflare.reconfirm.every_hours`. */
    private const REPROBE_KEY = 'masjid-domain:reprobe:';

    /**
     * The re-probe marker lives this much less than `every_hours`, so the run
     * that finds a row due a day later always finds the marker gone. Equal
     * lifetimes race: `next_check_at` is stored to the second, the run can
     * reach the row a moment before the marker expires, and the probe would
     * slip a whole day.
     */
    private const REPROBE_MARGIN_MINUTES = 30;

    /** Per row: when a zone POST went out whose answer never came back. */
    private const ZONE_CREATE_SENT_KEY = 'masjid-domain:zone-create-sent:';

    /** Allowance between this server's clock and Cloudflare's created_on. */
    private const CLOCK_ALLOWANCE_MINUTES = 5;

    private const PAGES_FAILED = ['error', 'blocked', 'deactivated'];

    private const ZONE_WAITING = ['initializing', 'pending'];

    /** Whether the step in progress has already probed its row, so no step probes twice. */
    private bool $probedThisStep = false;

    public function __construct(
        private readonly CloudflareService $cloudflare,
        private readonly DomainProbe $probe,
    ) {
    }

    /** The lock every writer of one row's Cloudflare state holds: advance() and DELETE. */
    public static function lockFor(int $domainId): Lock
    {
        return Cache::lock('masjid-domain:' . $domainId, self::LOCK_SECONDS);
    }

    /**
     * "Check now" on a failed row: back to pending with its clocks cleared, so
     * the next advance() starts a fresh stage. The stage markers go with it.
     * The Pages-retry marker lives four days, longer than the 72-hour stage it
     * was set in, so one left behind would swallow the new stage's only retry.
     * The zone-create marker stays: it records a zone Cloudflare may already
     * hold for this row, which the next look must still count as Studio's.
     *
     * Under the row's lock, on the row re-read inside it, like every other
     * writer: the caller's copy may be one another Check now or a DELETE has
     * already moved on from.
     */
    public function restart(MasjidDomain $domain): void
    {
        if (! $domain->exists) {
            return;
        }

        $lock = self::lockFor($domain->id);

        if (! $lock->get()) {
            return;
        }

        try {
            try {
                $domain->refresh();
            } catch (ModelNotFoundException) {
                return;
            }

            if (! $this->organisationLive($domain)) {
                return;
            }

            $this->restartIfFailed($domain);
        } finally {
            $lock->release();
        }
    }

    /**
     * "Check now": restart() and then advance(), under one hold of the row's
     * lock, answering whether it got the row at all. False means another
     * writer holds it and nothing was done, which the caller must say rather
     * than show the unchanged row as if it had been checked.
     *
     * @throws ModelNotFoundException when the row was deleted before the lock
     */
    public function checkNow(MasjidDomain $domain): bool
    {
        $lock = self::lockFor($domain->id);

        if (! $lock->get()) {
            return false;
        }

        try {
            $domain->refresh();

            if ($this->organisationLive($domain)) {
                $this->restartIfFailed($domain);
                // An operator asked: a confirmed row is probed now, not when due.
                $this->step($domain, reprobeNow: true);
            }
        } finally {
            $lock->release();
        }

        return true;
    }

    /** restart()'s work, for a caller that already holds the lock and re-read the row. */
    private function restartIfFailed(MasjidDomain $domain): void
    {
        if ($domain->status !== MasjidDomain::STATUS_FAILED) {
            return;
        }

        $domain->forceFill([
            'status' => MasjidDomain::STATUS_PENDING,
            'waiting_on' => null,
            'last_error' => null,
            'stage_started_at' => null,
            'next_check_at' => null,
        ])->save();

        Cache::forget(self::PAGES_RETRY_KEY . $domain->id);
        Cache::forget(self::ACTIVATION_CHECK_KEY . $domain->id);
    }

    /**
     * Whether the row's organisation is live (W2 S2). A trashed organisation's
     * rows are left exactly as they are, by every entry point: the job, the
     * schedule and "Check now" alike. Nothing is sent or written, so nothing
     * is attached in Cloudflare for an organisation that is not live, and a
     * restore resumes each row where it stopped. The relation applies Masjid's
     * SoftDeletes scope, as MasjidDomain::served() relies on.
     */
    private function organisationLive(MasjidDomain $domain): bool
    {
        return $domain->masjid()->exists();
    }

    public function advance(MasjidDomain $domain): MasjidDomain
    {
        if (! $domain->exists) {
            return $domain;
        }

        $lock = self::lockFor($domain->id);

        if (! $lock->get()) {
            return $domain;
        }

        try {
            try {
                $domain->refresh();
            } catch (ModelNotFoundException) {
                return $domain;
            }

            if (! $this->organisationLive($domain)) {
                return $domain;
            }

            $this->step($domain);
        } finally {
            $lock->release();
        }

        return $domain;
    }

    private function step(MasjidDomain $domain, bool $reprobeNow = false): void
    {
        $this->probedThisStep = false;

        // `detaching` belongs to DomainDetacher (W2 S3): attaching it again
        // mid-removal would re-create what is being taken away.
        if (in_array($domain->status, [MasjidDomain::STATUS_RESERVED, MasjidDomain::STATUS_FAILED, MasjidDomain::STATUS_DETACHING], true)) {
            return;
        }

        $domain->last_checked_at = now();

        if ($domain->underReconfirmation()) {
            $this->reconfirm($domain, $reprobeNow);

            // With a token a manual or imported row is still promoted by
            // reads, as in W1; nothing else about a confirmed row changes.
            if ($this->cloudflare->isConfigured()
                && $domain->status !== MasjidDomain::STATUS_ACTIVE
                && ($domain->source === MasjidDomain::SOURCE_IMPORTED || $domain->status === MasjidDomain::STATUS_MANUAL)) {
                $this->promoteByReads($domain);
            }

            $domain->save();

            return;
        }

        if ($domain->isRedirect()) {
            $this->stepRedirect($domain);
            $domain->save();

            return;
        }

        if (! $this->cloudflare->isConfigured()) {
            $this->withoutToken($domain);
        } elseif ($domain->status === MasjidDomain::STATUS_ACTIVE) {
            $this->confirmServing($domain);
        } elseif ($domain->source === MasjidDomain::SOURCE_IMPORTED || $domain->status === MasjidDomain::STATUS_MANUAL) {
            $this->promoteByReads($domain);
        } else {
            if ($domain->waiting_on === 'token') {
                $domain->waiting_on = null;
            }

            match ($domain->status) {
                MasjidDomain::STATUS_PENDING => $this->fromPending($domain),
                MasjidDomain::STATUS_AWAITING_NAMESERVERS => $this->fromAwaitingNameservers($domain),
                MasjidDomain::STATUS_PROVISIONING => $this->fromProvisioning($domain),
                default => null,
            };
        }

        $domain->save();
    }

    /**
     * No token: say so on a row that is still moving, send nothing to
     * Cloudflare, and let the host speak for itself.
     */
    private function withoutToken(MasjidDomain $domain): void
    {
        if (in_array($domain->status, MasjidDomain::NON_TERMINAL, true)) {
            $domain->waiting_on = 'token';
        }

        $this->runProbe($domain);

        if ($domain->serving_confirmed_at !== null && in_array($domain->status, MasjidDomain::TRUSTED, true)) {
            $domain->stage_started_at = null;
            $domain->next_check_at = null;

            return;
        }

        $domain->next_check_at = $this->backoffFrom($domain->created_at);
    }

    /** An active row whose serving has not been seen yet: the probe, and nothing else. */
    private function confirmServing(MasjidDomain $domain): void
    {
        if ($domain->serving_confirmed_at === null && ! $this->probedThisStep) {
            $this->runProbe($domain);
        }

        $domain->next_check_at = $domain->serving_confirmed_at === null
            ? $this->backoffFrom($domain->verified_at)
            : null;
    }

    /**
     * An imported or manual row with a token: become `active` if, and only if,
     * the Pages project already lists the host as active. GETs only. Nothing
     * Cloudflare answers can fail such a row or change it any other way: it is
     * how a live organisation is reached today.
     */
    private function promoteByReads(MasjidDomain $domain): void
    {
        $pages = $this->cloudflare->getPagesDomain($domain->host);

        if ($pages->is(CloudflareResult::UNAUTHORIZED)) {
            $domain->waiting_on = 'token_scope';
            $domain->last_error = 'Cloudflare refused the token when reading the Pages project: ' . $pages->error;
        } elseif ($pages->is(CloudflareResult::OK) && ($pages->data['status'] ?? null) === 'active') {
            $this->recordPagesDomainId($domain, (string) $pages->data['id']);
            $domain->cf_zone_id = $pages->data['zone_tag'] ?: $domain->cf_zone_id;
            $domain->status = MasjidDomain::STATUS_ACTIVE;
            $domain->verified_by = MasjidDomain::VERIFIED_BY_CLOUDFLARE;
            $domain->verified_at = now();
            $domain->waiting_on = null;
            $domain->last_error = null;

            $this->confirmServing($domain);

            return;
        } elseif ($domain->waiting_on === 'token' || $domain->waiting_on === 'token_scope') {
            $domain->waiting_on = null;
        }

        $domain->next_check_at = now()->addHours(self::READ_ONLY_RECHECK_HOURS);
    }

    private function fromPending(MasjidDomain $domain): void
    {
        if ($domain->kind === MasjidDomain::KIND_MANAGED_SUBDOMAIN) {
            $this->attach($domain, (string) config('cloudflare.managed_zone_id'));

            return;
        }

        // createZone() looks first and adds the zone only when the account does
        // not have it, so one call covers "found" (adopted) and "added"
        // (created); only the second is a zone Studio made. A POST whose
        // answer was lost may have made it all the same, so that attempt is
        // remembered and a zone found on a later tick is judged against it.
        $attemptedAt = now();
        $zone = $this->cloudflare->createZone($domain->zone_apex);

        if ($zone->is(CloudflareResult::CREATED)
            || ($zone->is(CloudflareResult::ADOPTED) && $this->madeByLostCreate($domain, $zone))) {
            $domain->cf_zone_created = true;
        }

        if ($zone->is(CloudflareResult::CREATED, CloudflareResult::ADOPTED)) {
            Cache::forget(self::ZONE_CREATE_SENT_KEY . $domain->id);
        } elseif ($zone->data['create_sent'] ?? false) {
            Cache::add(self::ZONE_CREATE_SENT_KEY . $domain->id, $attemptedAt->getTimestamp(), now()->addDays(self::ZONE_PENDING_LIMIT_DAYS));
        }

        if ($this->stopped($domain, $zone)) {
            return;
        }

        $status = (string) ($zone->data['status'] ?? '');

        if ($status === 'active') {
            $this->attach($domain, (string) $zone->data['id']);

            return;
        }

        if (in_array($status, self::ZONE_WAITING, true)) {
            $domain->cf_zone_id = (string) $zone->data['id'];
            $domain->nameservers = $zone->data['name_servers'] ?: $domain->nameservers;
            $domain->status = MasjidDomain::STATUS_AWAITING_NAMESERVERS;
            $domain->waiting_on = 'nameservers';
            $domain->stage_started_at = now();
            $domain->last_error = null;
            $domain->next_check_at = now()->addMinutes(30);

            return;
        }

        $this->fail($domain, "Cloudflare reports the {$domain->zone_apex} zone as \"{$status}\", so Studio cannot attach {$domain->host} to it.");
    }

    private function fromAwaitingNameservers(MasjidDomain $domain): void
    {
        $zone = $this->cloudflare->getZone((string) $domain->cf_zone_id);

        if ($zone->is(CloudflareResult::ABSENT)) {
            $this->fail($domain, "The {$domain->zone_apex} zone is no longer on Cloudflare (a zone left waiting for its nameservers for "
                . self::ZONE_PENDING_LIMIT_DAYS . ' days is deleted).');

            return;
        }

        if ($this->stopped($domain, $zone)) {
            return;
        }

        $status = (string) ($zone->data['status'] ?? '');

        if ($status === 'active') {
            $domain->waiting_on = null;
            $domain->stage_started_at = null;
            $this->attach($domain, (string) ($zone->data['id'] ?: $domain->cf_zone_id));

            return;
        }

        if (! in_array($status, self::ZONE_WAITING, true)) {
            $this->fail($domain, "Cloudflare reports the {$domain->zone_apex} zone as \"{$status}\", so Studio cannot attach {$domain->host} to it.");

            return;
        }

        $since = $domain->stage_started_at ?? ($domain->stage_started_at = now());

        if ($since->lte(now()->subDays(self::ZONE_PENDING_LIMIT_DAYS))) {
            $this->fail($domain, "The {$domain->zone_apex} zone was still waiting for its nameservers after "
                . self::ZONE_PENDING_LIMIT_DAYS . ' days. Cloudflare deletes a zone left pending that long; start again once the registrar is ready to change the nameservers.');

            return;
        }

        $domain->nameservers = $zone->data['name_servers'] ?: $domain->nameservers;
        $domain->waiting_on = 'nameservers';

        if (Cache::add(self::ACTIVATION_CHECK_KEY . $domain->id, true, now()->addHours(self::ACTIVATION_CHECK_EVERY_HOURS))) {
            $check = $this->cloudflare->requestActivationCheck((string) $domain->cf_zone_id);

            if ($check->is(CloudflareResult::UNAUTHORIZED)) {
                $domain->waiting_on = 'token_scope';
                $domain->last_error = 'Cloudflare refused the token: ' . $check->error;
            }
        }

        $domain->next_check_at = now()->addMinutes(30);
    }

    private function fromProvisioning(MasjidDomain $domain): void
    {
        $pages = $this->cloudflare->getPagesDomain($domain->host);

        if ($pages->is(CloudflareResult::ABSENT)) {
            $this->fail($domain, "{$domain->host} is no longer a custom domain on the " . config('cloudflare.pages_project')
                . ' Pages project.');

            return;
        }

        if ($this->stopped($domain, $pages)) {
            return;
        }

        $status = (string) ($pages->data['status'] ?? '');

        if ($status === 'active') {
            $this->recordPagesDomainId($domain, (string) $pages->data['id']);
            $domain->status = MasjidDomain::STATUS_ACTIVE;
            $domain->verified_by = MasjidDomain::VERIFIED_BY_CLOUDFLARE;
            $domain->verified_at = now();
            $domain->waiting_on = null;
            $domain->stage_started_at = null;
            $domain->last_error = null;

            $this->confirmServing($domain);

            return;
        }

        if (in_array($status, self::PAGES_FAILED, true)) {
            $said = $pages->data['validation_data']['error_message']
                ?? $pages->data['verification_data']['error_message']
                ?? null;

            $this->fail($domain, "Cloudflare Pages reports {$domain->host} as \"{$status}\""
                . (filled($said) ? ': ' . $said : '.'));

            return;
        }

        // `initializing`, `pending`, or a status the API reference did not list
        // when this was written: keep waiting, on the same 72-hour clock.
        $since = $domain->stage_started_at ?? ($domain->stage_started_at = now());

        if ($since->lte(now()->subHours(self::CERTIFICATE_LIMIT_HOURS))) {
            $this->fail($domain, "Cloudflare had not issued a certificate for {$domain->host} "
                . self::CERTIFICATE_LIMIT_HOURS . " hours after it was added (Pages status \"{$status}\"). Check its DNS and CAA records.");

            return;
        }

        if ($since->lte(now()->subHours(self::CERTIFICATE_RETRY_AFTER_HOURS))
            && Cache::add(self::PAGES_RETRY_KEY . $domain->id, true, now()->addDays(4))) {
            $this->cloudflare->retryPagesDomain($domain->host);
        }

        $domain->waiting_on = 'certificate';
        $domain->last_error = in_array($status, CloudflareService::PAGES_STATUSES, true)
            ? null
            : "Cloudflare Pages reports an unrecognised status \"{$status}\" for {$domain->host}; still waiting.";
        $domain->next_check_at = $this->backoffFrom($since);
    }

    /**
     * The DNS step, then the Pages step. The zone id is recorded here only once
     * the CNAME is Studio's or adopted, so a managed host, or one whose zone was
     * already active, that fails on a record it may not touch keeps no
     * Cloudflare id and can still be removed through Studio. A custom host that
     * waited for its nameservers already carries its zone id (and perhaps
     * cf_zone_created), so it cannot: the failure text names the cause only,
     * and MasjidDomain::manualSteps() says which way forward the row has.
     */
    private function attach(MasjidDomain $domain, string $zoneId): void
    {
        $cname = $this->cloudflare->ensureCname($zoneId, $domain->host, 'Manara Studio: organisation ' . $domain->masjid_id);

        if ($cname->is(CloudflareResult::CONFLICT)) {
            $this->fail($domain, "{$domain->host} already has a DNS record ({$cname->data['type']} {$cname->data['content']}). "
                . 'Studio will not overwrite a DNS record it did not create: change or remove it in Cloudflare.');

            return;
        }

        if ($this->stopped($domain, $cname)) {
            return;
        }

        // A flag says Studio's own POST made the object (W2 S3), which is the
        // only thing DomainDetacher may delete. An adopted record keeps it only
        // if it is the very record Studio made on an earlier step.
        $recordId = $cname->data['id'] ?: $domain->cf_dns_record_id;
        $domain->cf_dns_record_created = $cname->is(CloudflareResult::CREATED)
            || ($domain->cf_dns_record_created && $recordId === $domain->cf_dns_record_id);
        $domain->cf_zone_id = $zoneId;
        $domain->cf_dns_record_id = $recordId;

        $pages = $this->cloudflare->ensurePagesDomain($domain->host);

        if ($pages->is(CloudflareResult::CONFLICT)) {
            $domain->status = MasjidDomain::STATUS_PENDING;
            $domain->waiting_on = 'capacity';
            $domain->last_error = $pages->error;
            $domain->next_check_at = now()->addHour();

            return;
        }

        if ($this->stopped($domain, $pages)) {
            return;
        }

        $pagesId = $pages->data['id'] ?: $domain->cf_pages_domain_id;
        $domain->cf_pages_domain_created = $pages->is(CloudflareResult::CREATED)
            || ($domain->cf_pages_domain_created && $pagesId === $domain->cf_pages_domain_id);
        $domain->cf_pages_domain_id = $pagesId;
        $domain->status = MasjidDomain::STATUS_PROVISIONING;
        $domain->waiting_on = 'certificate';
        $domain->stage_started_at = now();
        $domain->last_error = null;
        $domain->next_check_at = now()->addMinutes(5);
    }

    /**
     * Record the Pages custom domain a read found. A different id than the
     * one stored is not the domain Studio created, whatever Studio did earlier
     * (someone removed and re-added the host), so it loses the created flag
     * that would let detach delete it (W2 S3).
     */
    private function recordPagesDomainId(MasjidDomain $domain, string $id): void
    {
        if ($id === '') {
            return;
        }

        if ($id !== $domain->cf_pages_domain_id) {
            $domain->cf_pages_domain_created = false;
        }

        $domain->cf_pages_domain_id = $id;
    }

    /**
     * Record a call that did not go through, and say whether the step must
     * stop. The status never moves backwards here: a refused token waits on
     * `token_scope`, a rate limit or an outage is tried again on the next tick,
     * and only a request Cloudflare rejected outright fails the row, with its
     * own words.
     */
    private function stopped(MasjidDomain $domain, CloudflareResult $result): bool
    {
        if ($result->ok) {
            return false;
        }

        if ($result->is(CloudflareResult::UNAUTHORIZED)) {
            $domain->waiting_on = 'token_scope';
            $domain->last_error = 'Cloudflare refused the token: ' . $result->error;
            $domain->next_check_at = now()->addMinutes(30);
        } elseif ($result->is(CloudflareResult::NOT_CONFIGURED)) {
            $domain->waiting_on = 'token';
            $domain->next_check_at = now()->addMinutes(5);
        } elseif ($result->is(CloudflareResult::REJECTED)) {
            $this->fail($domain, 'Cloudflare refused the request: ' . $result->error);
        } else {
            $domain->last_error = $result->error;
            $domain->next_check_at = now()->addMinutes(5);
        }

        return true;
    }

    private function fail(MasjidDomain $domain, string $reason): void
    {
        $domain->status = MasjidDomain::STATUS_FAILED;
        $domain->waiting_on = null;
        $domain->last_error = $reason;
        $domain->stage_started_at = null;
        $domain->next_check_at = null;
    }

    /**
     * Probe, recording what the host said when it has never been seen serving.
     * A miss after a match writes nothing here: a confirmed row's later
     * probes are reconfirm()'s, which keeps its serving health (W2 S4).
     */
    private function runProbe(MasjidDomain $domain): void
    {
        $result = $this->probe->confirm($domain);
        $this->probedThisStep = true;

        if ($result['matched']) {
            $domain->serving_last_seen_at = now();
            $domain->serving_missed_since = null;
            $domain->serving_miss_count = 0;
        }

        if ($result['matched']) {
            if ($domain->waiting_on === 'token') {
                $domain->waiting_on = null;
            }

            $domain->last_error = null;
        } elseif ($domain->serving_confirmed_at === null) {
            $domain->last_error = 'Not serving this organisation yet: ' . $result['seen'];
        }
    }

    /**
     * Probe a host already seen serving (or one demoted and waiting to be seen
     * again), at most once per `cloudflare.reconfirm.every_hours` unless an
     * operator pressed Check now, and keep its serving health (W2 S4).
     *
     * A match records `serving_last_seen_at` and ends any run of misses; for a
     * demoted row it is the re-confirmation (DomainProbe::confirm()). A miss
     * extends the run. A Studio row that is confirmed is demoted, by clearing
     * `serving_confirmed_at` and nothing that decides serving, only once the
     * run has reached `demote_after_misses` AND started at least
     * `demote_after_hours` ago. An imported or adopted row never is (R10): at
     * exactly the third miss of a run it tells the owner, once per run.
     */
    private function reconfirm(MasjidDomain $domain, bool $now): void
    {
        $every = (int) config('cloudflare.reconfirm.every_hours', 24);
        $markerUntil = now()->addHours($every)->subMinutes(self::REPROBE_MARGIN_MINUTES);

        if ($now) {
            Cache::put(self::REPROBE_KEY . $domain->id, true, $markerUntil);
        } elseif (! Cache::add(self::REPROBE_KEY . $domain->id, true, $markerUntil)) {
            // Asked again inside the day (a manual row's six-hourly reads, or a
            // run that came early): look again once the marker has gone.
            $domain->next_check_at = now()->addMinutes(self::REPROBE_MARGIN_MINUTES);

            return;
        }

        $domain->next_check_at = now()->addHours($every);

        $wasConfirmed = $domain->serving_confirmed_at !== null;
        // A confirmed row keeps the time it was first confirmed; only a
        // demoted one is stamped again, by confirm().
        $result = $wasConfirmed ? $this->probe->probe($domain) : $this->probe->confirm($domain);
        $this->probedThisStep = true;

        if ($result['matched']) {
            $domain->serving_last_seen_at = now();
            $domain->serving_missed_since = null;
            $domain->serving_miss_count = 0;

            if (! $wasConfirmed) {
                $domain->last_error = null;
            }

            return;
        }

        $domain->serving_miss_count = (int) $domain->serving_miss_count + 1;
        $domain->serving_missed_since ??= now();

        $misses = (int) config('cloudflare.reconfirm.demote_after_misses', 3);
        $hours = (int) config('cloudflare.reconfirm.demote_after_hours', 72);

        if ($domain->ownedByStudio()) {
            if ($wasConfirmed
                && $domain->serving_miss_count >= $misses
                && $domain->serving_missed_since->lte(now()->subHours($hours))) {
                $domain->serving_confirmed_at = null;
                $domain->last_error = "Not seen serving this organisation in {$domain->serving_miss_count} checks since "
                    . $domain->serving_missed_since->toDateTimeString() . " UTC (last: {$result['seen']}). "
                    . 'CORS and card-payment returns no longer trust it until a check sees it again.';

                Log::warning('A Studio web address stopped serving its organisation and lost its CORS and payment-return admission.', [
                    'masjid_domain_id' => $domain->id,
                    'masjid_id' => (int) $domain->masjid_id,
                    'host' => $domain->host,
                    'misses' => $domain->serving_miss_count,
                    'missed_since' => $domain->serving_missed_since->toIso8601String(),
                    'seen' => $result['seen'],
                ]);
            }

            return;
        }

        if ($domain->serving_miss_count === $misses) {
            Log::channel('monitors')->error(
                "domains: {$domain->host} (organisation #{$domain->masjid_id}, from the live host map) has not answered for its organisation in "
                . "{$misses} daily checks since " . $domain->serving_missed_since->toDateTimeString() . " UTC. The probe saw: {$result['seen']}. "
                . 'It keeps its CORS and payment-return admission: an imported host is never withdrawn automatically. '
                . 'Review it with `php artisan domains:imported list`.',
                [
                    'masjid_domain_id' => $domain->id,
                    'masjid_id' => (int) $domain->masjid_id,
                    'host' => $domain->host,
                    'misses' => $domain->serving_miss_count,
                    'missed_since' => $domain->serving_missed_since->toIso8601String(),
                    'seen' => $result['seen'],
                ],
            );
        }
    }

    /**
     * A redirect row (W2 S5): the host answers 301 to its serving sibling by a
     * Cloudflare Single Redirect rule, and uses no Pages slot.
     *
     *   pending      -> wait for the sibling to have its zone (`canonical`);
     *                   refuse a zone Studio neither created nor was allowed
     *                   (config `cloudflare.redirect_zones`) BEFORE any
     *                   request; read the zone's redirect entry point (a
     *                   refused token waits on `token_scope`); the proxied
     *                   placeholder record; the rule, appended; provisioning
     *   provisioning -> the probe sees the 301 to the sibling: `manual`,
     *                   verified by the probe; 72 hours without it: failed
     *
     * Without a token nothing is sent to Cloudflare; the probe alone can see a
     * redirect the owner made by hand.
     */
    private function stepRedirect(MasjidDomain $domain): void
    {
        $canonical = $domain->redirectTo;

        if ($canonical === null) {
            $this->fail($domain, "The address {$domain->host} redirects to is no longer recorded, so there is nothing to redirect it to.");

            return;
        }

        if ($domain->status === MasjidDomain::STATUS_MANUAL && $domain->verified_at !== null) {
            $domain->next_check_at = null;

            return;
        }

        if (! $this->cloudflare->isConfigured()) {
            if (in_array($domain->status, MasjidDomain::NON_TERMINAL, true)) {
                $domain->waiting_on = 'token';
            }

            $this->verifyRedirect($domain, $canonical);

            if ($domain->status !== MasjidDomain::STATUS_MANUAL) {
                $domain->next_check_at = $this->backoffFrom($domain->created_at);
            }

            return;
        }

        if ($domain->waiting_on === 'token') {
            $domain->waiting_on = null;
        }

        if ($domain->status === MasjidDomain::STATUS_PROVISIONING) {
            $this->verifyRedirect($domain, $canonical);

            return;
        }

        // The sibling must be past its DNS step: a zone id is recorded while a
        // new zone still waits for its nameservers, which can take 28 days,
        // longer than this row's 72-hour verify clock.
        $ready = [MasjidDomain::STATUS_PROVISIONING, MasjidDomain::STATUS_ACTIVE, MasjidDomain::STATUS_MANUAL];

        if ($canonical->cf_zone_id === null
            || $canonical->zone_apex !== $domain->zone_apex
            || ! in_array($canonical->status, $ready, true)) {
            $domain->waiting_on = 'canonical';
            $domain->next_check_at = now()->addMinutes(30);

            return;
        }

        if (! $domain->redirectZoneAllowed()) {
            $this->fail($domain, "Studio adds redirect rules only in a zone it created itself or one the platform owner has listed in cloudflare.redirect_zones, and {$domain->zone_apex} is neither. Add the redirect by hand, or ask the owner to list the zone.");

            return;
        }

        $zoneId = (string) $canonical->cf_zone_id;
        // Read first: a token without the redirect scope is refused here,
        // before a placeholder record is made for a rule that cannot follow.
        if ($this->stopped($domain, $this->cloudflare->getRedirectEntrypoint($zoneId))) {
            return;
        }

        if ($domain->cf_dns_record_id === null) {
            $record = $this->cloudflare->ensureProxiedPlaceholder($zoneId, $domain->host, 'Manara Studio: redirect for organisation ' . $domain->masjid_id);

            if ($record->is(CloudflareResult::CONFLICT)) {
                $this->fail($domain, "{$domain->host} already has a DNS record ({$record->data['type']} {$record->data['content']}). "
                    . 'Studio will not overwrite a DNS record it did not create: change or remove it in Cloudflare.');

                return;
            }

            if ($this->stopped($domain, $record)) {
                return;
            }

            $domain->cf_zone_id = $zoneId;
            $domain->cf_dns_record_id = $record->data['id'] ?: null;
            $domain->cf_dns_record_created = $record->is(CloudflareResult::CREATED);
        }

        $rule = $this->cloudflare->ensureRedirectRule($zoneId, $domain->redirectRuleRef(), $domain->host, $canonical->host);

        if ($rule->is(CloudflareResult::CONFLICT)) {
            $this->fail($domain, (string) $rule->error);

            return;
        }

        if ($this->stopped($domain, $rule)) {
            return;
        }

        $domain->cf_zone_id = $zoneId;
        $domain->cf_redirect_rule_id = (string) $rule->data['id'];
        $domain->status = MasjidDomain::STATUS_PROVISIONING;
        $domain->waiting_on = null;
        $domain->stage_started_at = now();
        $domain->last_error = null;
        $domain->next_check_at = now()->addMinutes(5);
    }

    /** The redirect probe: a 301 to the sibling makes the row `manual`, verified by the probe. */
    private function verifyRedirect(MasjidDomain $domain, MasjidDomain $canonical): void
    {
        $result = $this->probe->redirects($domain, $canonical->host);
        $domain->last_checked_at = now();

        if ($result['matched']) {
            $domain->status = MasjidDomain::STATUS_MANUAL;
            $domain->verified_by = MasjidDomain::VERIFIED_BY_PROBE;
            $domain->verified_at = now();
            $domain->waiting_on = null;
            $domain->stage_started_at = null;
            $domain->last_error = null;
            $domain->next_check_at = null;

            return;
        }

        $domain->last_error = "Not redirecting to {$canonical->host} yet: {$result['seen']}";

        if ($domain->status !== MasjidDomain::STATUS_PROVISIONING) {
            return;
        }

        $since = $domain->stage_started_at ?? ($domain->stage_started_at = now());

        if ($since->lte(now()->subHours(self::CERTIFICATE_LIMIT_HOURS))) {
            $this->fail($domain, "{$domain->host} still did not answer 301 to {$canonical->host} "
                . self::CERTIFICATE_LIMIT_HOURS . " hours after its redirect rule was added (last: {$result['seen']}).");

            return;
        }

        $domain->next_check_at = $this->backoffFrom($since);
    }

    /**
     * Whether a zone found now is the one Studio's own earlier POST made, when
     * that POST's answer was lost (a timeout, or a 5xx after Cloudflare had
     * acted). The read before that POST found no zone, so a zone made no
     * earlier than the attempt is Studio's; one made before it is not. A zone
     * without created_on is counted as Studio's, since the absent read and the
     * POST are the evidence. With the cache cleared the zone reads as found,
     * which is the state before this marker existed.
     */
    private function madeByLostCreate(MasjidDomain $domain, CloudflareResult $zone): bool
    {
        $sentAt = Cache::get(self::ZONE_CREATE_SENT_KEY . $domain->id);

        if ($sentAt === null) {
            return false;
        }

        $createdOn = $zone->data['created_on'] ?? null;

        if (blank($createdOn)) {
            return true;
        }

        try {
            return Carbon::parse($createdOn)
                ->gte(Carbon::createFromTimestamp((int) $sentAt)->subMinutes(self::CLOCK_ALLOWANCE_MINUTES));
        } catch (\Throwable) {
            return true;
        }
    }

    /** Every five minutes for the first hour of a wait, then every half hour. */
    private function backoffFrom(?Carbon $since): Carbon
    {
        return $since !== null && $since->gt(now()->subHour())
            ? now()->addMinutes(5)
            : now()->addMinutes(30);
    }
}
