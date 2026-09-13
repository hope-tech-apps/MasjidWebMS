# Deploy / ops artifacts

Infrastructure files for the Manara droplets — production
(`masjid.hopetechapps.com`) and, since T-040, staging
(`masjid-staging.hopetechapps.com`; see `staging/`). These are committed for
reproducibility. **Nothing applies them automatically.** Apply them by hand once
per server.

> **There is no deploy pipeline.** `.github/workflows/tests.yml` is the only
> workflow on `main`, and it runs the test suite — it does not deploy. (A
> `chore/github-actions-deploy` branch exists and is unmerged.) An earlier
> version of this file described a GitHub Actions deploy that pulls code,
> migrates and restarts the queue; that deploy has never existed on `main`, and
> believing in it is how a "deployed" change turns out never to have shipped.
>
> Deploys are: `scripts/ship.sh <staging|production> [ref]` from the Mac, which
> builds and rsyncs the SPA and then runs `sudo bin/deploy` on the box. See
> `.claude/rules/environments.md`.

## Queue worker — `masjid-queue.service`

The app uses `QUEUE_CONNECTION=database` and dispatches `SendMasjidNotificationJob`
(push notifications) as a `ShouldQueue` job. **Without a running worker, queued
jobs sit in the `jobs` table forever and notifications never send.** This systemd
unit runs the worker as a daemon: auto-restart on crash (`Restart=always`),
start on boot (`WantedBy=multi-user.target`), recycle hourly (`--max-time=3600`).

### Install (one-time, as root on the Droplet)

```sh
# Copy the unit from the repo checkout into systemd
cp /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem/deploy/masjid-queue.service \
   /etc/systemd/system/masjid-queue.service

systemctl daemon-reload
systemctl enable --now masjid-queue.service
systemctl status masjid-queue.service        # confirm "active (running)"
```

### Verify it processes jobs

```sh
# Dispatch a throwaway job and confirm the queue drains:
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
php artisan tinker --execute='\App\Models\Notification::query()->exists();'  # any harmless call
# Or watch the worker live:
journalctl -u masjid-queue.service -f
```

### Interaction with deploys

`bin/deploy` runs `systemctl restart masjid-queue.service` near the end, then
`sleep 2` and `systemctl is-active` to prove it came back. **That restart is the
only thing that gets new code into the worker** — a long-running worker holds the
old code in memory, so without it a deployed fix keeps not happening for up to
`--max-time` (3600s).

Nothing else restarts it. There is no pipeline running `queue:restart`; if you
deploy by hand instead of through `bin/deploy`, restart the worker yourself.
(Both php-fpm and the worker run as `www-data` against the same `database` cache
store, so a `queue:restart` cache flag would propagate — but only if something
writes it.)

### Path note

`WorkingDirectory` / `ExecStart` use the absolute prod path
`/var/www/html/Masjids_App_Management_System/MasjidsManagementSystem`. If the
app is ever relocated, update both lines in the unit and `daemon-reload`.

## Scheduler cron

`routes/console.php` schedules `sanctum:prune-expired --hours=24` daily (keeps
the `personal_access_tokens` table bounded). Laravel's scheduler only runs if
the system cron invokes `schedule:run` every minute. Install once (as root):

```sh
( crontab -l 2>/dev/null; \
  echo "* * * * * cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem && /usr/bin/php artisan schedule:run >> /dev/null 2>&1" \
) | crontab -
crontab -l   # confirm the line is present
```

## CORS allowed origins

`config/cors.php` reads `CORS_ALLOWED_ORIGINS` (comma-separated). Default `*` is
acceptable for the anonymous public endpoints (`supports_credentials => false`,
no cookies/credentials cross-origin, public data only) but locked down in prod
for defense-in-depth:

```
CORS_ALLOWED_ORIGINS=https://www.burlingtonmasjid.com,https://burlingtonmasjid.com,https://masjid.hopetechapps.com
```

