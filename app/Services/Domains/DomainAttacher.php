<?php

namespace App\Services\Domains;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
 *  - `reserved` rows are never advanced at all: no probe, no read, no write
 *    (R4). They hold a live tenant's host without trusting it.
 *  - `imported` rows and `manual` rows, when there is a token, are promoted by
 *    READS only: if Cloudflare's Pages project already lists the host as
 *    active, the row becomes `active`. Nothing is created, changed or retried
 *    for them, whatever Cloudflare says, and they are never failed.
 *  - `failed` rows are left alone; "Check now" resets one to pending first
 *    (restart()), which is also the only way forward for a failed row that
 *    Cloudflare holds records for, since Studio cannot delete that row (R28).
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
 * `serving_confirmed_at` is set by every probe match and never cleared by a
 * later miss in W1 (plan §6).
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

    /** Per row: when a zone POST went out whose answer never came back. */
    private const ZONE_CREATE_SENT_KEY = 'masjid-domain:zone-create-sent:';

    /** Allowance between this server's clock and Cloudflare's created_on. */
    private const CLOCK_ALLOWANCE_MINUTES = 5;

    private const PAGES_FAILED = ['error', 'blocked', 'deactivated'];

    private const ZONE_WAITING = ['initializing', 'pending'];

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
            $this->restartIfFailed($domain);
            $this->step($domain);
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

            $this->step($domain);
        } finally {
            $lock->release();
        }

        return $domain;
    }

    private function step(MasjidDomain $domain): void
    {
        if (in_array($domain->status, [MasjidDomain::STATUS_RESERVED, MasjidDomain::STATUS_FAILED], true)) {
            return;
        }

        $domain->last_checked_at = now();

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
        if ($domain->serving_confirmed_at === null) {
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
            $domain->cf_pages_domain_id = $pages->data['id'] ?: $domain->cf_pages_domain_id;
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
            $domain->cf_pages_domain_id = $pages->data['id'] ?: $domain->cf_pages_domain_id;
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

        $domain->cf_zone_id = $zoneId;
        $domain->cf_dns_record_id = $cname->data['id'] ?: $domain->cf_dns_record_id;

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

        $domain->cf_pages_domain_id = $pages->data['id'] ?: $domain->cf_pages_domain_id;
        $domain->status = MasjidDomain::STATUS_PROVISIONING;
        $domain->waiting_on = 'certificate';
        $domain->stage_started_at = now();
        $domain->last_error = null;
        $domain->next_check_at = now()->addMinutes(5);
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
     * A miss after a match writes nothing: the confirmation stands in W1.
     */
    private function runProbe(MasjidDomain $domain): void
    {
        $result = $this->probe->confirm($domain);

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
