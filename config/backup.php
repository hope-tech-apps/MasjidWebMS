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

    /*
    |--------------------------------------------------------------------------
    | The watchdog — because a backup that stops happening is silent
    |--------------------------------------------------------------------------
    |
    | On 2026-09-12 this platform was found never to have taken a backup. Not a
    | stale one: none, ever. `backup:run` had been scheduled nightly at 02:40 for
    | weeks, /var/backups/manara did not exist because `sudo bin/backup --install`
    | was never run on that box, and the command refused correctly every single
    | night with the sentence "The backup destination is not usable, so nothing
    | was written." Nobody ever saw it, because the cron line ends in
    | `>> /dev/null 2>&1` and nothing else was watching.
    |
    | Every check in `backup:run` worked. The gap was that a REFUSAL and a
    | SUCCESS are indistinguishable from the outside, and the outside is where
    | everybody stands.
    |
    | `backup:check` is the outside. It does not watch `backup:run` — watching a
    | command tells you nothing on the nights it does not run, which was every
    | night — it looks at the DESTINATION and asks how old the newest verified
    | set is. That is a pull, not a push, and it is the difference between a
    | watchdog that can be starved into silence and one that cannot.
    |
    */

    'check' => [

        /*
         * WHY THIS DEFAULTS TO `monitors` AND THE OTHER MONITORS DEFAULT TO null.
         *
         * `media:verify` and `tenancy:canary` both take a log channel from env
         * and default to the application's own — which means their email path
         * is OFF until somebody edits .env, and this task exists because the one
         * thing nobody did was the one manual step. So this one defaults to the
         * `monitors` stack (config/logging.php): the ordinary file line, plus
         * `ops-alerts`, which emails the operator at level `error` and is inert
         * while OPS_ALERT_EMAIL is unset. The stack sets `ignore_exceptions`, so
         * a mail failure can never take down the file line beside it.
         *
         * Setting OPS_ALERT_EMAIL is therefore the single switch that turns the
         * whole on-call contract on, for this and for anything else pointed at
         * `monitors`. Until it is set, this channel costs one extra no-op
         * handler per run and nothing else.
         */
        'log_channel' => env('BACKUP_CHECK_LOG_CHANNEL', 'monitors'),

        /*
         * HOW OLD THE NEWEST VERIFIED SET MAY BE BEFORE THIS IS AN EMERGENCY.
         *
         * 36 hours, not 24: `backup:run` is daily at 02:40, so a threshold of 24
         * would page on any night the run was ten minutes late, and an alarm
         * that fires on an ordinary Tuesday is an alarm that gets silenced —
         * which is how this platform got here. 36 is one missed nightly run plus
         * twelve hours of slack, so the FIRST missed night pages and no ordinary
         * night does.
         *
         * It is measured from the manifest's `created_at`, which is written by
         * the run that took the set, not from the file's mtime, which an rsync
         * or a `cp -p` can carry across from somewhere else.
         */
        'max_age_hours' => (int) env('BACKUP_CHECK_MAX_AGE_HOURS', 36),

        /*
         * THE CHECKER'S OWN HEARTBEAT.
         *
         * A checker whose own failure is invisible reproduces the bug it exists
         * to catch, so `backup:check` records every run here and reports the gap
         * since its previous one. A check that has been dead for a week and then
         * runs says so in its own output, and an operator reading the file has a
         * history rather than a single line.
         *
         * storage/app/ for the reasons media:verify's baseline lives there: it
         * survives a `git checkout` into the live tree, survives `cache:clear`,
         * and is not in the database — a record of whether the backups are
         * healthy must not live in the thing the backups exist to restore.
         *
         * Losing the file costs one run's self-history and nothing else.
         */
        'heartbeat_path' => env('BACKUP_CHECK_HEARTBEAT_PATH', storage_path('app/backup-check/last-run.json')),

        'self_gap_hours' => (int) env('BACKUP_CHECK_SELF_GAP_HOURS', 36),

        /*
         * THE DEAD MAN'S SWITCH, AND THE ONLY PART OF THIS THAT SURVIVES THE HOST.
         *
         * Everything else here is in-band: a heartbeat file on the disk being
         * watched, a log line written by the process being watched, an email
         * sent by the application being watched. If `schedule:run` stops, if
         * cron stops, if the droplet stops, EVERY ONE of those goes quiet
         * together and quiet is exactly what a healthy night looks like. That is
         * the residual shape of the original bug and no amount of in-band
         * checking removes it.
         *
         * The only construction that does is an outside observer expecting to
         * hear from us. Set this to a URL that alerts when it is NOT called
         * (healthchecks.io, Better Stack, a DigitalOcean uptime check against a
         * push endpoint) and the check pings it after a CLEAN run only — never
         * after a failing one, so a failure and a silence both raise the alarm
         * and only a genuinely healthy platform is quiet.
         *
         * Unset, as it is today, the checker's own silence is DETECTABLE (the
         * heartbeat file and the missing log line both show it) but not
         * ALERTED. That is the honest state and it is worth writing down rather
         * than implying otherwise.
         */
        'heartbeat_url' => env('BACKUP_CHECK_HEARTBEAT_URL'),

        'heartbeat_timeout' => (int) env('BACKUP_CHECK_HEARTBEAT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | The off-site copy — OFF, and off honestly
    |--------------------------------------------------------------------------
    |
    | `bin/backup` has said in capitals since it was written that there is ONE
    | COPY, ON ONE VOLUME, ON THE MACHINE IT IS BACKING UP: /var/backups/manara
    | sits on the same 48G root filesystem as the application, so a destroyed
    | droplet, a corrupted disk or a wrong `rm` takes the backups with the thing
    | they were backing up. This block is what closes that, and it is switched
    | OFF because there are no credentials for it yet.
    |
    | IT DOES NOT READ AWS_*. Production's AWS_ACCESS_KEY_ID and friends exist
    | and are EMPTY, and they belong to the `s3` disk in config/filesystems.php —
    | the disk MEDIA might live on one day, serving public images. The backup
    | bucket is the opposite kind of object: private, write-mostly, and holding
    | an entire organisation's database including records about children. One key
    | pair for both means the credential that serves logos can also download
    | every backup ever taken. See App\Support\Backup\OffsiteTarget.
    |
    | WHAT DIGITALOCEAN SPACES DOES AND DOES NOT BUY. It is the same provider as
    | the droplet and the managed database, so it covers the failures that are
    | actually likely — the droplet destroyed or rebuilt, the volume corrupted, a
    | wrong `rm`, a deploy that eats the disk, ransomware on the host. It does
    | NOT cover a DigitalOcean account compromise or suspension, because one
    | login reaches all three. Put the Space in a DIFFERENT REGION from the
    | droplet so a regional outage is not a single event, and treat a periodic
    | copy at a second provider as the next piece of work rather than as done.
    |
    */

    'offsite' => [

        /*
         * The master switch, explicit rather than inferred from "are the keys
         * filled in". Inferring it means a half-finished .env edit — a bucket
         * named, the secret not yet pasted — reads as "off-site is off" instead
         * of "off-site is broken", and the difference between those two is
         * whether anybody is told.
         */
        'enabled' => filter_var(env('BACKUP_OFFSITE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'bucket' => env('BACKUP_OFFSITE_BUCKET'),

        /*
         * For DigitalOcean Spaces the region is the datacentre slug in the
         * endpoint — nyc3, ams3, sgp1 — and it is part of the SigV4 credential
         * scope, so a wrong value fails every request with a signature error
         * that reads like a bad secret.
         */
        'region' => env('BACKUP_OFFSITE_REGION'),

        /*
         * The regional endpoint WITHOUT the bucket: https://nyc3.digitaloceanspaces.com.
         * The bucket is prepended to the host (virtual-hosted style) unless
         * `path_style` is on, which is for the S3-compatible stores that only
         * serve the older shape.
         */
        'endpoint' => env('BACKUP_OFFSITE_ENDPOINT'),

        'path_style' => filter_var(env('BACKUP_OFFSITE_PATH_STYLE', false), FILTER_VALIDATE_BOOLEAN),

        'prefix' => env('BACKUP_OFFSITE_PREFIX', 'manara'),

        'key' => env('BACKUP_OFFSITE_KEY'),

        'secret' => env('BACKUP_OFFSITE_SECRET'),

        /*
         * Sent as `x-amz-server-side-encryption`. AES256 is SSE-S3: the store
         * encrypts the object at rest with a key IT holds and manages.
         *
         * Be exact about what that is worth. It protects the bytes against
         * somebody walking out of a datacentre with a disk. It protects them
         * against NOTHING that has the bucket credential or the DigitalOcean
         * account, because the store decrypts transparently for anyone who can
         * read the object. These sets contain children's records and private
         * media, so the protections that actually carry weight here are: a
         * PRIVATE bucket, a key scoped to that bucket alone, TLS on the wire
         * (OffsiteTarget refuses a non-https endpoint outright), and the local
         * set's 0640 mode inside a 0750 directory.
         *
         * Client-side encryption before upload — where the store holds only
         * ciphertext — is deliberately NOT implemented rather than half-done. A
         * key kept on the same droplet protects against nothing, since anyone
         * who can read the backups can read the key beside them; a key kept
         * elsewhere is a custody process that has to survive the very incident
         * the backups are for, and losing it turns every set into noise. That is
         * a decision to make on purpose, with somewhere to put the key, not a
         * config default to sneak in.
         */
        'encryption' => env('BACKUP_OFFSITE_SSE', 'AES256'),

        /*
         * Refuse rather than half-do. Above this, a single PUT is not allowed by
         * S3 and the correct answer is multipart upload, which is not written
         * here — an abandoned multipart leaves parts in the bucket that bill
         * every month and restore nothing. Capped at 5 GiB by OffsiteTarget
         * whatever this says.
         */
        'max_object_bytes' => (int) env('BACKUP_OFFSITE_MAX_OBJECT_BYTES', 5 * 1024 * 1024 * 1024),

        'timeout' => (int) env('BACKUP_OFFSITE_TIMEOUT', 900),

        /*
         * Turn this on once the off-site copy is provisioned and expected to
         * work. It changes `backup:check`'s verdict, not its output: while it is
         * false, a missing off-site copy is stated loudly on every run and does
         * not page — because today that is a known, accepted, ticketed gap and
         * an amber that burns every ordinary night is an amber that gets
         * silenced. Once `enabled` is true this is implied: a configured
         * off-site copy that is not there is an error whatever this says.
         */
        'required' => filter_var(env('BACKUP_OFFSITE_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | The restore drill — because an unpractised restore is not a backup
    |--------------------------------------------------------------------------
    |
    | `bin/backup` carries the sentence THE DATABASE HALF OF A RESTORE HAS NEVER
    | BEEN EXECUTED, and the reason: this droplet has the mysql CLIENT and no
    | server (`/usr/sbin/mysqld` and `/usr/sbin/mariadbd` do not exist, both
    | units read `inactive`), and the database is a DigitalOcean MANAGED instance.
    |
    | `backup:drill` executes it, on the managed server, into a SCRATCH SCHEMA it
    | creates and drops. Not onto staging: staging is deliberately scrubbed of
    | real people (see App\Console\Commands\StagingScrub), and shipping a
    | production set there would put real children's records on the box that gets
    | handed around for testing — trading a backup problem for a privacy
    | incident. Not onto a local mysqld either, because installing a database
    | server on the production application host to prove production is safe is
    | changing production to check production.
    |
    | Every safety property of the drill is in App\Console\Commands\BackupDrill's
    | docblock, where the code that enforces each one is next to it.
    |
    */

    'drill' => [

        'log_channel' => env('BACKUP_DRILL_LOG_CHANNEL', 'monitors'),

        /*
         * Every scratch schema this command creates begins with this, every
         * schema it drops must begin with this, and the run REFUSES outright if
         * the live database name begins with it. Three uses of one string, so
         * there is no arrangement of configuration in which the drill's DROP can
         * name the live schema.
         */
        'scratch_prefix' => env('BACKUP_DRILL_SCRATCH_PREFIX', 'manara_drill_'),

        /*
         * The cross-check pulls id and file_name for the restored media rows and
         * looks for each file in the unpacked archive. Bounded for the reason
         * media:verify bounds the same walk: past the ceiling the drill reports
         * `partial` and names the truncation rather than reporting a clean
         * result it did not earn.
         */
        'max_rows' => (int) env('BACKUP_DRILL_MAX_ROWS', 25000),

        /*
         * A restored dump that produces almost no tables restored almost
         * nothing, however cleanly the client exited. The real schema is well
         * over a hundred tables; 20 is a floor that cannot be met by an empty or
         * half-applied dump and cannot fire on an ordinary one.
         */
        'minimum_tables' => (int) env('BACKUP_DRILL_MINIMUM_TABLES', 20),
    ],

];
