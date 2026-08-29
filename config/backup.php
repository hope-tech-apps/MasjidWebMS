<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where a backup set is written
    |--------------------------------------------------------------------------
    |
    | One directory per set: `<destination>/<set-id>/` holding `database.sql.gz`,
    | `media.tar.gz` and `manifest.json`. The manifest is written LAST and the
    | directory is renamed into place only once it is there, so a set that exists
    | is a set that finished — see App\Support\Backup\BackupSet.
    |
    | NOT /root/backups, which is where the four legacy dumps live. The system
    | cron runs `schedule:run` as www-data (see deploy/README.md), and www-data
    | cannot write to /root. A destination only root can write to is a scheduled
    | backup that never runs. Create it once with `sudo bin/backup --install`.
    |
    */

    'destination' => env('BACKUP_DESTINATION', '/var/backups/manara'),

    /*
    |--------------------------------------------------------------------------
    | Retention — bounded, and here is the arithmetic
    |--------------------------------------------------------------------------
    |
    | Measured on production (droplet 586894889) on 2026-08-21:
    |
    |   disk                        48G total, 6.4G used, 42G available
    |   database dump, gzipped      ~1.3 MB   (the four dumps in /root/backups)
    |   media disk on the filesystem 11 MB    (91 files in 68 directories)
    |   => one complete set          ~12.5 MB
    |
    | 14 sets is therefore ~175 MB, or 0.4% of the free space. The media half is
    | already-compressed images, so gzip buys little and the figure is honest.
    |
    | WHAT HAPPENS WHEN THE DISK FILLS: nothing silent. `backup:run` measures
    | free space against `headroom_multiple` x the estimated set size BEFORE it
    | writes anything, and refuses the run — logging at `error` with the byte
    | counts — rather than filling the volume the application itself is serving
    | from. Pruning happens only AFTER a set has been written and verified, and
    | never removes the last remaining set, so a failing backup cannot eat the
    | last good one to make room for itself.
    |
    | At 100x the current media (1.1 GB), 14 sets is ~15 GB and this number is
    | the one to lower. It is a count and not a duration on purpose: a count is
    | a hard ceiling on bytes, a duration is not.
    |
    */

    'keep_sets' => (int) env('BACKUP_KEEP_SETS', 14),

    'headroom_multiple' => (float) env('BACKUP_HEADROOM_MULTIPLE', 3.0),

    /*
    |--------------------------------------------------------------------------
    | The database half
    |--------------------------------------------------------------------------
    |
    | `connection` null means config('database.default') — resolved in the
    | command, not here, because config() is not available while config files
    | are still being loaded.
    |
    | The production database is a MANAGED MySQL instance (nothing listens on
    | 3306 on the droplet and mysqld is inactive), reached over the network with
    | `MYSQL_ATTR_SSL_CA` set. Two consequences the defaults below encode:
    |
    |   --no-tablespaces  the managed user has no PROCESS privilege, and without
    |                     this flag MySQL 8 refuses the dump outright
    |   --ssl-ca=         appended automatically from the connection's PDO
    |                     options when it carries MYSQL_ATTR_SSL_CA, so the dump
    |                     is not the one connection in the system that talks to
    |                     the database in the clear
    |
    | --routines and --triggers are included deliberately. If the managed user
    | cannot read them the dump FAILS rather than quietly producing a restore
    | that is missing them; remove them here if that day comes, as a decision
    | somebody made rather than a gap nobody saw.
    |
    */

    'database' => [
        'connection' => env('BACKUP_DB_CONNECTION'),

        'dump_binary' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'restore_binary' => env('BACKUP_MYSQL', 'mysql'),
        'gzip_binary' => env('BACKUP_GZIP', 'gzip'),

        'options' => [
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--hex-blob',
        ],

        /*
         * mysqldump writes this as the last line of a dump it finished. TWO of
         * the seven backup artifacts on this server captured nothing: a 20-byte
         * .sql.gz in /root/backups that decompresses to zero, and a 0-byte .sql
         * in /var/backups/masjid_db. Both are named exactly like the ones that
         * worked, and nobody ever looked inside either. This marker is what
         * `backup:run` looks for before it will call a dump a dump.
         */
        'completion_marker' => '-- Dump completed',

        /*
         * A dump smaller than this is not plausible for this application and is
         * treated as a failed run. The real dumps are ~1.3 MB gzipped.
         */
        'minimum_bytes' => (int) env('BACKUP_DB_MINIMUM_BYTES', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | The file half — the half no backup on this server has ever had
    |--------------------------------------------------------------------------
    |
    | `disk` null means config('media-library.disk_name'), which is `public`
    | today (MEDIA_DISK is not set in the production environment). It is read
    | through media-library's own config rather than hardcoded so that moving
    | media to another disk MOVES THE BACKUP WITH IT instead of leaving this
    | tooling faithfully archiving an empty directory.
    |
    | IF MEDIA EVER MOVES TO S3. `config/filesystems.php` defines an `s3` disk
    | and the production .env already carries AWS_* keys, but no S3 flysystem
    | adapter is installed (composer.lock has league/flysystem-local only) and
    | there is no `aws` binary on the droplet, so that disk cannot serve a byte
    | today. When it can, the file half is `aws s3 sync`, not `tar`, and that is
    | the one line below — `strategies.s3`. Until it is set, a media disk whose
    | driver is s3 makes `backup:run` REFUSE THE WHOLE RUN. It does not fall
    | back to a database-only backup: a database-only backup of this system is
    | the thing that recreates dangling media rows on restore, which is the
    | outage this tooling exists because of.
    |
    | A SET COVERS ONE DISK, AND THE ROWS ARE ASKED WHETHER THAT IS ENOUGH.
    | `backup:run` takes the equivalent of `select distinct disk from media`
    | every run and REFUSES if any row names a disk the archive does not cover.
    | Today every row is on `public` and the answer is always yes — which is a
    | fact about today's configuration, not a property of the design. One row
    | written to `s3` (a half-finished migration, a worker holding a stale
    | MEDIA_DISK) and the archive quietly stops being the file half of the
    | database it is bound to, while the manifest goes on saying
    | `complete: true`. That claim is checked rather than assumed.
    |
    */

    'media' => [
        'disk' => env('BACKUP_MEDIA_DISK'),
        'prefix' => env('BACKUP_MEDIA_PREFIX'),

        'strategies' => [
            'local' => env('BACKUP_LOCAL_STRATEGY', 'tar'),
            's3' => env('BACKUP_S3_STRATEGY'),
        ],

        'tar_binary' => env('BACKUP_TAR', 'tar'),

        /*
         * THE MEDIA-HALF FLOOR. A run whose media root holds fewer regular
         * files than this refuses, before it writes anything.
         *
         * This is the database half's `minimum_bytes` applied to the other
         * half, and it exists because of the ORDER the 2026-08-17 incident
         * happened in: the files were already gone before the 226 rows were
         * deleted. A nightly run inside that window would have dumped a healthy
         * database, tarred an empty directory, verified both — every check in
         * this tooling passing honestly — and then pruned the sets that still
         * held the files. An archive of nothing is not a small backup; it is
         * the disk having been lost, wearing a backup's clothes.
         *
         * 1, not 91: this must not need editing every time the media count
         * changes, and `retention_regression_ratio` below is what catches a
         * PARTIAL loss. Set it to 0 for an installation that genuinely has no
         * media yet — as a decision somebody made, not a gap nobody saw.
         */
        'minimum_files' => (int) env('BACKUP_MEDIA_MINIMUM_FILES', 1),

        /*
         * RETENTION'S ONE QUESTION: did this set capture roughly what the set
         * it is about to replace captured? Below this fraction, the set is
         * still written and verified — it is a faithful capture — but PRUNING
         * IS SKIPPED, because the older sets are then the only copies of the
         * files this one no longer holds, and a backup that deletes the last
         * copy of what went missing is worse than no backup at all.
         *
         * 0.9 rather than 1.0 deliberately. One deleted announcement image is
         * an ordinary Tuesday, and a guard that trips on any decrease at all
         * stops pruning permanently the first time somebody tidies up — which
         * fills the volume the application is serving from and ends with every
         * run refusing for lack of space. 1.0 means "any decrease blocks
         * pruning"; 0.0 disables the guard.
         *
         * The comparison only fires against a set that recorded
         * `halves.media.files`. A set taken before that field existed recorded
         * tar HEADERS and nothing else, and comparing files to headers is
         * precisely the mistake this area was fixed for, so an older set makes
         * the guard inert rather than making it guess.
         */
        'retention_regression_ratio' => (float) env('BACKUP_MEDIA_REGRESSION_RATIO', 0.9),

        /*
         * How far BELOW the live media row count a set may sit before
         * `backup:restore` refuses without `--accept-fewer-rows`.
         *
         * Looser than the retention guard above (half, against its 0.9) and
         * deliberately so: a restore always goes back in time, so a month-old
         * set legitimately misses everything added since, and a command that
         * nags about ordinary staleness is one an operator learns to force past
         * without reading. This is aimed at the other shape — a set taken while
         * the table was empty or nearly so, laid over a populated estate. A set
         * recording ZERO rows is refused whatever this says.
         */
        'restore_shrinkage_ratio' => (float) env('BACKUP_MEDIA_RESTORE_SHRINKAGE_RATIO', 0.5),

        's3' => [
            'sync_binary' => env('BACKUP_AWS_BINARY', 'aws'),
            'staging_path' => env('BACKUP_S3_STAGING'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Process timeout, seconds
    |--------------------------------------------------------------------------
    |
    | A dump of a 1.3 MB database over the network takes seconds. Thirty minutes
    | is room for the day the database is a hundred times larger, and a ceiling
    | so a hung connection cannot leave a half-written set behind forever.
    |
    */

    'process_timeout' => (int) env('BACKUP_PROCESS_TIMEOUT', 1800),

];