Only browser origins need listing (native iOS/Android apps don't send an Origin
header). The Nuxt site's client-side fetches (e.g. the splash modal) come from
`www.burlingtonmasjid.com`, so that origin must stay in the list.

## Backups — `bin/backup`, `backup:run`, `backup:check`, `backup:ship`, `backup:drill`, `backup:restore`

Every backup this server had before 2026-08-21 was a `mysqldump`. When the
`media` table emptied — 226 rows, every masjid's logo, 45 announcement images —
those dumps could restore the ROWS and none of the FILES. The posters were
recovered from the operator's laptop. The logos were not recovered at all.

A set is the database **and** the media files its rows point at, and the tooling
will not let you have one without the other.

### One-time install (as root on the Droplet)

The scheduler runs as `www-data` (see the cron line above), so the destination
cannot be `/root/backups`.

```sh
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem
sudo bin/backup --install         # mkdir /var/backups/manara, chown www-data, 0750
sudo bin/backup --dry-run         # prints the plan and the free-space arithmetic
sudo bin/backup                   # takes one
```

`routes/console.php` schedules `backup:run` daily at 02:40 UTC. Until the
destination exists every run refuses and logs at `error` naming `--install` — it
does not fail quietly.

**AND THAT IS EXACTLY WHAT HAPPENED.** On 2026-09-12 this platform was found
never to have taken a backup at all — not a stale one, none, ever.
`sudo bin/backup --install` above was never run on the droplet, so every nightly
run since the schedule was added refused correctly, with a clear sentence and the
remedy, into `>> /dev/null 2>&1`. Every check worked. Nobody was told, because
from outside the machine a refusal and a success look the same.

So there are now three more commands, and the first one is the important one:

```sh
sudo -u www-data HOME=/tmp php artisan backup:check      # is there a recent, verified, off-site set?
sudo -u www-data HOME=/tmp php artisan backup:ship --all # copy what is here to the off-site store
sudo -u www-data HOME=/tmp php artisan backup:drill      # prove the newest set really restores
```

`backup:check` runs daily at 14:20 UTC — half a day from the backup, so a wedged
`backup:run` lock cannot take it with it. **It does not watch `backup:run`.** An
alerter that waits to be told about a failure is silent on every night the thing
it watches does not run, which was every night; this one reads the destination
and the clock, and fails when the newest VERIFIED set is older than 36 hours
(one missed nightly run plus slack).

**`OPS_ALERT_EMAIL` is the single variable that makes any of this reach a
person.** `backup:check` and `backup:drill` log to the `monitors` channel, which
is the ordinary file line plus `ops-alerts`; `ops-alerts` emails at level `error`
and is completely inert while that variable is empty — which is how it is on
production right now. Set it.

`backup:drill` runs weekly, Sunday 04:20 UTC.

### What a set is

```
/var/backups/manara/20260821-024000/
    database.sql.gz     mysqldump, verified to end with "-- Dump completed"
    media.tar.gz        the disk config('media-library.disk_name') resolves to
    manifest.json       sha256 of both halves, written LAST
```

The manifest is written last and the directory is renamed into place only once
it is there, so **a directory in the destination is a run that finished**. A
killed run leaves a `*.partial` that nothing will restore.

`manifest.json` also records `media_integrity`: how many media rows there were
and how many of them had their file. That is the number that says whether the
set is worth restoring, and it is the number nobody had on 2026-08-17.

### What "the file half is whole" is actually checked against

This is the one guarantee a set has that the four legacy dumps did not, so it is
worth knowing exactly what is compared with what.

- **Files against files, not headers against files.** A tar header is not a
  file: `tar -czf … -C root .` writes one for every directory too, GNU tar
  writes an extra `L` record for any path over 99 bytes, and a symlink is a
  record with no data. The media disk is 91 files in **68 directories**, so its
  archive carries roughly 160 headers — which means an archive holding 91
  headers and thirty files used to clear a floor of "at least 91". `backup:run`
  now counts only the tar headers whose type flag says *regular file* and
  compares that to the regular files the disk walk found.
