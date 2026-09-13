---
paths:
  - "bin/backup"
  - "config/backup.php"
  - "app/Console/Commands/BackupRun.php"
  - "app/Console/Commands/BackupRestore.php"
  - "app/Console/Commands/BackupCheck.php"
  - "app/Console/Commands/BackupShip.php"
  - "app/Console/Commands/BackupDrill.php"
  - "app/Support/Backup/**"
  - "deploy/README.md"
---
# Backups

On 2026-08-17 the `media` table emptied platform-wide — 226 rows, every masjid's
logo, 45 announcement images. There were four backups on the server. Every one
of them could restore the ROWS. Not one held a FILE. The posters came back off
the operator's laptop; the logos did not come back.

## The non-negotiables

- **A backup is BOTH halves or it is nothing.** `backup:run` resolves the media
  disk FIRST and refuses the entire run if it cannot archive it. There is no
  `--database-only`, no fallback, and no path through the command that writes a
  dump without an archive beside it. A database-only backup of this system
  re-creates rows pointing at files that are gone, which is the outage — so a
  backup that covers half a pair is not a partial backup, it is a loaded gun.
- **The manifest is the set, and it is written LAST.** A run assembles into
  `<id>.partial/` and renames into place only once `manifest.json` is there. A
  directory in the destination is therefore a run that finished; a `*.partial`
  is a crashed one and nothing will ever restore it.
- **Verify what you just wrote, from the inside.** Two of the seven backup
  artifacts on that server captured nothing:
  `/root/backups/pre-forms-migration-20260728-233129.sql.gz` (20 bytes, a valid
  gzip stream that decompresses to ZERO — `gzip -t` calls it healthy) and
  `/var/backups/masjid_db/pre_permasjid_20260724_163430.sql` (0 bytes). Both are
  named exactly like the ones that worked. So the database half is checked
  against a size floor AND for the `-- Dump completed` line mysqldump writes only
  when it finished, and the media half's REGULAR FILES are counted against the
  regular files that were on the disk.
- **Count files against files. A tar header is not a file.** `tar -czf … -C root
  .` writes a header for every directory, GNU tar writes an `L` record for any
  path over 99 bytes, and a symlink is a record with no data. The media disk is
  91 files in 68 DIRECTORIES, so its archive carries ~160 headers — an archive
  holding a third of the files still had more headers than the disk had files,
  and the completeness check passed it. `ArchiveIntegrity::countTarEntries()`
  returns `files` (type flag `0` or `\0` only) alongside `entries`, and only
  `files` may ever be compared to a count of files. The disk-side walk skips
  symlinks for the same reason: both sides must be the same unit.
- **A set covers ONE disk, and the rows are asked whether that is enough.** Every
  run takes `select distinct disk from media` (and `conversions_disk`) and
  REFUSES if a row names a disk the archive does not cover — and refuses again if
  the rows cannot be read at all. `complete: true` is a claim; a claim nobody
  could check is not one to write. Every row is on `public` today, which is a
  fact about today's configuration and not a property of the design.
- **A media half has a floor, and retention has to earn its delete.** An archive
  of an empty media root is not a small backup: on 2026-08-17 the FILES went
  first, so a nightly run inside that window would have passed every check
  honestly and then pruned the sets that still held them.
  `backup.media.minimum_files` refuses the run outright;
  `backup.media.retention_regression_ratio` lets the set be written but SKIPS
  PRUNING and exits 3 when it captured markedly less than the set it would have
  replaced. The older sets are then the only copies of what went missing.
- **Never let a wrapper report the wrapped thing's health.** Two spellings of the
  same bug: `mysqldump … | gzip > out.gz` exits with GZIP's status, and
  `mysql --execute='source dump.sql'` runs the client's own SOURCE, which prints
  a rejected statement, CARRIES ON, and lets the client exit 0 — so a restore
  that died on statement 812 of 4000 reported success. Dump with `--result-file`
  and compress separately; restore by feeding the dump on STDIN, where batch mode
  aborts on the first error and the exit status is real.
- **Never pipe the dump into gzip.** `mysqldump … | gzip > out.gz` exits with
  GZIP's status; mysqldump can fail outright and the shell reports success. That
  is how a 20-byte backup gets written and reported as fine. Dump with
  `--result-file`, compress as a separate process, check both exit codes.
- **The password never goes in an argument vector** — a command line is readable
  by every process on the host. It goes in a 0600 `--defaults-extra-file` that is
  deleted before the set is moved into place, so it is never part of a backup.
