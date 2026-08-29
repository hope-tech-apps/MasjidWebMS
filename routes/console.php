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
| THE ALERT CONTRACT — read this before wiring anything to it
|
| The command exits non-zero on a finding AND on any run that did not fully
| certify the platform, so whatever watches cron exit codes is one alert path.
| It is FOUR codes, not two:
|
|   0  clean       every planned endpoint reached, and the cross-tenant
|                  comparison worked on a majority of the graded surface
|   1  leak        a finding — page
|   2  incomplete  the run is not evidence about this platform (nothing
|                  reached, nothing verified, refused service, truncated past
|                  the coverage floor) — page
|   3  partial     the run IS evidence, about most of the platform, and it
|                  names what it could not see: an endpoint it never reached,
|                  or one it reached and could not compare two organisations
|                  on — ticket, not page
|
| For a SCHEDULED run the exit code is not the path that exists today, because
| schedule:run discards stdout and nothing here inspects the status. The LOG
| LINE is: exactly one per run, and its LEVEL carries the same four states, so
| an alerting rule can route on it without anyone editing this file —
|
|   info     clean
|   warning  partial          (pinned by TenancyCanaryTest::
|                              a_partial_run_logs_quieter_than_a_blocked_one)
|   error    leak, incomplete — with the reproducing curl for a leak
|
| That distinction is the whole point of the third code: one organisation
| missing an optional record, or two dormant organisations with nothing to
| compare, must not page at the same volume as an origin that serves nothing.
| An alarm that fires on an ordinary Tuesday gets silenced, and then the
| emergency is unheard. A canary you cannot prove ran is a canary that can stop
| running unnoticed, which is why the line is written on every run including a
| clean one.
|
| ROUTING WITHIN EXIT 3 — `degraded_by`
|
| Exit 3 covers two different facts, and the contract above says so: "an
| endpoint it never reached, or one it reached and could not compare two
| organisations on". Measured on 2026-08-13, they were indistinguishable to
| anything reading the level:
|
|   one unreachable UNGRADED /api/mobile endpoint         partial, exit 3, warning
|   every org dormant, comparison blind on 6 of 7 graded  partial, exit 3, warning
|
| and a `partial` run writes NOTHING to `errors` on purpose — that is what keeps
| it from reading like a blocked run — so the only discriminator left was
| re-deriving the coverage arithmetic in the alert rule.
|
| Both are still exit 3 and both still log `warning`, because both are tickets
| and the four states are the contract. What is new is that the log summary and
| the `--json` payload carry `degraded_by`, a list of reasons, so the rule can
| route on a field:
|
|   unreached_endpoints        an endpoint answered, but never 2xx for a real org
|   endpoints_not_probed       the run ended before it got there
|   truncated_request_budget   ) the budget cut the run short; `errors` carries
|   truncated_time_budget      ) the detail, and the coverage floor decides
|                              ) whether it was deep enough to be exit 2 instead
|   transport_error            a dead socket on at least one probe
|   comparison_floor           most of the graded surface it REACHED could not be
|                              compared between two organisations — the leak
|                              detector is largely asleep
|   row_ownership_dark         not one graded endpoint carried a row traceable to
|                              an owner, so a straight swap of two organisations'
|                              rows would read as clean
|   row_ownership_unplaced     a graded endpoint served real record ids that
|                              NOTHING could place — no canary.compare_by
|                              relation is named by the route or by the key path
|                              those ids sat under. Added 2026-08-13; see below
|
| The last three are about the canary's own eyesight. `comparison_floor` and
| `row_ownership_dark` want a ticket against the DATA (give the compared
| organisations content, or point `--tenants=` at two that have some);
| `row_ownership_unplaced` wants one line of CONFIG, and the payload names the
| endpoint, the bucket and the ids. The first four are about the platform or the
| run. Empty on every status other than `partial`.
|
| WHY `row_ownership_unplaced` EXISTS, AND WHY IT IS GRADED AT ZERO
|
| The row-ownership floor shipped as `attributed > 0`: one traceable graded
| endpoint and the floor held. Measured on this branch — a TOTAL SWAP served
| under a bucket no relation is named by, beside one correctly scoped sibling:
|
|   exit=0  clean  findings=0  degraded_by=null
|     answer-carries-the-requested-tenants-rows : skipped
|        "NOT TRACED: featured (ids 1, 2, 3) — no canary.compare_by relation…"
|     row_ownership: attributable 2, attributed 1, met TRUE
|
| Thirty of thirty-one graded endpoints could have been in that state and the run
| would still have logged `info` and exited 0. The check was honest and the
| verdict threw it away — the same defect one level up.
|
| It is NOT a majority of `attributed`, and that asymmetry is argued in full on
| TenancyCanary::applyOwnershipFloor(). Briefly: `unattributed` is two facts.
| An endpoint that carried NO ROWS (`/api/v1/settings`, a photoless gallery, an
| organisation whose announcements expired) can never be attributed and nobody
| can change that tonight — grading it is the permanent amber that gets a canary
| silenced. An endpoint that SERVED ROWS nothing could place is a hole with a
| one-line remedy that clears for good. The first is named and ungraded; the
| second costs the floor, at zero, because one is the whole of the swap fixture.
|
| WHAT `clean` STILL DOES NOT COVER — `coverage.route_table`
|
| Deliberately NOT a `degraded_by` reason, so read it even on a green run.
| `endpoints reached: 31/31` is a ratio over the PLAN, and the plan is not the
| route table.
|
| `coverage.routes_not_planned` has named the public GETs `ProbeCatalog` refuses
| — a parameter other than `{masjid_id}`, a limiter this canary may not spend,
| the `canary.skip` list — since 2026-08-13, and this comment used to end by
| saying "if this list grows, a public read endpoint was added that nothing will
| ever probe". THAT SENTENCE WAS FALSE, and the way it was false is the reason
| the field existed: it will not grow for a POST-shaped read, and it will not
| grow for an authenticated one. `ProbeCatalog::scan()` dropped both into
| NEITHER list, silently, and `canary.prefixes` excluded everything else before
| any predicate ran. Eight refused URIs read as the edge of the map and were 2%
| of it. Measured on this route table, 2026-08-13:
|
|   routes in the application                   364
|   probed by a `--all` run                      31
|   public GET refused (routes_not_planned)       8
|   write-verb routes under canary.prefixes      12   ALL unauthenticated; 7 on
|                                                     the graded api/v1 surface,
|                                                     several of them READS —
|                                                     offerings/{slug}/quote,
|                                                     zakat/calculate,
|                                                     registrations/{uuid}/checkout
|   authenticated GETs under canary.prefixes      0
|   outside canary.prefixes entirely            313   api/admin 288, api/family 14
|
| Those 302 admin and family routes are the admin tree and the parent portal.
| Every defect rounds one to three actually shipped — the teacher locked out of
| her classroom, the forked contact directory, the destroyed parent credential —
| lives there, and that is where a school's classrooms and children's records
| will be. Nothing in this command has ever looked at one of them.
|
| So `coverage.route_table` carries the whole census — counts and groups, never
| 313 URIs — and the human report prints it above the check table and again in
| the green verdict: "This run probed 31 of 364 routes in this application."
|
| It degrades nothing, for the same reason `routes_not_planned` does not: it is
| byte-identical on every run until somebody adds a route, and an alarm that
| fires every night for a condition no operator can act on tonight is the failure
| this whole design is a reaction to. What it IS good for is a diff, and now the
| diff is honest — it moves for a POST, for an authenticated route, and for a new
| prefix, which are the three ways the old field could not.
|
| AND WHAT NO COVERAGE CAN COVER — `coverage.detector_limits`
|
| Every field above says which ENDPOINTS were watched. None of them says which
| LEAKS are visible at all. Three constructions were measured on this branch
| scoring `exit 0 / clean / 0 findings / every check pass` against a live
| cross-tenant read: a scope that reads a `?masjid_id=` query parameter (the
| 2026-08-11 SearchableTrait shape one parameter over); a disclosure whose rows
| carry no primary key and no `masjid_id` (another school's parent names, emails
| and phone numbers, scoring the strongest verdict this command can issue); and a
| swap on any table outside `canary.compare_by`. `detector_limits` states all
| three, in the payload and in the human report, on every run including a clean
| one. They are not blind spots and they are not tickets; they are the size of
| the sentence `clean`.
*/
Schedule::command('tenancy:canary --json')->hourlyAt(47)->withoutOverlapping(15);