- **Every disk the rows name.** A set covers ONE disk. `config/filesystems.php`
  defines `local`, `public` and `s3`, and every media row is on `public` today —
  a fact about today's configuration, not a property of the design. So each run
  asks the rows (`select distinct disk from media`, and `conversions_disk` too)
  and **refuses** if any row names a disk the archive does not cover, rather than
  writing a manifest that says `complete: true` about files it never went near.
  If the rows cannot be read at all, that is also a refusal: `complete` is a
  claim, and a claim nobody could check is not one to write down.
- **A media-half floor**, `BACKUP_MEDIA_MINIMUM_FILES` (default 1). On
  2026-08-17 the FILES went first. A nightly run inside that window would have
  dumped a healthy database, tarred an empty directory and verified both —
  every check passing honestly — and then pruned the sets that still held the
  files. An archive of nothing is not a small backup.
- **Retention asks what this set captured before it deletes anything.** If a run
  captures less than `BACKUP_MEDIA_REGRESSION_RATIO` (default 0.9) of the media
  files the newest existing set captured, the new set is still written and
  verified — it is a faithful capture — but **pruning is skipped and the run
  exits 3**, because the older sets are then the only copies of what went
  missing. Being wrong here costs disk; being wrong the other way costs the
  files.

### Exit codes

```
0  a complete set was written and verified
1  no set was written — the reason is on stderr and in the log
2  a set was written and verified, and the media it captured has rows whose
   files are missing: the BACKUP is sound, the data in it is not
3  a set was written and verified, and it captured markedly fewer media files
   than the set it would have replaced. RETENTION WAS SKIPPED; nothing was
   deleted. Look at the media disk before the next run.
```

### Restore procedure

Restore is **preflight-by-default**. A bare run writes nothing; it prints what it
would do and every reason it would not.

```sh
cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem

# 1. What is here, and is the newest one restorable?
ls -1 /var/backups/manara
sudo -u www-data HOME=/tmp php artisan backup:restore                 # newest set, preflight
sudo -u www-data HOME=/tmp php artisan backup:restore --set=20260821-024000

# 2. Read the preflight. It names: when the set was taken, which database and
#    which media root it will write to, and how many media rows had no file when
#    it was taken. If the media root it prints is not the one you expect, STOP.

# 3. Take a set of the CURRENT state first, so the restore itself is reversible.
sudo bin/backup --label=pre-restore

# 4. Stop the queue worker so nothing writes underneath the restore.
sudo systemctl stop masjid-queue.service

# 5. Perform it. Media is unpacked first, then the database is applied.
sudo -u www-data HOME=/tmp php artisan backup:restore --set=20260821-024000 --force

# 6. Bring it back up.
sudo systemctl start masjid-queue.service
sudo -u www-data HOME=/tmp php artisan config:clear
sudo -u www-data HOME=/tmp php artisan config:cache
```

**Order is media first, then the database, and that is deliberate.** A file with
no row is inert. A row with no file is the outage. If a restore dies halfway it
should die on the harmless side.

`tar -xzf` **overwrites what the archive names and leaves everything else
alone.** It is not a mirror: media added since the backup survives the restore.
If you need the disk to match the backup exactly, move the current directory
aside first — the command will not do it for you.

### It refuses to restore half a pair

`backup:restore` stops, and says which of these it was, when:

- the set has no `manifest.json` (a crashed run, or not a set);
- the manifest records the set as incomplete;
- either half named by the manifest is missing from the directory;
- either half's size or sha256 does not match what the manifest recorded;
- the media disk cannot be unpacked into (e.g. media has moved to S3);
- the set records media rows that already had no file when it was taken — that
  one is overridable with `--accept-dangling`, because the set is intact and it
  is the DATA that is not.

**The four `*.sql.gz` files in `/root/backups` are not sets and this command will
not restore one.** Pointed at one it says why: restoring a database-only dump
re-creates 226 media rows pointing at files that are not on this server, which is
the outage, re-applied by someone who believes they are fixing it. If you ever
genuinely need one of them, you are accepting that state knowingly, and you do it
with `mysql` by hand rather than with a tool that implies it is safe.

