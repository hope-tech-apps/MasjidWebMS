# Deploy / ops artifacts

Infrastructure files for the production Droplet (`masjid.hopetechapps.com`).
These are committed for reproducibility — they are NOT auto-applied by the
GitHub Actions deploy (that only pulls code, migrates, rebuilds the SPA).
Apply them manually once per server.

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

The GitHub Actions deploy already runs `php artisan queue:restart`, which writes
a cache flag the worker checks after each job; the worker then exits and systemd
respawns it with the freshly-pulled code. No manual restart needed on deploy.
(Both php-fpm and the worker run as `www-data` against the same `database` cache
store, so the restart signal propagates.)

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

## Backups — `bin/backup`, `backup:run`, `backup:restore`

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

### Which half of a restore has been exercised, and which has only been read

**Read this before you rely on `backup:restore` at three in the morning.** An
untested restore path is a backup nobody has proven, and you are entitled to
know which half has been run.

**The file half is executed.** In the test suite a real `tar` unpacks a real
`media.tar.gz` into a real directory, a real `gzip` decompresses a real dump,
and the files are asserted to be on the disk afterwards. Every refusal this
command makes is executed too.

**The database half has never been applied to a real MySQL or MariaDB server**
by anything in this repository, and it cannot be from here:

```sh
ls /usr/bin/mysql /usr/bin/mariadb        # the CLIENT is installed
ls /usr/sbin/mysqld /usr/sbin/mariadbd    # no such file — no SERVER is
systemctl is-active mariadb mysql         # inactive, inactive
```

The database is a DigitalOcean **managed** instance and is off limits, and this
droplet is the production application host — installing a server on it to prove
a backup is safe would be changing production to check that production is safe.

So the run stops one step short of the server. What *is* executed:
`tests/Feature/Backup/BackupRestoreDatabaseHalfTest.php` puts a real child
process exactly where the client stands, asserts that the entire decompressed
dump reaches it **on stdin, byte for byte**, and asserts that this command
reports failure when that process exits non-zero. The one thing taken on the
manual's word is the client's own behaviour, and it is the thing that was wrong:

> `mysql --execute='source dump.sql'` runs the client's **own** `SOURCE`
> command, and `SOURCE` does not stop on an error — it prints the failed
> statement and carries on, and the client exits **0**. A restore that died on
> statement 812 of 4000 therefore reported success. Fed on stdin the client runs
> in batch mode, where the first error aborts and the exit status is non-zero
> (that is precisely what `--force` exists to switch off). This command now feeds
> the dump on stdin and passes no `--execute` at all.

That paragraph stops being true the day somebody restores a set onto a scratch
database and writes down here that they did. **Until then: the plumbing is
proven, the client's behaviour is documented-and-assumed, and no restore of this
system has ever been performed end to end.**

### What this does not cover

The full statement is in the header of `bin/backup`; read it before trusting a
backup. In short: **one copy, on the same volume as the application** (no
offsite); retention is a bounded count (14 sets, ~175 MB at today's 12.5 MB per
set, against 42 GB free) and a full volume makes the run refuse rather than
part-write; the private disk, `.env` and server configuration are not in it; and
**the database half of a restore has never been executed** — see the section
above for exactly how far it has been taken.

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
