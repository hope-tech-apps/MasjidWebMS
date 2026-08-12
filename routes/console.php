<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune expired Sanctum personal access tokens daily so the
// personal_access_tokens table stays bounded (tokens expire after 8h via
// config/sanctum.php, but expired rows linger until pruned). Requires the
// system cron to run `php artisan schedule:run` every minute — see
// deploy/README.md.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Server-side prayer backstop: every minute, push adhan/iqama at prayer time to
// devices that have gone dark (no heartbeat > 5 days) so they never miss a
// reminder even if their local schedule lapsed. Active devices are excluded, so
// no duplicates. withoutOverlapping() guards against a slow run stacking up.
// Requires the same system cron running `php artisan schedule:run` every minute.
Schedule::command('prayers:send-due')->everyMinute()->withoutOverlapping();

// Daily silent "re-sync" nudge: wake all devices in the background to re-pull
// prayer times and re-arm their rolling notification window, so the buffer stays
// fresh even for users who don't open the app. 07:00 UTC ≈ pre-dawn US Eastern
// (after midnight local, before Fajr). Complements the 6-day buffer.
Schedule::command('prayers:daily-resync')->dailyAt('07:00');

/*
|--------------------------------------------------------------------------
| Retention and reclamation sweeps
|--------------------------------------------------------------------------
|
| Everything below was WRITTEN AND NEVER RUN. config/groups.php carries three
| `retention_days` windows (feed ~:29, messaging ~:65, behaviour ~:112), every
| row those windows govern is stamped with a `retained_until` on create, and
| `groups:purge-feed` sweeps all three tables — but no cron in this system ever
| invoked it, and each command's docblock deferred the cadence to "an operator
| decision" that was never made. The same was true of the expired-checkout
| reaper. A retention policy nothing executes is not a policy; it is a claim.
|
| These delete records ABOUT CHILDREN on a timer, so the schedule is written
| where the sweeps can be seen together rather than hidden in an operator
| runbook. Exposure is nil today (no row has yet reached a 365-day window),
| which is precisely why scheduling them now is safe: the first real deletion is
| a year out, and the sweep will have been running visibly, reporting zeros,
| the whole time.
|
| Both commands are idempotent (they select on a date and force-delete, so a
| second run in the same window finds nothing), both take `--dry-run`, both
| scope per masjid with `--masjid=`, and both log their counts — `schedule:run`
| discards stdout, so the log line is the only evidence a scheduled run leaves.
|
| Requires the same system cron running `php artisan schedule:run` every minute
| that the prayer tasks above already depend on — see deploy/README.md.
*/

// Group retention: feed posts (and their images on the private disk), messaging
// threads and behaviour awards past their `retained_until`. One sweep, because
// retention over a group's content is one policy rather than one per table —
// see PurgeGroupFeed, and note `hifz_entries` is excluded BY DESIGN.
//
// 03:10 UTC: after midnight in every timezone a tenant runs in, and off the
// 07:00 prayer resync so two long-running tasks never share a minute.
// withoutOverlapping() so a slow sweep on a large tenant cannot stack up and
// have two processes force-deleting the same rows.
Schedule::command('groups:purge-feed')->dailyAt('03:10')->withoutOverlapping();

// Seats held by pending registrations whose Stripe Checkout window expired
// without payment — the backstop for a `checkout.session.expired` that never
// arrived. Nothing is eligible until the grace margin has passed, so a tighter
// cadence would reclaim nothing sooner; fifteen minutes matches the default
// margin. withoutOverlapping() because two concurrent sweeps would contend on
// the same `lockForUpdate` rows for no benefit.
Schedule::command('registrations:reap-expired')->everyFifteenMinutes()->withoutOverlapping();

// Spent and expired family sign-in codes. Scheduled for exactly the reason the
// two sweeps above are: a retention policy that nothing executes is not a
// policy, it is a claim — and this table gains a row per sign-in REQUEST,
// forever, each carrying the requester's IP alongside an HMAC of the code.
//
// Behaviourally the rows are already inert (ContactLoginCode::scopeRedeemable
// filters on expires_at and consumed_at), so this is about not accumulating a
// growing record of which addresses tried to sign in to which organisation and
// from where. 03:25 UTC, clear of the 03:10 group sweep.
Schedule::command('family:prune-login-codes')->dailyAt('03:25')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Cross-tenant canary
|--------------------------------------------------------------------------
|
| Everything above this line deletes or sends. This one only LOOKS: it probes
| the running public API for the shape of the two cross-tenant holes that were
| live in production on 2026-08-11 (SearchableTrait's fail-open scope, and the
| gallery's unresolved tenant escaping as a 500). See App\Console\Commands\
| TenancyCanary for what each probe is and why it is read-only.
|
| WHY HOURLY, AND NOT EVERY FIVE MINUTES
|
| A tighter cadence buys nothing and costs something real. The exposure it
| watches for is introduced by a DEPLOY, not by traffic, and the last two
| instances were live for weeks and months respectively — the difference
| between finding one in five minutes and finding it in an hour is noise
| against that. Meanwhile /api/mobile is limited to 60 requests per minute per
| IP; a canary firing every five minutes takes a standing bite out of that
| bucket from whatever address it runs on, twelve times an hour, forever. The
| command already paces itself to a third of the bucket and rotates the mobile
| surface, and hourly keeps the whole arrangement to roughly one probe every
| three seconds for two or three minutes an hour.
|
| Deploys are the other half of the cadence and do not belong in a cron: the
| deploy script should run `php artisan tenancy:canary --all --json` after the
| release is live and fail the deploy on a non-zero exit. That is when a new
| hole actually appears; this schedule is the backstop for the one that appears
| some other way — a config change, a cache rule, a route added by a migration
| of somebody else's making.
|
| :47 so it never shares a minute with the quarter-hourly checkout reaper
| (:00/:15/:30/:45), the 03:10 group sweep, the 03:25 code prune or the 07:00
| prayer resync.
|
| withoutOverlapping(15) rather than the bare call the sweeps above use, and
| the deviation is deliberate: the bare form holds its lock for 24 hours, so a
| single killed run would silence the canary for a day. A watchdog whose
| failure mode is "stops watching, says nothing" is the failure mode it exists
| to prevent. Fifteen minutes comfortably exceeds a full --all run and expires
| well inside the hour.
|
| It exits non-zero on a finding AND on an incomplete run, so whatever watches
| cron exit codes is the alert path; the command also writes exactly one log
| line per run (info when clean, error with the reproducing curl when not),
| because schedule:run discards stdout and a canary you cannot prove ran is a
| canary that can stop running unnoticed.
*/
Schedule::command('tenancy:canary --json')->hourlyAt(47)->withoutOverlapping(15);
