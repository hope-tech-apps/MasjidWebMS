# Turning on Host enforcement

A proposal, not a runbook step. **Nothing here has been applied.** Every command
below touches a production `.env`, which is where this codebase has historically
produced total outages: a bad `.env` plus `config:cache` makes every request 500,
and the error blames `APP_KEY` rather than the file. It needs the owner's yes and
its own window with someone watching — not a ride-along on another deploy.

## Where this stands (2026-09-17)

The owner's decision, verbatim: **"Ship log-only + full list."** Fix the URL
poisoning now; LOG unknown hosts without blocking them; enforce only later,
after a week of clean logs, as a **separate** decision. And: "Document the
tenant host map" — that is `docs/tenant-host-map.md`, which is where the host
list below comes from and where the evidence for each host is recorded.

### The production setting to apply with this release

```
TRUSTED_HOSTS=manara.hopetechapps.com
```

and **nothing else** — `TRUSTED_HOSTS_ENFORCE` stays absent (absent means
`false`, which is the shipped default and is pinned by
`TrustedHostsLogOnlyTest::the_shipped_default_is_log_only`). Production's `.env`
has no `TRUSTED_HOSTS*` key today (read 2026-09-17), so this is one added line.

Why that one name and no other: the middleware already admits `APP_URL`'s host
(`masjid.hopetechapps.com`) and every `PORTAL_HOSTS` key
(`portal.alrazischool.org`). `manara.hopetechapps.com` is the only other name
proven to reach this app — a proxied A record served purely by nginx's
`default_server` — and it is named in no setting. Every other Manara hostname
(Burlington, BISS, the `*.manara` tenant sites, MEC's `mec-web.pages.dev`,
Al-Razi's own site, the parent guide) is answered by Cloudflare Pages or a
Worker and reaches Laravel only as `masjid.hopetechapps.com`. Do **not** add the
droplet's IP addresses or any of the foreign hostnames the log will show.

Applying it is optional for the release itself — the release is safe without
it, because nothing is refused — but until it is set, `manara.hopetechapps.com`
writes one warning an hour and hides among the junk the log is meant to be read
for. Staging's equivalent is `TRUSTED_HOSTS=manara-staging.hopetechapps.com`
(now in `deploy/staging/env.staging.example`).

## What already shipped, and needs none of this

`App\Support\SiteUrl` pins every URL that outlives its request to
`config('app.url')`: the five cached mobile payloads that carry media
placeholders, the app menu's `deletion_page_url`, the account-deletion and
unsubscribe form actions, the emailed unsubscribe links, the stored lunch flyer
URL, the provisioning runner's `callback_url`, and the Stripe onboarding
return/refresh URLs. That half is unconditional and has no blast radius: it
removes the request from the URL and cannot refuse anybody.
`tests/Feature/HostHeaderUrlIntegrityTest.php` drives each of them with a forged
Host.

`App\Http\Middleware\TrustedHosts` ships **observing**. It logs an unknown Host
at `warning` — production's `LOG_LEVEL`, so the line is kept; a quieter level
would be discarded before it reached the file — and passes the request through.
Neither a cache failure while rate-limiting the line nor a log file that cannot
be written fails the request. `tests/Feature/TrustedHostsLogOnlyTest.php` pins
all of it: the default, the level (through a real file channel), the cache
failure, and the unwritable log. Everything below is about the second step —
making it refuse.

## The hosts each box actually serves

Read from the boxes on 2026-09-15 and re-derived in full on 2026-09-17 — the
complete map, including every hostname that does NOT reach Laravel and why, is
`docs/tenant-host-map.md`. The middleware assembles its list from `APP_URL`'s
host + every `PORTAL_HOSTS` key + `TRUSTED_HOSTS`, so two of the three are
already correct and only the third is missing.

### Production — 159.65.239.51

| host | how it is served | on the list today? |
|---|---|---|
| `masjid.hopetechapps.com` | own `server_name`, also `default_server` | yes, from `APP_URL` |
| `portal.alrazischool.org` | own vhost | yes, from `PORTAL_HOSTS=portal.alrazischool.org=14` |
| `manara.hopetechapps.com` | **`default_server` only** — named in no vhost and no setting | **NO** |

`manara.hopetechapps.com` reaches the app only because the masjid vhost is
`default_server`. Not all of it does: the Cloudflare Worker `manara-marketing` owns
four exact paths, `/`, `/masjids`, `/schools` and `/community`, and answers them
without calling the origin. Everything else under that hostname is Laravel: the
sign-in page `/auth/sign-in`, the admin SPA, `/api/*`, `/up`. You can tell them
apart by the headers (checked 2026-09-17). `/` returns 200 with no
`x-frame-options` and no `cf-cache-status`. `/auth/sign-in` and `/up` return 200
with `x-frame-options: DENY` and `cf-cache-status: DYNAMIC`. That header comes
only from this app's `SecurityHeaders`: production nginx adds
`X-Content-Type-Options` (and `Access-Control-Allow-Origin` on two locations)
but never `X-Frame-Options`, and the Worker sets neither. (`/masjids/`, with a
trailing slash, is Laravel's too: the Worker's routes match the four paths
exactly.) **Enforcing
without adding the hostname makes every Laravel path under it a 400, including
sign-in, while `/` keeps answering 200.**

This is no longer a prediction. The branch was deployed to staging on 2026-09-15
in report-only mode, and the middleware logged the staging twin of exactly this
gap on an ordinary sign-in request:

```
staging.WARNING: Request carried a Host header this deployment does not serve.
{"host":"manara-staging.hopetechapps.com",
 "allowed":["masjid-staging.hopetechapps.com","portal-staging.hopetechapps.com"],
 "enforced":false,"path":"auth/sign-in"}
```

The box serves three hostnames; the assembled list knew two. That single line is
the entire argument for why observing mode exists — it found the gap in minutes,
on a real request, without refusing anybody.

### Staging — 157.230.212.38

Same shape, same gap: `APP_URL=https://masjid-staging.hopetechapps.com` and
`PORTAL_HOSTS=portal-staging.hopetechapps.com=14` cover two of the three;
`manara-staging.hopetechapps.com` is in the vhost's `server_name` but in no
application setting.

## What will NOT break, checked rather than assumed

- **No cron reaches the app over HTTP.** The only crontab entry on either box is
  `php artisan schedule:run`, which is CLI — no Host header exists.
- **The canary uses a hostname, not an IP.** Staging sets
  `CANARY_BASE_URL=https://masjid-staging.hopetechapps.com`; production leaves it
  unset, so it falls back to `config('app.url')`. Both are on the list.
- **The proxied organisation domains are redirects, not rewrites.**
  `www.burlingtonmasjid.com/jummah-lunch/1` 307s to
  `masjid.hopetechapps.com/jummah-lunch/1`, and `alrazischool.org/portal` 307s to
  `portal.alrazischool.org/portal`. The Host that arrives here is ours either way.

**The one unknown is external monitoring.** Anything that reaches the origin by
IP — DigitalOcean's own checks, an uptime service, a load balancer health probe —
sends a Host nobody wrote down. That is precisely what the observing mode is for,
and it is why step 2 below is "read the log", not "wait a bit".

## The log will never be empty — so "zero warnings" is the wrong test

nginx's error log (the only nginx log that records the Host header) shows
**58 distinct Host values** reaching the production origin in the 14 days to
2026-09-17. Four are ours (`masjid.hopetechapps.com`, `portal.alrazischool.org`,
`manara.hopetechapps.com`, the last only because nobody has listed it). The rest
are the droplet's two IP addresses — with and without `:443`, some 20,000 lines
of dotfile scans — and about fifty **third-party hostnames whose DNS still points
at our reserved IP** (`promocao.energisaprev.com.br`, `*.idplugger.com`, …).

Every one of those will be logged, once an hour each: expect in the order of a
thousand lines a day. A week of **zero** warnings will not happen with this
traffic, and waiting for it would postpone enforcement forever. The criterion
that means what the owner meant is:

> **Seven consecutive days in which no warning names a hostname in a zone we
> own**, and no warning shows a request we recognise (a monitor, a webhook, a
> partner) arriving by IP.

## The order, and why it is this order

Each step is separately reversible, and no step depends on a later one.

**1. Add the host to `.env` on both boxes. Enforcement stays off.**

```
TRUSTED_HOSTS=manara.hopetechapps.com          # production
TRUSTED_HOSTS=manara-staging.hopetechapps.com  # staging
```

(Production: this is the same line as "The production setting to apply with
this release" above — if it went on with the release, step 1 is already done.)

Write it **through the inode** — `cat >` or an editor, never `mv` a new file over
it as root. Moving a file over `.env` replaces the inode with one owned by root
and unreadable to `www-data`; `config:cache` then fails and the 500 blames
`APP_KEY`. Then `php artisan config:cache` and confirm the site still answers.

Adding the host while enforcement is off changes **nothing observable**. That is
the point: it is a free step that removes the only known way step 3 can break the
site, and if something else goes wrong it is trivially attributable.

**2. Leave it observing, and read the log.**

Production logs to `storage/logs/laravel.log` (`LOG_STACK=single`) and the file
is rotated daily into `laravel.log.N.gz`, so read it with `zgrep`, which takes
the rotated files and the live one alike:

```
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
M="Host header this deployment does not serve"

# every host reported, most frequent first
zgrep -h "$M" storage/logs/laravel.log* | grep -o '"host":"[^"]*"' | sort | uniq -c | sort -rn

# THE GATE: warnings naming a hostname in a zone we own. Must print nothing
# for seven consecutive days before step 3.
zgrep -h "$M" storage/logs/laravel.log* \
  | grep -oE '"host":"([a-z0-9-]+\.)*(hopetechapps\.com|alrazischool\.org|burlingtonmasjid\.com|al-aqsaclinic\.org|joinwird\.com|tapcraft\.tech|mizanfintech\.app|aiinnovation\.dev)"' \
  | sort | uniq -c

# requests by IP literal: look at the path and client IP, not the count
zgrep -h "$M" storage/logs/laravel.log* | grep -E '"host":"[0-9.]+"' | grep -oE '"path":"[^"]*","method":"[A-Z]+","ip":"[^"]*"' | sort | uniq -c | sort -rn | head -30
```

Each line carries the host, the path, the method and the IP. Repeats of the same
host are rate-limited to one line an hour, so the count is hosts-over-time, not
requests. Read for **at least a full day** so a daily monitor or a nightly job
gets a chance to appear, and for **seven days** before enforcing.

The Host is the caller's choice, so the log is also capped: at most
`TRUSTED_HOSTS_LOG_BUDGET` (default 200) **new** hostnames an hour. The first
one over the cap writes a single line starting `Unknown-Host logging paused`
and naming it as `first_unlogged_host`; nothing more is written until the hour
ends. **A paused hour is an hour the log cannot vouch for** — a hostname of ours
could have arrived after the cap. If the pause check below prints anything
inside the seven days, read nginx's error log for that hour (it records every
Host nginx logged an error for) or restart the clock.

Three outcomes:

- Only junk — scanner noise, raw IPs probing dotfiles, the foreign domains in
  `docs/tenant-host-map.md` §1. That is the expected steady state. Proceed.
- A hostname in one of our zones. **Stop and add it to `TRUSTED_HOSTS` first**
  (and a row to `docs/tenant-host-map.md`), then restart the clock. This is the
  step doing its job; it is not a delay.
- An IP-literal request you recognise — `/up` or an API path from a fixed
  client IP every few minutes is the tell of a monitor. Point that monitor at a
  hostname before enforcing; do not add IP addresses to the list.

**3. Only then, enforce.**

```
TRUSTED_HOSTS_ENFORCE=true
```

`php artisan config:cache`, then verify by hand:

```
for u in https://masjid.hopetechapps.com/up \
         https://manara.hopetechapps.com/auth/sign-in \
         https://portal.alrazischool.org/portal; do
  printf '%s  ' "$u"
  curl -s -o /dev/null -D - "$u" | tr -d '\r' | grep -iE '^(HTTP/|x-frame-options:)' | tr '\n' ' '
  echo
done
# every line must show a 200 status AND x-frame-options: DENY (either letter case;
# masjid and portal answer over HTTP/1.1 with capitalised header names)
```

Each URL must be answered **by Laravel**, or the check cannot fail. Do not use
`https://manara.hopetechapps.com/`: the `manara-marketing` Worker answers that
exact path itself, so it returns 200 even when Laravel refuses the hostname.
`x-frame-options: DENY` comes only from this app's `SecurityHeaders`: the
Worker's pages do not carry it, and production nginx never adds it. A refusal is
a 400, so it fails on the status alone. So "200 plus that header" means Laravel
admitted the host. All three URLs showed exactly that on 2026-09-17, and
`https://manara.hopetechapps.com/` showed 200 with no such header.

### The forged-Host check must go STRAIGHT TO THE ORIGIN

This is the step most likely to lie to you, and it lied on staging first.

```
# WRONG on any PROXIED hostname — Cloudflare answers this itself
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: evil.example' \
     https://masjid-staging.hopetechapps.com/account-deletion       # 403 from the EDGE

# RIGHT — bypass the edge, ask the origin directly
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: evil.example' \
     https://<origin-ip>/account-deletion                            # 400 from the APP
```

A proxied hostname never lets a forged Host reach PHP: Cloudflare refuses it at
the edge with a **403** carrying `server: cloudflare` and none of this app's
headers. That looks like success and is not — it would pass identically against
an application with no middleware at all, which is the same class of vacuous
check as asserting on a `Host:` header the test client discarded.

Tell the two apart by the status code and the headers: **403 + `server:
cloudflare`** is the edge, **400** with this app's usual headers is the
middleware. `-k` is needed because the origin's certificate is for a hostname you
are deliberately not using.

Production's `masjid.hopetechapps.com` is a **DNS-only** A record, so the public
form of this command does reach the origin there — but do not rely on that
distinction from memory. Go to the IP on both boxes and the check means the same
thing on each.

Rollback is one line — `TRUSTED_HOSTS_ENFORCE=false` plus `config:cache` — and it
needs no deploy, because the flag is config and not code.

### Already done on staging, 2026-09-15, in report-only mode

Deployed at `263968a` and verified against the origin directly:

- All three staging hostnames answered **200** — nothing refused, as designed.
- Forged Host: **200**, form action on `masjid-staging.hopetechapps.com`, and
  **zero** occurrences of the forged host anywhere in the body.
- **Exactly one** log line naming the forged host.
- The placeholder payloads that actually exercise the change — `services/1` (44
  URL fields) and `services/5` (32) — entirely on the staging host with zero
  nulls. `announcements/1` and `services/13` carry nulls in `preview_url` only,
  never `original_url`, identical before and after: pre-existing, not this change.
  (`original_url` is the field the Flutter clients force-unwrap; it is null
  nowhere.)
- `/features` was unchanged at 33/33 — and proves nothing here, because every
  feature has icon media so the placeholder path is unreachable in that payload.

What remains unverified on a box is enforcement itself, which is steps 1-3 above.

**Staging first, and a full day there, before production.** Nothing in this
repository reaches production without being on staging, and this change's whole
failure mode is a host nobody remembered.

## nginx

Unchanged, and deliberately so. Both boxes keep `default_server` on `:80` and
`:443`. Removing it would make an unknown Host fail at nginx instead of in PHP,
which is a stronger boundary — but it would also take
`manara.hopetechapps.com` down immediately, since that hostname has no
`server_name` of its own and is served *by* the default vhost. If the owner wants
that boundary, the order is: give manara its own `server_name`, verify, and only
then reconsider `default_server`. That is a separate change with a separate
window, and it is not required for any of the above.
