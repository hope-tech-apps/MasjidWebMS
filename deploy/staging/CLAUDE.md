# `deploy/staging/` — the staging environment

## Purpose

Everything needed to turn a **clone of the production droplet** into the staging
box, point DNS at it, and hand it to someone safely. Created by T-040 (slice W3),
decided in `DECISIONS.md` 2026-09-10.

Staging exists because the test suite runs in-memory SQLite with `postJson`,
while production runs MySQL 8.4 behind an SPA that posts form-encoded through
Cloudflare, under nginx, with a queue worker and a cron. Every deploy incident
this project has had lived in that gap. Staging is production-shaped on purpose:
same OS, same PHP 8.3, same nginx, same `database` cache/queue/session drivers,
prod-shaped (but scrubbed) data.

Nothing in this directory runs automatically. Nothing in it touches production.

## Entry points

| file | run where | what it does |
|---|---|---|
| `RUNBOOK.md` | — | **start here.** The owner-facing sequence, the verification checklist, how to destroy droplet 480119186, and how to reverse each step. |
| `provision.sh` | as root **on the staging droplet** | Converts the clone: hostname, local MySQL, `.env` rebuild + deny-list, nginx repoint, certbot cleanup, inherited-data deletion, cron/services. Guarded; idempotent; `--again` to re-run. |
| `cloudflare-dns.sh <ip>` | on the Mac | Creates/updates the three proxied `*-staging.hopetechapps.com` A records. `--dry-run` prints the requests. |
| `env.staging.example` | read by `provision.sh` | The **override set**, not a full `.env`. Every key carries the reason it is there. |
| `nginx-staging.conf.example` | reference | The intended final vhosts, derived from production's real ones. Diff against this when staging stops answering. |
| `DATA-REFRESH.md` | — | (W2) Loading a scrubbed copy of production. `provision.sh` deliberately leaves the database empty. |
| `host` | written by `provision.sh`, read by `scripts/ship.sh` | The droplet's public IP. Not committed — copy it down with `scp`, or `export STAGING_IP`. |

Shipping code is **not** here: `scripts/ship.sh staging <ref>` at the repo root.

## Local conventions

- **Every guard states WHY.** These scripts delete uploads, rewrite `.env` and
  reconfigure nginx; a reader must be able to tell a real safety property from a
  stylistic check without running anything.
- **`--dry-run` on anything with a side effect**, routed through one `run()`
  choke point so the dry run is honest by construction rather than by
  remembering to guard each call.
- **Refuse rather than prompt.** A wrong environment is an abort with an
  explanation, not a y/N. The single exception is production in `ship.sh`, which
  requires typing `ship production`.
- **Deny-list over allow-list for secrets.** The override set protects the keys
  someone remembered; the prefix deny-list makes a *new* integration safe by
  default. Both run, deny-list last, so it also neutralises the example's own
  placeholders.
- **POSIX-ish bash, no dependencies.** `set -euo pipefail`; `awk` (via the
  environment, never `-v`) for `.env` edits so URLs, passwords, `/` and `&`
  survive; `jq` only on the Mac side.
- **Comments explain the incident, not the syntax.** Most of these guards exist
  because something specific went wrong; the comment names it.

## Pitfalls

Learned on the first real bring-up, 2026-09-10 (each is now handled in the scripts):

- **A fresh clone of prod IS prod's worker.** It boots with prod's `.env`, so
  `masjid-queue` and cron run against the managed database within seconds of
  creation. Stop them before anything else; `provision.sh` cannot run first
  because ssh is not up yet.
- **The box's `/etc/mysql/my.cnf` is the MariaDB flavour** and never includes
  `mysql.conf.d/`, so `mysqld.cnf`'s `bind-address` is silently ignored and a
  fresh `mysql-server` listens on `*:3306`. Overrides go in `/etc/mysql/conf.d/`.
- **Prod's `mysqldump` is MariaDB 10.11's.** Against the MySQL 8.4 cluster it
  writes values for GENERATED columns and the load dies (`ERROR 3105`). Dump from
  staging with MySQL 8's client behind a transient firewall rule
  (`DATA-REFRESH.md`), strip `DEFINER=`, and keep `skip-log-bin` (else `1419`).
- **The managed cluster's firewall blocks this box** — keep it that way; the
  refresh opens a rule and removes it in the same command.
- **`BROADCAST_CONNECTION=` blank is not "unset"** — Laravel treats it as a
  driver name and `package:discover` fails; use `null`.
- **Prod squirrels secrets and dumps in more places than the app dir:**
  `/root/env-backups/.env*`, `/root/env-backup-<ts>`, `/root/db-backups/`,
  `/root/backups/*.sql.gz`, `/var/backups/masjid_db/`, a school-data JSON in
  `/root`. All inherited by the clone; all purged now; the script reports any
  live-looking value it still finds.
- **`doctl databases firewalls list` has no `--format`** (1.163); parse the
  plain table.


- **The clone boots believing it is production.** Same `.env`, same nginx
  `server_name`s, same certbot renewals — and a **queue worker plus root cron
  pointed at the production database**. That is exactly the 2026-09-10 incident
  with droplet 480119186. Provision the box promptly; do not leave it up
  overnight un-provisioned.
- **`bootstrap/cache/*.php` outranks `.env`.** The clone's compiled config holds
  production's DB host and live Stripe secret. Leave it in place and staging
  talks to the production cluster while every file on disk says otherwise — the
  stale-bootstrap-cache shape that produced 528 phantom 403s in CI.
- **`certbot delete` removes the certificate files with it.** The vhosts must be
  repointed to `/etc/ssl/manara-staging/` (copied first) *before* certbot is told
  to forget the production names, or nginx refuses to start and :443 goes down.
