# Environments

There are two Manara servers that matter and one that must go. Nothing in this
project is safe to reason about until you know which one you are looking at,
because **all three answer any Host header and, historically, all three could
read production's database.** A full Stripe go-live once landed on the wrong box
and looked healthy for an hour (`NOTES.md`, 2026-08-10).

## The table

| | PRODUCTION | STAGING | STALE — destroy |
|---|---|---|---|
| droplet | 586894889 `masjid-backend-24-04` | `masjid-staging` (created from snapshot 244822223) | 480119186 `masjid-backend-service` |
| IPs | 159.65.239.51, reserved **164.90.253.138**, private 10.116.0.4 | see `deploy/staging/host` / `$STAGING_IP` | 147.182.210.42 |
| hosts | `masjid.hopetechapps.com` (DNS-only A), `manara.hopetechapps.com` (proxied), `portal.alrazischool.org` (DNS-only) | `masjid-staging.`, `manara-staging.`, `portal-staging.hopetechapps.com` — all **proxied** | none |
| `APP_ENV` | `production` | `staging` | `production` (!) |
| database | managed cluster `db-mysql-nyc1-51565`, schema `masjids_app_management_system_db`, MySQL 8.4, port 25060 | **local** MySQL 8.0 on the box, `masjids_staging`, 127.0.0.1:3306 | the **production** cluster and schema |
| `LOG_LEVEL` | `warning` | `debug` | `debug` |
| mail | Resend, real | `log` | Resend, real |
| Stripe | `sk_live_` | `sk_test_` | blank |
| TLS | Let's Encrypt on the origin (certbot) | Cloudflare Universal SSL at the edge; origin keeps prod's copied cert; **no certbot** | inherited cert, still renewing |
| deploys | `bin/deploy`, ff-only `origin/main`, `--ref` **refused** | `bin/deploy --ref <anything>` | nothing |

The stale droplet is not idle and never was. Its queue worker and root cron ran
against the production database — sending prayer pushes from 7-week-old code —
until they were disabled on 2026-09-10. Ubuntu 24.10 on it is EOL, so `apt`
cannot patch it. `deploy/staging/RUNBOOK.md` step 7 has the destroy procedure and
the two scripts to archive first.

**"Serves no traffic" is not "does nothing."** When a box shares a database,
check `systemctl`, `crontab -l` for every user, and `ss -tn` to the DB host — not
just DNS.

## Nothing reaches production that has not been on staging

Every change ships to staging first, is verified there, and the verification is
written into `LOG.md` before the same ref goes to production. Not because the
test suite is untrusted — it is 2,400+ tests and green — but because the suite
runs **in-memory SQLite** with `postJson`, and production runs **MySQL 8.4**
behind an SPA that posts form-encoded through Cloudflare, under nginx, with a
queue worker and a cron. Every deploy incident this project has had lived in
that gap: MySQL's 64-character index-name limit, unenforced `VARCHAR(n)`,
`"true"` failing a `boolean` rule, a stale route cache, assets pinned to one
host. None of them are reachable from a green suite; all of them are reachable
from staging.

The verification checklist is `deploy/staging/RUNBOOK.md` step 6. A LOG entry
that says "deployed to staging" without saying what was checked does not satisfy
this rule.

The one exception is a change that cannot exist on staging — a production-only
credential rotation, say. Those are done directly, and the LOG entry says which
part of this rule was skipped and why.

## `scripts/ship.sh` is the only sanctioned way to ship

```sh
scripts/ship.sh staging <branch-or-sha>
scripts/ship.sh production            # main only; requires typing "ship production"
```

A deploy has **two halves** and doing one of them is the recurring failure:
`bin/deploy` ships PHP and no frontend, and there is no node on either server, so
`public/build` must be built on the Mac and rsynced. `git pull` on a box ships
nothing at all.

`ship.sh` does both, in order, and then verifies from outside: it fetches
`/auth/sign-in`, resolves the `app-*.js` the page actually references, confirms
it returns 200, and greps it for `masjid.hopetechapps.com`. If any of that fails
it aborts loudly rather than reporting success.

Fixed properties, none of them negotiable:

