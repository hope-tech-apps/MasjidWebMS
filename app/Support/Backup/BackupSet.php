<?php

namespace App\Support\Backup;

/**
 * One backup: a database dump AND the media files that database's rows point at,
 * bound together by a manifest.
 *
 * A "set" is a directory:
 *
 *     <destination>/20260821-024000/
 *         database.sql.gz
 *         media.tar.gz
 *         manifest.json
 *
 * THE MANIFEST IS THE SET. It is written last, and `backup:run` assembles into
 * `<id>.partial/` and renames into place only once the manifest is there, so a
 * directory that exists is a run that finished. A killed run leaves a `.partial`
 * that nothing will ever restore and the next run can sweep. There is no state
 * in which half a set looks like a whole one.
 *
 * WHY THIS CLASS EXISTS SEPARATELY FROM THE COMMANDS
 *
 * `problems()` is the refusal logic — every reason a restore must not proceed —
 * and it is the part of this slice most worth testing, so it lives where a test
 * can call it with a directory it built itself, no processes and no database.
 * Both `backup:run` (which verifies what it just wrote) and `backup:restore`
 * (which verifies what it is about to restore) go through this one function, so
 * the two cannot disagree about what a valid set is.
 *
 * THE LEGACY DUMPS ARE NOT SETS
 *
 * /root/backups holds four `*.sql.gz` files from before this existed. Pointed at
 * one, `problems()` says so in the sentence that matters: restoring a
 * database-only dump re-creates rows whose files are gone, which is the outage.
 */
final class BackupSet
{
    public const MANIFEST_FILE = 'manifest.json';

    public const DATABASE_FILE = 'database.sql.gz';

    public const MEDIA_FILE = 'media.tar.gz';

    public const MANIFEST_VERSION = 1;

    private function __construct(public readonly string $path) {}

    public static function at(string $path): self
    {
        return new self(rtrim($path, '/'));
    }

    /**
     * Every finished set in a destination, NEWEST FIRST — ordered by when each
     * was taken, not by the name it was given.
     *
     * The id sorts chronologically only while every set is a bare timestamp. A
     * labelled one (`--label=pre-r9`) begins with a letter, and letters sort
     * above digits, so ordering on the name alone would make a pre-deploy backup
     * from March permanently "the newest set" — which decides both what
     * `backup:restore` picks by default and what retention deletes.
     *
     * @return list<self>
     */
    public static function all(string $destination): array
    {
        $destination = rtrim($destination, '/');

        if (! is_dir($destination)) {
            return [];
        }

        $rows = [];

        foreach ((array) scandir($destination) as $entry) {
            if ($entry === '.' || $entry === '..' || str_ends_with((string) $entry, '.partial')) {
                continue;
            }

            $path = $destination.'/'.$entry;
            $manifestPath = $path.'/'.self::MANIFEST_FILE;

            if (! is_dir($path) || ! is_file($manifestPath)) {
                continue;
            }

            $set = self::at($path);
            $createdAt = $set->manifest()['created_at'] ?? null;

            $rows[] = [
                'set' => $set,
                // A manifest with no timestamp (or an unreadable one) falls back
                // to when it was written, which is the same instant in practice.
                'taken' => is_string($createdAt) ? strtotime($createdAt) : filemtime($manifestPath),
            ];
        }

        usort($rows, function (array $a, array $b) {
            return ($b['taken'] <=> $a['taken']) ?: strcmp($b['set']->id(), $a['set']->id());
        });

        return array_map(fn (array $row) => $row['set'], $rows);
    }

    public static function latest(string $destination): ?self
    {
        return self::all($destination)[0] ?? null;
    }

    public function id(): string
    {
        return basename($this->path);
    }

    public function manifestPath(): string
    {
        return $this->path.'/'.self::MANIFEST_FILE;
    }

    public function databasePath(): string
    {
        return $this->path.'/'.self::DATABASE_FILE;
    }

    public function mediaPath(): string
    {
        return $this->path.'/'.self::MEDIA_FILE;
    }