- **`default_server` belongs to exactly one vhost per port.** Repeating it makes
  nginx refuse to start with "a duplicate default server", taking every staging
  hostname down at once. It stays on the app vhost; the portal vhost must not
  have it.
- **Two-label hostnames do not work.** Universal SSL covers
  `*.hopetechapps.com` one label deep. `staging.masjid.hopetechapps.com` has no
  certificate. `alrazi.manara.*` works only because it is a Cloudflare **Pages**
  custom domain with its own per-hostname cert — not evidence that two levels are
  fine.
- **Proxied, not DNS-only.** The zone's SSL mode is `full` (non-strict), so
  Cloudflare does not validate the origin certificate's name — which is what lets
  the box keep serving production's copied cert and never run certbot. A DNS-only
  record bypasses all of that and needs a real cert per name on the origin.
- **`ss -tn | grep 25060` must print nothing.** Any output means staging is
  connected to production's managed database. Check it after every `.env` change.
- **Blank Stripe keys are the safe state.** An SDK exception on checkout is a
  clean, visible failure. A live key that works is not.
- **The Mac's `rsync` is Apple openrsync at protocol 29.** GNU-only flags are
  unavailable; only `-az --no-perms --no-owner --no-group` are used.

## Open items

- **The droplet does not exist yet.** Creating it needs a DigitalOcean API token
  or four console clicks — `doctl` here is unauthenticated and the DO MCP has no
  create-droplet tool. `RUNBOOK.md` step 0a has both forms. Snapshot **244822223**
  is ready.
- **Stripe test keys are not in place.** They come from the dashboard only;
  `provision.sh` blanks the slots and reports them.
- **`provision.sh` has never been executed end to end** — there is nowhere to run
  it. Its `.env` merge and deny-list, and its nginx rewrite against production's
  real vhost text, were exercised in isolation
  (`artifacts/t040-w3-20260910-002059.log`). Read its output on the first real
  run rather than assuming.
- **`archive/` does not exist yet and is gitignored.** `RUNBOOK.md` step 7
  pulls `/root/archive-pre-staging/` off droplet 480119186 (its last `.env`,
  keys blanked, and crontab) and `/root/manara-stripe-live.sh` off PRODUCTION
  before the stale box is destroyed. `manara-onboard-link.sh` no longer exists
  on either box (verified 2026-09-10).
- **Inherited sshd settings.** The clone carries production's `PermitRootLogin`
  and `PasswordAuthentication yes` (a standing open item on production too).
  `provision.sh` does not change them — hardening the clone would hide the fact
  that production has the same problem.
- **Scheduled work is not pruned on staging.** `prayers:send-due` and
  `prayers:daily-resync` still run every minute / daily; they are inert because
  OneSignal is blank *and* the scrub NULLs every subscription id, but nobody has
  removed them from the schedule. Same for `backup:run`, which is redirected to
  `/var/backups/manara-staging` rather than disabled.
- **The native apps and the Nuxt renderer cannot be pointed at staging without a
  code edit** — iOS and Android hardcode the API host in Swift/Kotlin literals,
  and `nuxt.config.ts` hard-redirects `/jummah-lunch/**` to production at build
  time. Staging covers the Laravel origin and the SPA only.
  (`artifacts/t040-scoutC-external.md` has the specifics.)

## Data refresh — `DATA-REFRESH.md` (slice W2)

`provision.sh` leaves the database empty on purpose; `DATA-REFRESH.md` is how it
gets filled. The sequence is read-only `mysqldump --single-transaction
--no-tablespaces --set-gtid-purged=OFF` on prod → copy → load into
`masjids_staging` → `php artisan migrate --force` → `staging:scrub --dry-run` →
`staging:scrub --i-understand-this-destroys-personal-data`. The policy behind it
is `config/staging_scrub.php`; the mechanism is
`app/Console/Commands/StagingScrub.php`.

Things that bite:

- **`mysql-client` is usually missing on the production droplet.** The app talks
  to MySQL through `pdo_mysql` and never needs the CLI, so `mysqldump` is often
  simply not installed. `apt-get install -y mysql-client` first.
- **All three dump flags are load-bearing.** Without `--no-tablespaces` the
  managed user lacks `PROCESS` and the dump aborts; without
  `--set-gtid-purged=OFF` the import dies on a `SET @@GLOBAL.GTID_PURGED` line
  staging's standalone MySQL will not accept.
- **Stop `masjid-queue` before loading.** Production's `jobs` rows arrive with
  the dump, and a worker will happily send a real push to a real person in the
  window before the scrub empties that table.
- **The command's exit code is not the acceptance criterion — the verification
  block is.** It re-reads every anonymised address and phone, then sweeps every
  text column of `contacts` and `users` for an `@` not ending in `.invalid`,
  independently of the config, because the config is the thing that could be
  wrong. `VERIFICATION FAILED` means drop the database and start over.
- **Copy `storage/app/public/` only.** `storage/app/private/**` —
  form attachments, group media (photos of children), group resources,
  credential scans, janazah flyer sources — is never copied. Nothing 404s as a
  result: the scrub deletes those attachment rows outright rather than leaving a
  path to bytes that are not there.
- **`sms_suppressions` is emptied, and that is safe ONLY here.** It is the STOP
  list and in production deliberately outlives the contact row. It is expendable
  on staging solely because staging cannot send SMS. Give staging a real A2P
  sender and this table has to move to `keep` first.
- **A new migration with a personal-data column turns the suite red on purpose.**
  `tests/Feature/StagingScrubCoverageTest` walks the real schema and fails if a
  PII-shaped column is neither dropped, nulled, anonymised nor listed in
  `reviewed_keep` with a reason. Decide; do not delete the test.