- **`rsync` WITHOUT `--delete`.** The server keeps every historic hash-named
  chunk deliberately; a browser holding a cached `index.html` requests chunks by
  their old hashes. Deleting them turns a cached tab into a wall of 404s. The
  directory has 1,240+ chunks on production. That is the accepted cost.
- **`chown -R www-data:www-data` afterwards.** php-fpm is www-data; root-owned
  files in the tree are the 2026-08 "banner" bug.
- **The Mac's `rsync` is Apple openrsync at protocol 29**, not GNU rsync 3.x.
  Only `-az --no-perms --no-owner --no-group` are used; GNU-only flags
  (`--info=progress2`, `--outbuf`) are unavailable.
- **Never grep the whole assets directory** to check a build. It contains years
  of stale chunks and will always "find" whatever you are looking for. Resolve
  the one entry chunk from the served page.

## `npm run build:prod` is forbidden

It sets `VITE_APP_URL=https://masjid.hopetechapps.com`, which `vite.config.js`
bakes into the bundle as `process.env.APP_URL`. The SPA then addresses that one
host from **every** host it is served on: `manara.hopetechapps.com`,
`portal.alrazischool.org`, `burlingtonmasjid.com`, and staging. A staging tab
built that way silently reads and writes **production**, while looking entirely
normal.

`VITE_APP_URL` must be empty everywhere — in `.env`, in the shell, in CI. The
SPA's API calls are host-relative on purpose so one bundle serves every host.
`ship.sh` asserts the variable is unset before building and greps the shipped
chunk afterwards; do not weaken either check, and do not add a `build:staging`
that bakes a different host — it is the same defect wearing a different name.

## Staging egress: what must stay blank

Staging holds a copy of production's data shape and is reachable from the
internet. The rule is that **nothing on it can reach a real person, a real
device, or real money.** `deploy/staging/env.staging.example` is the enforced
list; `deploy/staging/provision.sh` applies it and then runs a **deny-list** pass
that blanks every key matching `^(STRIPE|RESEND|ANTHROPIC|ONESIGNAL|PUSHER|
GITHUB|AWS|VITE_PUSHER)_` plus `MAIL_PASSWORD`/`MAIL_USERNAME`, whether or not
the example mentions them.

The deny-list is the load-bearing half. The override list is a *matching*
exercise — it protects the keys someone remembered. The deny-list makes a new
integration safe **by default**, so that adding one to production and forgetting
the example does not leave a live credential on the box people are invited to
break.

