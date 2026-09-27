# Runbook: the renderer's Pages custom-domain ceiling

Every client website Studio attaches is a **custom domain on one Cloudflare
Pages project**, `manara-renderer` (`config('cloudflare.pages_project')`).
Cloudflare caps custom domains **per project**: 100 on Free, 250 on Pro, 500 on
Business (developers.cloudflare.com/pages/platform/limits, "Last updated Sep 5,
2026"). At the ceiling Studio adds nothing: a new host waits as `pending` with
`waiting_on = capacity`, and the attacher tries it again every hour
(`App\Services\Domains\DomainAttacher::attach`). The client's site is not
live until a slot frees.

## How you hear about it

`php artisan domains:capacity` runs daily at 07:17 UTC (`routes/console.php`)
and writes one line to the `monitors` channel, so every run is in
`storage/logs/monitors.log`. It emails `OPS_ALERT_EMAIL` when:

- the project first reaches **50, 70, 85 and 95 percent** of the ceiling (one
  email per threshold; `cloudflare.pages_domain_notice_at`);
- **any host is waiting on capacity**. This email repeats every day until the
  host gets a slot, because a client is waiting.

Run it by hand for the figures:

```bash
php artisan domains:capacity --json
```

`source` says where `used` came from: `cloudflare` (one GET of the project's
domain list, with `CLOUDFLARE_STUDIO_TOKEN`) or `rows_estimate` (the
`masjid_domains` serving rows that hold or are acquiring a slot, without the
token or when the GET failed; `cloudflare_error` then says why).
`clients_left_estimate` is an **estimate**: it assumes one slot per new client,
because since W2 S5 a client's second host is a redirect rule, not a custom
domain. Clients attached before S5 may still hold two until step 1 below.

### What counts toward the ceiling

- **The live hosts count.** When Studio was designed the project already held
  five: `burlingtonmasjid.com`, `www.burlingtonmasjid.com`,
  `sundayschool.burlingtonmasjid.com`, `mec.manara.hopetechapps.com` and
  `alrazi.manara.hopetechapps.com` (`docs/manara-studio.md` D17).
- **Reserved rows do not.** A `reserved` row (for example `meccharlotte.org`,
  held for MEC until its zone comes to Cloudflare) holds a host for an
  organisation in our table; it is not a custom domain on the project.
- **A trashed organisation's hosts still count.** Trashing an organisation
  stops the lookup from answering for its hosts, but it leaves Cloudflare
  untouched, so its Pages domains still hold slots.

## Freeing slots, in the order to try

1. **Collapse two-host clients to one Pages domain plus a redirect.** The
   canonical host (`www`, owner decision 2026-09-24) stays a custom domain; the
   other becomes a Cloudflare redirect rule and uses no slot. This frees one
   slot per two-host client and changes nothing a visitor sees. Tool:
   `php artisan domains:collapse-alias {domain_id}` (W2 S5; dry run unless
   `--execute`). It needs the token's redirect scope (Zone › Single Redirect:
   Edit) and writes only in a zone Studio created or one listed in
   `cloudflare.redirect_zones`; live clients attached before Studio (imported
   rows) are refused.
2. **Detach the hosts of trashed or departed organisations**, each with the
   owner's go. Tool: `php artisan domains:release {masjid_id}` (W2 S3; dry run
   unless `--execute`). It removes only the Cloudflare objects Studio itself
   created. Until S3 ships, remove them by hand with the steps the Studio
   domain screen shows for that row (`MasjidDomain::removalSteps()`), then ask
   the platform owner to remove the row.
3. **Upgrade the Cloudflare plan.** Pro allows 250 custom domains per project.
   This is a cost decision for the owner. After the upgrade, set
   `CLOUDFLARE_PAGES_DOMAIN_CEILING=250` in production's `.env` (parse-check it,
   then `config:cache`). No code change is needed.
4. **Shard.** A second Pages project deployed from the same renderer build,
   with `config('cloudflare.pages_project')` becoming a per-row value. That is
   a separate slice with its own renderer byte-identity check.
5. **Cloudflare for SaaS custom hostnames**, the spec's long-term answer
   (`docs/manara-studio.md`, "The ceiling to know about"). That is a design of
   its own.

## After a slot frees

Nothing to do: the attacher retries a host waiting on capacity every hour
(`domains:reconcile`, every five minutes, picks it up when it is due). The next
`domains:capacity` run stops sending the "waiting" email once no row waits.