- **Where media lives is a CONFIG question, not a constant.**
  `App\Support\Backup\MediaTarget` derives the archive from
  `config('media-library.disk_name')` and the disk behind it. Never hardcode
  `public` or `storage/app/public`. A disk whose driver has no configured
  strategy (today: `s3`) is a refusal that names the setting which fixes it.
- **Retention is a COUNT, and pruning happens only after a verified write.** A
  count is a hard ceiling on bytes; a duration is not. Never prune before
  writing, and never prune the last remaining set — a failing backup must not be
  able to eat the last good one to make room for itself.
- **One log line per run, on every run including a clean one.** `schedule:run`
  discards stdout. `info` = a verified set; `warning` = verified, and either the
  media it captured has rows without files (exit 2) or it captured markedly less
  than the set before it and retention was skipped (exit 3); `error` = no set was
  written. A file with
  no row is deliberately NOT graded: this disk carries 68 directories of stock
  seed art that will never have rows, and an amber that burns every ordinary
  night is an amber that gets silenced.

## The logic lives in artisan, not in the shell script

`bin/backup` is a wrapper. Everything a shell version would have to decide —
which connection holds the data and its credentials, which disk media is on,
where that disk's root is, whether it is a directory or a bucket — is already
decided by the application's configuration, and a shell script would re-derive
all of it by parsing `.env`: a second copy of the answer that drifts silently the
moment `MEDIA_DISK` changes. Put behaviour in the commands, where it reads the
same config the application reads and where a test can call it. The script exists
so cron and an operator have one entry point, and so the statement of what is NOT
covered sits where a person looks before trusting a backup.

## A backup that is not happening must be LOUD

On 2026-09-12 this platform was found never to have taken a backup. Not a stale
one — none, ever. `backup:run` had been scheduled nightly at 02:40 for weeks,
`/var/backups/manara` did not exist because `sudo bin/backup --install` was never
run on that box, and the command refused correctly every single night with a
clear sentence and a remedy. Nobody saw it: the cron line ends in
`>> /dev/null 2>&1`.

- **The watchdog does not watch the backup, it watches the clock.** `backup:check`
  never inspects `backup:run` — an alerter driven by events is silent exactly
  when the event source dies, and the event source dying is the failure. It reads
  the DESTINATION and asks how old the newest set is that passes every check a
  freshly-written set would pass. A backup that stops happening produces a
  growing number, and a number crosses a threshold on its own. Never replace this
  with "alert when the backup command fails".
- **Its threshold is 36 hours and not 24, on purpose.** A nightly run at 02:40
  against a 24-hour bar pages every time cron is ten minutes late. 36 is one
  missed night plus slack: the first genuinely missed night pages and no ordinary
  night does. Age comes from the manifest's `created_at`, never from mtime, which
  a `cp -p` carries in from elsewhere.
- **A checker whose own failure is invisible reproduces the bug it catches.**
  Four defences, and three of them share one point of failure: separate schedule
  entry and lock from `backup:run`; a heartbeat file it reads back so a checker
  that was dead for a week says so; one log line on every run including a clean
  one, so the ABSENCE of a line means something; and
  `BACKUP_CHECK_HEARTBEAT_URL`, a dead man's switch pinged only after a PASS,
  which is the only one that survives this host. That URL is unset today —
  the checker's own silence is detectable, not alerted, and that is the residual
  risk. Do not delete the heartbeat file or the ping and leave the first three
  believing they are enough.
- **`monitors` is the default channel for the new commands, and null is the
  default for the old ones.** `media:verify` and `tenancy:canary` expect an
  operator to point them at a delivering channel; the whole reason this work
  exists is that the one manual step is the step nobody does. `OPS_ALERT_EMAIL`
  is the single switch that turns the on-call contract on.
- **Not configured is not the same as broken.** With no off-site store, every
  `backup:check` run says so in full and does not page: today it is a known,
  ticketed gap, and an amber that burns every ordinary night is an amber that
  gets silenced. Once `BACKUP_OFFSITE_ENABLED` is true, an off-site copy that is
  not there — or that cannot be confirmed — is an `error`. "I could not ask the
  store" is graded the same as "it is not there", for the same reason
  `backup:run` refuses to write a completeness claim nobody could check.

## The off-site copy

