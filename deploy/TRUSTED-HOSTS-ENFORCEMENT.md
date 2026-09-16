# Turning on Host enforcement

A proposal, not a runbook step. **Nothing here has been applied.** Every command
below touches a production `.env`, which is where this codebase has historically
produced total outages: a bad `.env` plus `config:cache` makes every request 500,
and the error blames `APP_KEY` rather than the file. It needs the owner's yes and
its own window with someone watching — not a ride-along on another deploy.

## What already shipped, and needs none of this

`App\Support\SiteUrl` pins the cached payloads, the account-deletion and
unsubscribe form actions, and the lunch flyer URL to `config('app.url')`. That
half is unconditional and has no blast radius: it removes the request from the
URL, it cannot refuse anybody, and it is already proven by 19 tests.

`App\Http\Middleware\TrustedHosts` ships **observing**. It logs an unknown Host
at `warning` and passes the request through. Everything below is about the second
step — making it refuse.

## The hosts each box actually serves

Read from the boxes on 2026-09-15. The middleware assembles its list from
`APP_URL`'s host + every `PORTAL_HOSTS` key + `TRUSTED_HOSTS`, so two of the
three are already correct and only the third is missing.

### Production — 159.65.239.51

| host | how it is served | on the list today? |
|---|---|---|
| `masjid.hopetechapps.com` | own `server_name`, also `default_server` | yes, from `APP_URL` |
| `portal.alrazischool.org` | own vhost | yes, from `PORTAL_HOSTS=portal.alrazischool.org=14` |
| `manara.hopetechapps.com` | **`default_server` only** — named in no vhost and no setting | **NO** |

`manara.hopetechapps.com` answers 200 today and serves the admin SPA. It reaches
the app purely because the masjid vhost is `default_server`. **Enforcing without
adding it makes every request to that hostname a 400.**

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

## The order, and why it is this order

Each step is separately reversible, and no step depends on a later one.

**1. Add the host to `.env` on both boxes. Enforcement stays off.**

```
TRUSTED_HOSTS=manara.hopetechapps.com          # production
TRUSTED_HOSTS=manara-staging.hopetechapps.com  # staging
```

Write it **through the inode** — `cat >` or an editor, never `mv` a new file over
it as root. Moving a file over `.env` replaces the inode with one owned by root
and unreadable to `www-data`; `config:cache` then fails and the 500 blames
`APP_KEY`. Then `php artisan config:cache` and confirm the site still answers.

Adding the host while enforcement is off changes **nothing observable**. That is
the point: it is a free step that removes the only known way step 3 can break the
site, and if something else goes wrong it is trivially attributable.

**2. Leave it observing, and read the log.**

```
grep -c "Host header this deployment does not serve" storage/logs/laravel.log
grep    "Host header this deployment does not serve" storage/logs/laravel.log | tail -40
```

Each line carries the host, the path, the method and the IP. Repeats of the same
host are rate-limited to one line an hour, so the count is hosts-over-time, not
requests. Read for **at least a full day** so a daily monitor or a nightly job
gets a chance to appear.

Two outcomes:

- Only junk — scanner noise, raw IPs, random domains. Proceed.
- A hostname you recognise. **Stop and add it to `TRUSTED_HOSTS` first**, then
  restart the clock. This is the step doing its job; it is not a delay.

**3. Only then, enforce.**

```
TRUSTED_HOSTS_ENFORCE=true
```

`php artisan config:cache`, then verify by hand:

```
curl -s -o /dev/null -w '%{http_code}\n' https://masjid.hopetechapps.com/up          # 200
curl -s -o /dev/null -w '%{http_code}\n' https://manara.hopetechapps.com/            # 200
curl -s -o /dev/null -w '%{http_code}\n' https://portal.alrazischool.org/portal      # 200
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: evil.example' \
     https://masjid.hopetechapps.com/account-deletion                                 # 400
```

Rollback is one line — `TRUSTED_HOSTS_ENFORCE=false` plus `config:cache` — and it
needs no deploy, because the flag is config and not code.

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
