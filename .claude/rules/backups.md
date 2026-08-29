---
paths:
  - "bin/backup"
  - "config/backup.php"
  - "app/Console/Commands/BackupRun.php"
  - "app/Console/Commands/BackupRestore.php"
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

## What is deliberately not covered

Stated in full in the header of `bin/backup`, and it must stay accurate: one copy
on the same volume as the application (**no offsite**), a bounded-count retention
that refuses rather than part-writes when the volume fills, and the private disk
/ `.env` / server configuration are not in it.

**THE DATABASE HALF OF A RESTORE HAS NEVER BEEN EXECUTED.** There is nowhere
permitted to execute it: the droplet is the production application host and
carries the mysql CLIENT with no server binary (`/usr/sbin/mysqld` and
`/usr/sbin/mariadbd` do not exist; both units are `inactive`), and the database
is a DigitalOcean managed instance that is off limits. The file half, the
refusals, and the delivery of the dump to a real child process on stdin ARE
executed — see `BackupRestoreDatabaseHalfTest`, which states the one assumption
it encodes in its own docblock. Do not let that distinction get quietly rounded
off in a later edit: an operator is entitled to know which half has been run. The
relationship between these sets and DigitalOcean's own managed-database backups
is **still unwritten** — two backup systems for the database and one for the
files.
