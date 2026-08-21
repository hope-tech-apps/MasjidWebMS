<?php

namespace App\Support\Backup;

/**
 * Which files the file half of a backup has to reach, and how.
 *
 * This is the answer to "where does media live", derived from configuration
 * rather than written down twice. `config('media-library.disk_name')` names a
 * disk, `config('filesystems.disks.*')` says what that disk IS, and the driver
 * decides whether the archive step is `tar` over a directory or a sync out of a
 * bucket. Change MEDIA_DISK and the backup follows; nothing here is hardcoded to
 * `public` or to `storage/app/public`.
 *
 * It is a pure function of two config arrays — no filesystem, no container — so
 * every branch below is unit-testable, including the ones that matter most: the
 * refusals. See tests/Unit/Backup/MediaTargetTest.php.
 *
 * WHY AN UNSUPPORTED DISK IS A REFUSAL AND NOT A WARNING
 *
 * The failure this whole slice is a reaction to is a set of database rows that
 * outlived their files. Every backup on this server is database-only, so
 * restoring one RE-CREATES that state: 226 media rows pointing at 226 files that
 * are not there. A backup that silently covers half of a pair is not a partial
 * backup, it is a loaded gun — so when the file half cannot be reached, this
 * class returns unsupported() and the command stops BEFORE it dumps anything.
 * There is no path through `backup:run` that writes a database-only set.
 */
final class MediaTarget
{
    public const STRATEGY_TAR = 'tar';

    public const STRATEGY_S3_SYNC = 's3-sync';