(Two of the seven backup artifacts on this server captured nothing: one of those
five files is 20 bytes and decompresses to zero, and
`/var/backups/masjid_db/pre_permasjid_20260724_163430.sql` — from a second,
ad-hoc backup directory nobody scheduled — is 0 bytes. Both are named exactly
like the ones that worked, and `gzip -t` calls the first one healthy. That is why
`backup:run` opens what it just wrote and checks it against a size floor and for
mysqldump's completion line before calling it a backup.)

### If media ever moves to S3

`config/filesystems.php` defines an `s3` disk and the production `.env` already
carries `AWS_*` keys, but no S3 flysystem adapter is installed and there is no
`aws` binary on this host, so that disk cannot serve a byte today. When it can,
the file half becomes `aws s3 sync` and that is **one config line** —
`backup.media.strategies.s3` (`BACKUP_S3_STRATEGY`) — not a rewrite. Until it is
set, a media disk whose driver is `s3` makes `backup:run` **refuse the whole
run** rather than quietly writing a database-only set.

### The off-site copy — built, and switched OFF until you provision it

`/var/backups/manara` is on the same 48G root filesystem as the application, so
until this exists a destroyed droplet takes the backups with the thing they were
backing up. `backup:run` now copies each verified set to an S3-compatible store,
and `backup:ship --all` does the sets already on disk.

**It is off.** It reads its own `BACKUP_OFFSITE_*` variables, not the empty
`AWS_*` ones, and none of them is set — so today there is still one copy on one
volume, and `backup:check` says exactly that on every run rather than leaving it
to be discovered.

To turn it on:

1. Create a **private** Space in a region **other than this droplet's**, so a
   regional outage is not a single event.
2. Create a Spaces access key and restrict it to that Space. It must be its own
   credential: the `AWS_*` keys belong to the disk *media* might live on one day
   — public images — and this bucket holds an entire organisation's database
   including children's records. One key pair for both means the credential that
   serves logos can download every backup ever taken.
3. Add to `.env` (and **parse-check the file before `config:cache`**, which
   clears first — a bad `.env` plus `config:cache` is every request 500):

   ```
   BACKUP_OFFSITE_ENABLED=true
   BACKUP_OFFSITE_BUCKET=manara-backups
   BACKUP_OFFSITE_REGION=ams3
   BACKUP_OFFSITE_ENDPOINT=https://ams3.digitaloceanspaces.com
   BACKUP_OFFSITE_KEY=...
   BACKUP_OFFSITE_SECRET=...
   BACKUP_OFFSITE_PREFIX=manara
   ```
4. `php artisan backup:ship --dry-run` — prints the exact URLs, sends nothing.
5. `php artisan backup:ship --all` — oldest first, so an interrupted run has made
   progress through the history.
6. `php artisan backup:check` — should now say `off-site: present`.
7. `BACKUP_OFFSITE_REQUIRED=true`, so a regression pages instead of being noted.

**Both halves or neither, in the bucket too.** The halves go up first, each is
HEADed back at the byte length we sent, and the manifest goes **last** — a remote
prefix with no `manifest.json` is not a set, exactly as a local `*.partial` is
not. A ship that fails deletes what it put and never writes a manifest.

**What protects the bytes.** TLS in transit (a non-`https` endpoint is refused
outright), `x-amz-server-side-encryption: AES256` at rest, a private bucket, a
key scoped to it alone, and locally 0640 files inside a 0750 directory. Be exact
about the limit: SSE-S3 is the *provider's* key, so it stops a stolen disk and
stops nothing that holds the bucket credential or the DigitalOcean account.
Client-side encryption is deliberately not implemented — a key kept on this
droplet protects nothing, and a key kept elsewhere is a custody process that has
to survive the incident the backups are for.

**And what the same provider does not buy.** Spaces covers a destroyed droplet, a
corrupted volume, a wrong `rm`, ransomware on the host. It does **not** cover a
compromised or suspended DigitalOcean account, which reaches droplet, managed
database and Space alike. A copy at a second provider is the next piece of work.

### The restore drill — the database half is now executed

**Read this before you rely on `backup:restore` at three in the morning.**

This section used to say the database half of a restore had never been applied to
a real MySQL server by anything in this repository. It has now, weekly, since
`backup:drill` exists — but only under conditions worth being precise about.

The constraint has not changed:

```sh
ls /usr/bin/mysql /usr/bin/mariadb        # the CLIENT is installed
ls /usr/sbin/mysqld /usr/sbin/mariadbd    # no such file — no SERVER is
systemctl is-active mariadb mysql         # inactive, inactive
```

So the drill restores into a **scratch schema on the managed database server** —
created by the drill, dropped by the drill, and swept by the next drill if a kill
left one behind. **Not onto staging**: staging is deliberately scrubbed of real
people, which is what makes it safe to hand around for testing, and a production
set there would trade a backup problem for a privacy incident involving
children's school records.

It then asserts what came back: the table count, the restored `media` row count
against the manifest, and each of those rows against files unpacked from the
archive into a private 0700 directory. A client that exits 0 is not evidence.

**Why it cannot touch live data** (five guards, each sufficient alone; the full
argument is in `App\Console\Commands\BackupDrill`):

- there is no `--database` option — the scratch name is generated;
- three assertions run immediately before every statement including the `DROP`,
  and a live database whose name begins with `BACKUP_DRILL_SCRATCH_PREFIX` makes
  the drill **refuse to run at all**;
- the decompressed dump is scanned and refused if it contains `USE` or
  `CREATE DATABASE` — such a dump moves the client into the live schema, which
  would overwrite production;
- every invocation carries `--database=<scratch>` explicitly;
- the media half is unpacked to a temporary directory, never the media disk.

The backup user needs `CREATE` and `DROP` on `manara_drill_%`. If it does not
have them the drill exits **2 (blocked)** and names the grant; it never proceeds
without a scratch schema.

**What is still not proven:** applying a set to the LIVE schema *on top of
existing tables*, which is what `backup:restore --force` does and which nothing
may rehearse on production. The drill restores into an empty schema. Run
`backup:drill --keep-scratch` and look at the result before you trust a restore
in an emergency.

One thing about the restore path is worth keeping here, because it is the bug
that started all of this:

> `mysql --execute='source dump.sql'` runs the client's **own** `SOURCE`
> command, and `SOURCE` does not stop on an error — it prints the failed
> statement and carries on, and the client exits **0**. A restore that died on
> statement 812 of 4000 therefore reported success. Fed on stdin the client runs
> in batch mode, where the first error aborts and the exit status is non-zero.
> `backup:restore` and `backup:drill` both feed the dump on stdin and pass no
> `--execute` at all.

### What this does not cover

The full statement is in the header of `bin/backup`; read it before trusting a
backup. In short: the off-site copy is **built and switched off** until somebody
provisions a Space and fills in six `.env` lines, so today there is still **one
copy, on the same volume as the application** — and `backup:check` says so on
every run; retention is a bounded count (14 sets, ~175 MB at today's 12.5 MB per
set, against 42 GB free) and a full volume makes the run refuse rather than
part-write; the private disk, `.env` and server configuration are not in it; and
a restore **onto the live schema, over existing tables** has still never been
executed — see the drill section above for exactly how far it has been taken.

### Open: DigitalOcean's own database backups

The database is a DigitalOcean **managed** instance (nothing listens on 3306 on
this droplet; `mysqld` is inactive), and managed databases carry their own
automatic backups with their own retention, held by DigitalOcean. **Nobody has
written down** whether they are enabled on this cluster, what the retention
window is, who can trigger a restore, or how long one takes. So there are two
backup systems for the database and exactly one for the files. Someone should
confirm DO's settings and write them into this section; until then, treat DO's
copy as the database's second line and `/var/backups/manara` as the only line the
media has.