    /** @return array<string, mixed>|null */
    public function manifest(): ?array
    {
        if (! is_file($this->manifestPath())) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->manifestPath()), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Every reason this set must not be restored. Empty means restorable.
     *
     * Checksums are verified by default and are the expensive part (two sha256
     * passes over the set). `$verifyChecksums = false` is for listing many sets,
     * never for deciding to restore one.
     *
     * @return list<string>
     */
    public function problems(bool $verifyChecksums = true): array
    {
        if (is_file($this->path) && str_ends_with($this->path, '.sql.gz')) {
            return [sprintf(
                '%s is a database-only dump, not a backup set. Restoring it re-creates every media row '
                .'pointing at a file that is not on this server — the state that emptied the announcement '
                .'feed and the masjid logos. The files it needs were never in it. Do not restore it alone.',
                basename($this->path),
            )];
        }

        if (! is_dir($this->path)) {
            return [sprintf('No backup set at %s.', $this->path)];
        }

        $manifest = $this->manifest();

        if ($manifest === null) {
            return [sprintf(
                '%s has no readable %s, so nothing binds a database dump to the media files it needs. '
                .'A set is only finished once its manifest is written; this directory is either a '
                .'crashed run or not a backup set at all.',
                $this->path,
                self::MANIFEST_FILE,
            )];
        }

        $problems = [];

        $version = $manifest['manifest_version'] ?? null;

        if ($version !== self::MANIFEST_VERSION) {
            $problems[] = sprintf(
                'Manifest version is %s; this build understands version %d only.',
                var_export($version, true),
                self::MANIFEST_VERSION,
            );

            // Nothing below can be trusted against an unknown schema.
            return $problems;
        }

        if (($manifest['complete'] ?? false) !== true) {
            $problems[] = sprintf(
                'The manifest records this set as INCOMPLETE (%s). Half a pair cannot be restored: the '
                .'database half alone re-creates rows whose files are missing, and the media half alone '
                .'restores files nothing references.',
                $manifest['incomplete_reason'] ?? 'no reason recorded',
            );
        }

        foreach ([
            'database' => $this->databasePath(),
            'media' => $this->mediaPath(),
        ] as $half => $file) {
            $recorded = $manifest['halves'][$half] ?? null;

            if (! is_array($recorded)) {
                $problems[] = sprintf('The manifest does not describe the %s half of this set.', $half);

                continue;
            }

            if (! is_file($file)) {
                $problems[] = sprintf(
                    'The %s half (%s) is named by the manifest and is not in the set. Restoring the other '
                    .'half alone is exactly the failure this pairing exists to prevent.',
                    $half,
                    basename($file),
                );

                continue;
            }

            $bytes = (int) filesize($file);

            if (isset($recorded['bytes']) && (int) $recorded['bytes'] !== $bytes) {
                $problems[] = sprintf(
                    'The %s half is %d bytes; the manifest recorded %d. It has been truncated or replaced.',
                    $half,
                    $bytes,
                    (int) $recorded['bytes'],
                );

                continue;
            }

            if ($verifyChecksums && isset($recorded['sha256'])) {
                $actual = hash_file('sha256', $file);

                if (! hash_equals((string) $recorded['sha256'], (string) $actual)) {
                    $problems[] = sprintf(
                        'The %s half does not match its recorded sha256. The bytes changed after the '
                        .'backup was verified; this set is not the set that was taken.',
                        $half,
                    );
                }
            }
        }

        return $problems;
    }

    public function isRestorable(bool $verifyChecksums = true): bool
    {
        return $this->problems($verifyChecksums) === [];
    }

    /** Total bytes on disk for this set. */
    public function bytes(): int
    {
        $total = 0;

        foreach ([$this->databasePath(), $this->mediaPath(), $this->manifestPath()] as $file) {
            if (is_file($file)) {
                $total += (int) filesize($file);
            }
        }

        return $total;
    }

    /** Remove the set. Used only by retention pruning, which never prunes the last one. */
    public function delete(): void
    {
        if (! is_dir($this->path)) {
            return;
        }

        foreach ((array) (@scandir($this->path) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $this->path.'/'.$entry;

            if (is_file($child) || is_link($child)) {
                @unlink($child);
            }
        }

        @rmdir($this->path);
    }
}