/*
|--------------------------------------------------------------------------
| Media estate verifier
|--------------------------------------------------------------------------
|
| The second thing here that only LOOKS. `tenancy:canary` watches whether one
| organisation can read another's content; this watches whether the content is
| still THERE. See App\Console\Commands\MediaVerify for what each question is
| and why it is read-only.
|
| WHAT IT IS FOR
|
| On 2026-08-21 Burlington's lobby television read "No announcements" while ten
| were live. The `media` table held 0 rows against an auto_increment of 422; the
| pre-deploy backup from four days earlier held 226 — 77 feature icons, 45
| announcement images, 25 logo records, 22 services, 20 avatars. Every masjid
| showed no logo in the apps and every announcement was withheld from the feed,
| for an unknown period.
|
| NOTHING DETECTED ANY OF IT. No alert, no failing check, no log anybody read.
| The first report was a photograph of a screen on a masjid wall, days later.
| This is the thing that was missing.
|
| WHY EVERY SIX HOURS, AND NOT DAILY OR HOURLY
|
| The drift is introduced by DEPLOYS, RESTORES and MANUAL MAINTENANCE — a
| database moved between machines without its files, a `media-library:clean`
| somebody ran by hand — not by traffic. Those are human-scale events, and they
| cluster in working hours rather than at 3am.
|
| That is the argument against DAILY. A single 04:00 sweep lets a lunchtime
| deploy sit broken until the next morning: eighteen hours of every organisation
| showing no logo, which is the incident again with a shorter clock. Six hours
| bounds it to six, and the cost is four runs a day of a check whose whole
| expense is one filesystem stat per media row plus one directory listing per
| disk — bounded by `--max-rows` / `--max-files`, on an estate whose last known
| good state was 226 rows and 56 folders.
|
| It is the argument against HOURLY too. Nothing here is rate-limited and the
| database cost is fixed, but the orphan walk is a recursive listing of the
| whole media disk, which grows with the estate rather than staying at 56
| folders, and a check that fires 24 times a day to catch an event that happens
| a few times a month is buying its last five hours of latency very expensively.
|
| The DEPLOY is the other half of the cadence and does not belong in a cron: the
| deploy script should run `php artisan media:verify --json` after the release is
| live and fail the deploy on exit >= 1. That is when the database and the files
| most often part company. This schedule is the backstop for the time they part
| company some other way.
|
| :17 past the hour, so it never shares a minute with the hourly canary (:47),
| the quarter-hourly checkout reaper (:00/:15/:30/:45), the 03:10 group sweep,
| the 03:25 code prune or the 07:00 prayer resync. Runs at 00:17, 06:17, 12:17,
| 18:17 UTC.
|
| withoutOverlapping(30) rather than the bare call, for the reason the canary
| deviates the same way: the bare form holds its lock for 24 hours, so one
| killed run would silence this for a day and four scheduled runs. A watchdog
| whose failure mode is "stops watching, says nothing" is the failure mode it
| exists to prevent. Thirty minutes comfortably exceeds a run on any estate this
| bound allows and expires well inside the six-hour gap.
|
| THE ALERT CONTRACT — read this before wiring anything to it
|
| `schedule:run` discards stdout and nothing here inspects the status, so for a
| SCHEDULED run the LOG LINE is the alert path, not the exit code. There is
| exactly one line per run including a clean one — a check nobody can prove ran
| is a check that can stop running unnoticed, which is precisely how this
| incident lasted as long as it did — and its LEVEL carries the verdict, so a
| rule can route on it without anyone editing this file:
|
|   exit  status      level     meaning                                  action
|   ----  ----------  --------  ---------------------------------------  ------
|    0    clean       info      every row VERIFIED has its file, every    none
|                               listed organisation has a logo that
|                               resolves, no collection shrank past the
|                               threshold, the serving link is intact
|    1    broken      error     a finding — any of: a dangling row; a     page
|                               listed organisation with no logo; a
|                               collection that lost more rows than
|                               ordinary editing explains; a row naming
|                               a disk that does not resolve; a broken
|                               `public/storage`
|    2    incomplete  error     the run is not evidence about this        page
|                               platform (no media table, no readable
|                               disk, not one row verified)
|    3    partial     warning   the run IS evidence, about most of the    ticket
|                               estate, and names what it did not see
|                               (`degraded_by`: truncated_row_budget,
|                               unreadable_disk, unverified_rows)
|    4    empty       critical  the `media` table holds NO ROWS while     page
|                               the platform still expects media
|
| VISITED IS NOT VERIFIED. `estate.rows_checked` counts rows the run looked at;
| `estate.rows_verified` counts rows whose file it actually determined the state
| of. A check with a gap between them reports `partial` and never `pass` — the
| recurring defect in this codebase is a surface announcing success about
| something it did not read, and a green report is worth nothing if `clean` can
| mean "I could not reach the disk".
|
| THE TWO DISK FAILURES ARE DIFFERENT EVENTS. A disk that does not RESOLVE has
| no driver in this application: every file behind a row naming it is unreachable
| by every code path, so those rows are broken references and the run is `broken`
| at exit 1. A disk that resolved and then THREW is a transient read failure and
| the run is `partial` at exit 3. The payload carries them under separate keys
| (`estate.unresolvable_disks`, `estate.unreadable_disks`) so a reader can check
| which one they have instead of trusting the verdict.
|
| Codes 0-3 are `tenancy:canary`'s exact vocabulary and levels, deliberately, so
| an on-call engineer holds one dialect rather than two. Code 4 is the addition,
| and it is the one this command exists for: folded into `broken`, an emptied
| table would be indistinguishable from a single broken thumbnail to a rule that
| never reads the body — and it is not a bigger version of a broken thumbnail,
| it is a different event with a different response. Stop, find out what deleted
| them, restore from backup. Do NOT run `media-library:clean`, which is what
| deletes dangling rows and is not scheduled anywhere in this application.
|
| THE RUN HAS A MEMORY, AND ONE THING ON-CALL CAN DO WITH IT
|
| A DELETED row leaves no trace. Every other question here is answered by looking
| at what is there; a removed row is simply absent, and absence is
| indistinguishable from "that content never existed". So the run keeps a census
| — one row count per `model_type` + `collection_name`, plus a platform total —
| at `storage/app/media-verify/baseline.json`, and compares each run against the
| last. Without it this command catches the wipe that HAPPENED (the table at
| exactly zero) and misses the same event minus one surviving row, and misses the
| likelier partial: the 45 announcement images deleted and the rest left alone.
|
| The file lives in `storage/app/` because that survives a `git checkout` into
| the live tree, survives `cache:clear` (a cleared cache is exactly the moment
| the baseline matters most), and is not in the database — a baseline kept in the
| table that got wiped is not independent evidence of what the table used to
| hold. Losing the file costs one run; the next one records a fresh baseline and
| says `census.baseline_source: none` rather than implying it compared. On a host
| with backups installed, the newest set's `media_integrity.rows` seeds the
| platform total in the meantime.
|
| Be precise about what that seed buys: the manifest records ONE number, so a
| seeded run can see the platform shrink and cannot see WHICH collection did.
| Per-collection cover returns only once a real baseline has been written — that
| is, on the second run after the file was lost. A seeded run says so in
| `census.baseline_source: manifest` rather than leaving a reader to assume the
| detail is there.
|
| A finding here does NOT clear itself when the next run sees the smaller number.
| The baseline is HELD at its previous value and the page repeats every six hours
| until the rows come back or somebody accepts the loss. That is deliberate: a
| watchdog that barks once is a watchdog somebody slept through, and this
| incident went unnoticed for days.
|
| So there is one gesture on-call may need, and it is the only thing in this
| command that writes anything:
|
|     php artisan media:verify --accept-baseline
|
| It records the current census as the new normal and clears the hold. Use it
| when the deletion was DELIBERATE — a tenant offboarded, a gallery retired.
| Reaching for it to silence a page nobody has explained defeats the detector
| entirely, and it touches no media row and no media file either way.
|
| The threshold is argued in config/media-verify.php: a drop must be at least 10
| rows AND at least half the collection, or take a collection to exactly zero
| from 5 or more. Ordinary editing does not clear that bar, which is the point —
| an alarm that fires on an ordinary Tuesday gets silenced, and then the
| emergency is unheard.
|
| WHAT IS DELIBERATELY NOT AN ALERT: ORPHAN FILES
|
| Unreferenced bytes on the media disk are counted, sized, printed above the
| check table and carried in the log summary of every run — and they move no
| verdict and fire no page. They break nothing for anybody: no request 404s, no
| app renders a hole. Graded, they would be a permanent amber on any estate with
| history, and an alarm that fires on an ordinary Tuesday gets silenced, and
| then the emergency is unheard. They are reported because they are what
| `media-library:clean` deletes, and an operator should see that number BEFORE
| anybody runs it rather than after.
|
| AND ONE THING THAT IS AN ALERT AND LOOKS LIKE NOTHING: THE SERVING LINK
|
| `storage/app/public` is reachable over HTTP only because `public/storage`
| points at it. Break that link — a deploy into a fresh tree without
| `storage:link`, a release directory replaced — and every media URL 404s while
| every row and every file is perfectly intact. Every other check here goes green
| about images nobody can load. It is exit 1, the repair is `php artisan
| storage:link`, and nothing needs restoring. On a host whose media is not served
| that way the check reports `skipped` with the reason rather than guessing.
|
| Requires the same system cron running `php artisan schedule:run` every minute
| that everything above already depends on — see deploy/README.md.
*/
Schedule::command('media:verify --json')->cron('17 */6 * * *')->withoutOverlapping(30);

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| The database AND the media files its rows point at, as one set that can only
| be restored together. See App\Console\Commands\BackupRun and bin/backup — the
| latter carries the full statement of what this does not cover.
|
| WHY IT IS SCHEDULED AT ALL, GIVEN NOTHING ELSE HERE WRITES GIGABYTES
|
| The four backups that existed on 2026-08-17 were taken BY HAND, before
| deploys, and named after them: pre-forms-migration, pre-manara-verticals,
| pre-family-sms, pre-r8. That is a backup of the thing an operator was afraid
| of. It is not a backup of the thing that actually happened — 226 media rows
| disappearing on some ordinary day, discovered days later from a photograph of
| a television. A backup taken only when somebody is nervous cannot catch that,
| so this runs whether anybody is nervous or not.
|
| WHAT IT COSTS, MEASURED 2026-08-21 ON THIS DROPLET
|
|   root filesystem              48G total, 6.4G used, 42G free
|   database dump, gzipped       ~1.3 MB   (the four in /root/backups)
|   media disk                   11 MB in 91 files
|   => one set                   ~12.5 MB, and BACKUP_KEEP_SETS=14 is ~175 MB
|
| 0.4% of free space. RETENTION IS BOUNDED BY A COUNT, not a duration, because a
| count is a hard ceiling on bytes and a duration is not; pruning happens only
| after a new set is written and VERIFIED, and never takes the last one. If the
| volume cannot take another set, `backup:run` refuses the run before writing
| anything and logs at `error` — it does not part-write and it does not delete an
| old set to make room for one that has not succeeded yet.
|
| 02:40 UTC: clear of the 03:10 group sweep, the 03:25 code prune, the 07:00
| prayer resync and the :47 canary, and before the sweeps rather than after so a
| day's set is taken BEFORE that day's deletions rather than after them.
|
| withoutOverlapping(60) rather than the bare call, for the reason the canary
| gives: the bare form holds its lock for 24 hours, so one killed run would
| silence backups for a day. Sixty minutes comfortably exceeds a run of this
| size and expires well inside the daily gap.
|
| THE ALERT CONTRACT. schedule:run discards stdout, so the LOG LINE is the only
| evidence a scheduled run leaves. Exactly one per run, including a clean one,
| because a backup that quietly stops happening looks exactly like a backup that
| is fine — which is how the media loss went unnoticed for days:
|
|   info     a complete set was written and verified                  (exit 0)
|   warning  a complete set was written and verified, and the media it captured
|            has rows whose files are missing — the backup is sound, the data in
|            it is not (ticket, not page)                            (exit 2)
|   warning  a complete set was written and verified, and it captured markedly
|            fewer media FILES than the set it would have replaced, so RETENTION
|            WAS SKIPPED and nothing was deleted. The older sets are the only
|            copies of what this one no longer holds — look at the media disk
|            before the next run.                                    (exit 3)
|   error    no set was written: the media disk was unreachable, the destination
|            was unwritable, the volume was too full, a media row named a disk
|            this set does not archive, the media root had no files left, or a
|            half failed to verify                                   (exit 1)
|
| A file with no row is deliberately NOT graded — this disk carries 68
| directories of stock seed art that will never have rows, and an amber that
| burns every ordinary night is an amber that gets silenced.
|
| INERT UNTIL THE DESTINATION EXISTS. It writes to config('backup.destination'),
| /var/backups/manara, which must exist and be owned by www-data because that is
| who this cron runs as. `sudo bin/backup --install` creates it. Until then every
| run refuses with an `error` line naming that command — loudly, rather than
| appearing to work.
*/
Schedule::command('backup:run')->dailyAt('02:40')->withoutOverlapping(60);