- **Both halves or nothing, in the bucket too.** No transaction exists in an
  object store, so the atomicity is the same trick as the local set: the halves
  go up first, each is HEADed back at the byte length we sent, and THE MANIFEST
  GOES LAST. `BackupSet::all()` skips a directory with no manifest, so a remote
  prefix holding one half is the bucket's spelling of `*.partial`. If a remote
  manifest is found with a half missing, the manifest is DELETED FIRST — the
  invariant has to hold at every instant, not just at the end.
- **It reuses `BackupSet::problems()` and nothing else decides what a set is.**
  There is no second opinion about completeness anywhere in the shipper, and
  there must never be: two definitions drift, and the one that drifts is the one
  read at 3am.
- **It cannot fail the backup.** By the time it runs, a complete set is written,
  verified and moved into place. A store outage or a rotated credential returns a
  status string; `backup:check` grades it within the day. A shipper able to turn
  a good backup into a failed run is a shipper somebody switches off.
- **Its credentials are its own.** `BACKUP_OFFSITE_*`, never `AWS_*`. The `s3`
  disk in `config/filesystems.php` is the disk media might live on — public
  images. The backup bucket holds a whole organisation's database, children's
  records included. One key pair for both means the credential that serves logos
  can download every backup ever taken.
- **What protects the bytes.** TLS in transit (a non-`https` endpoint is refused
  outright), `x-amz-server-side-encryption` at rest, a private bucket, a key
  scoped to it alone, and 0640 files in a 0750 directory locally. Be exact about
  the limit: SSE-S3 is the provider's key, so it stops a stolen disk and stops
  nothing that holds the bucket credential or the DigitalOcean account.
  Client-side encryption is deliberately NOT implemented rather than half-done —
  a key on the same droplet protects nothing, and a key held elsewhere is a
  custody process that has to survive the incident the backups are for.
- **The same provider is not nowhere, and it is not everywhere.** Spaces covers
  a destroyed droplet, a corrupted volume, a wrong `rm`, ransomware on the host.
  It does not cover a compromised or suspended DigitalOcean account, which
  reaches droplet, database and Space alike. Put the Space in a DIFFERENT region
  from the droplet; a second provider is the next piece of work, not a done one.

## The restore drill

**THE DATABASE HALF OF A RESTORE IS NOW EXECUTED, WEEKLY, AND ONLY INTO A SCRATCH
SCHEMA.** `backup:drill` restores the newest set into a generated schema on the
same managed server, counts the tables, counts the restored `media` rows against
the manifest, and checks each of those rows against files unpacked from the
archive into a private 0700 directory. Then it drops the schema.

- **Not staging.** Staging is deliberately scrubbed of real people, which is what
  makes it safe to hand around; a production set there is a privacy incident
  involving children's records, not a test.
- **Not a local server.** The droplet has the client and no `mysqld`. Installing
  one to prove production is safe is changing production to check production.
- **Five independent guards, each sufficient alone**, set out in `BackupDrill`'s
  docblock: no option can name a target; three assertions run immediately before
  every statement including the DROP (and a live database whose name starts with
  the drill prefix REFUSES the whole run); the decompressed dump is scanned and
  refused if it contains `USE` or `CREATE DATABASE`, because such a dump moves
  the client into the live schema and would overwrite production; every
  invocation carries `--database=<scratch>`; and the media half is unpacked to a
  temporary directory, never the media disk.
- **No scratch schema is left behind.** `finally`, plus a shutdown function for a
  fatal, plus — because neither survives `kill -9` — a SWEEP at the start of
  every run that drops anything matching the prefix. The honest limit is one
  schema between a SIGKILL and the next weekly drill.
- **`backup:drill` does not call `backup:restore`.** That command's whole job is
  to overwrite production, and a `--pretend` flag on it would be one forgotten
  branch away from doing so. What is shared is the definition of a valid set.

## What is deliberately not covered

Stated in full in the header of `bin/backup`, and it must stay accurate: the
off-site copy is BUILT AND SWITCHED OFF (no credentials yet, so today there is
still one copy on one volume, and `backup:check` says so on every run), a
bounded-count retention that refuses rather than part-writes when the volume
fills, and the private disk / `.env` / server configuration are not in it.

**WHAT THE DRILL DOES NOT PROVE:** applying a set to the LIVE schema on top of
existing tables, which is what `backup:restore --force` does and which nothing
may rehearse on production. The drill restores into an empty schema. Do not let
that distinction get quietly rounded off in a later edit: an operator is entitled
to know which half has been run and under what conditions. The relationship
between these sets and DigitalOcean's own managed-database backups is **still
unwritten** — two backup systems for the database and one for the files.