    private function __construct(
        public readonly string $disk,
        public readonly ?string $driver,
        public readonly ?string $strategy,
        public readonly ?string $root,
        public readonly string $prefix,
        public readonly ?string $bucket,
        public readonly ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $backup  config('backup')
     * @param  array<string, array<string, mixed>>  $disks  config('filesystems.disks')
     * @param  string|null  $mediaLibraryDisk  config('media-library.disk_name')
     * @param  string|null  $mediaLibraryPrefix  config('media-library.prefix')
     */
    public static function resolve(
        array $backup,
        array $disks,
        ?string $mediaLibraryDisk,
        ?string $mediaLibraryPrefix = null,
    ): self {
        $media = $backup['media'] ?? [];

        // BACKUP_MEDIA_DISK is an override for the day the two need to differ
        // (a migration, a one-off archive of the old disk). Unset — which is
        // the production state — means "whatever media-library is using", so
        // the two cannot drift.
        $disk = $media['disk'] ?? null;
        $disk = ($disk === null || $disk === '') ? $mediaLibraryDisk : $disk;

        $prefix = $media['prefix'] ?? null;
        $prefix = ($prefix === null || $prefix === '') ? (string) ($mediaLibraryPrefix ?? '') : (string) $prefix;
        $prefix = trim($prefix, '/');

        if ($disk === null || $disk === '') {
            return self::unsupported('', $prefix, 'No media disk is configured: both backup.media.disk and media-library.disk_name are empty.');
        }

        if (! array_key_exists($disk, $disks)) {
            return self::unsupported($disk, $prefix, sprintf(
                'Media is configured on disk [%s], which config/filesystems.php does not define. Known disks: %s.',
                $disk,
                implode(', ', array_keys($disks)) ?: '(none)',
            ));
        }

        $driver = $disks[$disk]['driver'] ?? null;
        $strategy = $media['strategies'][$driver] ?? null;
        $strategy = ($strategy === null || $strategy === '') ? null : $strategy;

        if ($driver === 'local') {
            $root = $disks[$disk]['root'] ?? null;

            if ($root === null || $root === '') {
                return self::unsupported($disk, $prefix, sprintf(
                    'Disk [%s] is a local disk with no root configured, so there is no directory to archive.',
                    $disk,
                ), $driver);
            }

            if ($strategy !== self::STRATEGY_TAR) {
                return self::unsupported($disk, $prefix, sprintf(
                    'Disk [%s] uses the local driver but backup.media.strategies.local is [%s]; the only implemented local strategy is [%s].',
                    $disk,
                    $strategy ?? 'null',
                    self::STRATEGY_TAR,
                ), $driver);
            }

            return new self(
                disk: $disk,
                driver: $driver,
                strategy: self::STRATEGY_TAR,
                root: rtrim($root, '/'),
                prefix: $prefix,
                bucket: null,
                reason: null,
            );
        }

        // Everything that is not a local directory. Today that is s3, and today
        // it has no strategy, so this is a refusal that names the one setting
        // that turns it into a supported target.
        if ($strategy === null) {
            return self::unsupported($disk, $prefix, sprintf(
                'Media is on disk [%s] (driver [%s]) and no backup strategy is configured for that driver. '
                .'Set backup.media.strategies.%s (env BACKUP_%s_STRATEGY) — for s3 that is "%s", which needs the aws CLI on the host. '
                .'Refusing to take a database-only backup: restoring one re-creates media rows whose files are gone.',
                $disk,
                $driver ?? 'null',
                $driver ?? 'unknown',
                strtoupper((string) $driver),
                self::STRATEGY_S3_SYNC,
            ), $driver);
        }

        if ($driver === 's3' && $strategy === self::STRATEGY_S3_SYNC) {
            $bucket = $disks[$disk]['bucket'] ?? null;

            if ($bucket === null || $bucket === '') {
                return self::unsupported($disk, $prefix, sprintf(
                    'Disk [%s] is an s3 disk with no bucket configured (AWS_BUCKET is empty), so there is nothing to sync.',
                    $disk,
                ), $driver);
            }

            return new self(
                disk: $disk,
                driver: $driver,
                strategy: self::STRATEGY_S3_SYNC,
                root: null,
                prefix: $prefix,
                bucket: $bucket,
                reason: null,
            );
        }

        return self::unsupported($disk, $prefix, sprintf(
            'Media is on disk [%s] (driver [%s]) with strategy [%s], which this command does not implement.',
            $disk,
            $driver ?? 'null',
            $strategy,
        ), $driver);
    }

    private static function unsupported(string $disk, string $prefix, string $reason, ?string $driver = null): self
    {
        return new self(
            disk: $disk,
            driver: $driver,
            strategy: null,
            root: null,
            prefix: $prefix,
            bucket: null,
            reason: $reason,
        );
    }

    public function isSupported(): bool
    {
        return $this->strategy !== null;
    }

    /**
     * The absolute directory the archive is taken from, prefix included.
     * Null for anything that is not a local disk.
     */
    public function archiveRoot(): ?string
    {
        if ($this->root === null) {
            return null;
        }

        return $this->prefix === '' ? $this->root : $this->root.'/'.$this->prefix;
    }

    /**
     * The disk-relative path media-library would store media {id}/{file} under,
     * so a caller can ask the disk whether the file behind a row is really there.
     */
    public function relativePathFor(int|string $mediaId, string $fileName): string
    {
        $path = $mediaId.'/'.$fileName;

        return $this->prefix === '' ? $path : $this->prefix.'/'.$path;
    }

    public function describe(): string
    {
        if (! $this->isSupported()) {
            return sprintf('disk [%s] — UNSUPPORTED: %s', $this->disk, $this->reason);
        }

        return $this->strategy === self::STRATEGY_TAR
            ? sprintf('disk [%s] (local) -> tar of %s', $this->disk, $this->archiveRoot())
            : sprintf('disk [%s] (s3) -> aws s3 sync of s3://%s/%s', $this->disk, $this->bucket, $this->prefix);
    }

    /** @return array<string, mixed> */
    public function toManifest(): array
    {
        return array_filter([
            'disk' => $this->disk,
            'driver' => $this->driver,
            'strategy' => $this->strategy,
            'root' => $this->archiveRoot(),
            'prefix' => $this->prefix,
            'bucket' => $this->bucket,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