| must be blank / sunk | if it is not |
|---|---|
| `RESEND_KEY`, `MAIL_MAILER` other than `log` | staging emails real congregants; also, the production key is a known-leaked key still pending rotation |
| `ONESIGNAL_*` | a push to every congregant's phone from a test box |
| `STRIPE_*` live keys | a real charge on a real card against a real connected account |
| `ANTHROPIC_API_KEY`, `ASSISTANT_ESCALATION_EMAIL` | spend, and mail to two real inboxes (that address's default in `config/services.php` is two real people) |
| `SMS_DRIVER` other than `none`/`log`, any `TWILIO_*` | a text to a real number, and TCPA exposure |
| `GITHUB_DISPATCH_TOKEN` | a real `repository_dispatch` — a CI run, possibly a TestFlight build |
| `DB_HOST` pointing anywhere but `127.0.0.1` | staging writing to production's data |
| `BACKUP_DESTINATION` at production's default | a staging `backup:run` pruning production's backup sets |
| `CANARY_BASE_URL` unset | staging's hourly canary consuming production's rate-limit bucket |

Env is only half the belt. The other half is the scrub NULLing the columns —
`mobile_app_users.onesignal_subscription_id`, `masjids.stripe_account_id`,
`masjid_app_publishing.onesignal_*`, `masjid_sms_senders` — so that even a
credential that somehow survives has nothing to send to. **Do both.**

The quickest way to catch the worst failure:

```sh
ss -tn | grep 25060      # any output means staging is on the managed cluster
```

## The `.invalid` / 555 data policy

Staging data is a scrubbed copy of production, and the scrub's contract is that
**every** address is under a `.invalid` TLD (RFC 2606 — permanently unresolvable,
so a misconfigured mailer cannot deliver even by accident) and **every** phone
number is in the `555` range. It is not "most", and it is not "the ones we test
with": a single real address in a 506-row contacts table is one broadcast away
from mailing a real congregation from a test box.

The same policy governs anything written by hand on staging. Test accounts use
`@example.invalid` — the pattern already on production as
`zzteacher@example.invalid`.

Private-disk uploads are **never** copied: `storage/app/private`,
`form-attachments`, `group-media` (photographs of children), `group-resources`,
`flyers/cutouts`, `credential-documents`. `provision.sh` deletes whatever the
clone inherited; nothing re-creates them. See `.claude/rules/private-uploads.md`.

## `App\Support\Environment` is the only environment test

`App\Support\Environment` is the ONLY place that answers "which deployment is
this". Read `Environment::isProduction()` / `::name()` / `::label()`; never
compare `config('app.env')` or `env('APP_ENV')` by hand, and never call
`$this->app->environment(...)` for a behaviour switch — that is the bug T-040
W1 fixed.

**Environment name is not a capability.** Ask what you actually need. "Is this
behind TLS" is `config('app.force_https')`, not `APP_ENV === 'production'`;
"may this send push" is `OnesignalService::isConfigured()`, not the env name.
Add a config key rather than a second name test.

**`Environment` fails SAFE, toward production.** A blank or missing `APP_ENV`
reads as production, so a misconfigured box never stamps a ribbon or a
`noindex` header on the live site. Anything built on it must keep that
direction.

**Every non-production behaviour needs its production twin in tests.** A
middleware that stamped `X-Robots-Tag` unconditionally passes every staging
assertion while de-indexing the live site. See
`tests/Feature/StagingSafetyTest.php`.

**An integration with no credentials must no-op, not throw.** A constructor
that throws on blank config fails at container resolution — before any caller
can decide whether it wanted to act — and turns a per-minute scheduled command
into a per-minute unhandled exception. Construct on any configuration, expose
`isConfigured()`, and return a shaped "not sent" result (`id` present and null,
plus `not_sent` / `reason`) so callers reading the success key keep working.
`OnesignalService` is the reference.

**`robots.txt` on staging goes through PHP.** `public/robots.txt` is checked in
and allow-all, and nginx's `try_files $uri` serves it before Laravel runs, so
the environment-aware route in `routes/web.php` is dead on any box that keeps
the default location. The staging vhost uses
`location = /robots.txt { try_files /dev/null /index.php$is_args$args; }`
(`deploy/staging/nginx-staging.conf.example`; `provision.sh` applies it).
The `X-Robots-Tag: noindex` header does not depend on this and works
regardless.

## The scrub's policy is data, and a test enforces it

`config/staging_scrub.php` is the reviewed map; `App\Console\Commands\
StagingScrub` is only mechanism. Never hand-write a `DELETE` on staging — edit
the config so `StagingScrubCoverageTest` can check it. The scrub uses raw
`DB::table()` deliberately: the tenant global scope would clean one masjid and
silently leave every other organisation's data, and `SoftDeletes` would skip
trashed people. Encrypted columns are NULLed, never rewritten — plaintext there
makes every later read throw `DecryptException`. MySQL's generated columns
(`masjids.active_owner_user_id`, `active_stripe_account_id`,
`masjid_user.default_key`) are listed under `never_write` and validated on the
plan. The command's exit code is not the acceptance criterion; the verification
block is. Full procedure: `deploy/staging/DATA-REFRESH.md`.

## Cross-references

- **`deploy/staging/DATA-REFRESH.md`** — the export → import → migrate →
  `staging:scrub` sequence, and how to prove the scrub actually ran. Refreshing
  staging's data is that document, not this one.
- `deploy/staging/RUNBOOK.md` — bringing the box up; the verification checklist;
  destroying droplet 480119186; how to reverse each step.
- `deploy/staging/CLAUDE.md` — orientation for the directory itself.
- `DECISIONS.md`, 2026-09-10 — why a cloned droplet with a local MySQL, and what
  was rejected.
- `.claude/rules/shipping.md` — why a green suite says nothing about the
  transport, which is the reason staging exists at all.
