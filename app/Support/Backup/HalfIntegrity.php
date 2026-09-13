<?php

namespace App\Support\Backup;

/**
 * The checks that open a half of a set and decide whether it is really a backup,
 * in ONE place, so that the command which WRITES a set and the command which
 * WATCHES the destination cannot come to different conclusions about the same
 * file.
 *
 * WHY THIS WAS EXTRACTED
 *
 * `backup:run` has always verified what it just wrote — a dump must decompress,
 * clear a size floor and end with the `-- Dump completed` line mysqldump writes
 * only when it finished, because /root/backups/pre-forms-migration-20260728-233129.sql.gz
 * is 20 bytes, decompresses to nothing, and `gzip -t` calls it healthy. That
 * check ran once, at 02:40, against a file that was thirty seconds old.
 *
 * `backup:check` has to ask the same question of a file that is now a day old
 * and has been sitting on a disk that could have filled, been truncated by a
 * failing volume, or been part-overwritten. Writing those checks a second time
 * in a second command guarantees the two drift, and the one that drifts is the
 * one nobody re-reads. So the sentences below are the originals, moved, and
 * `BackupRun` calls them rather than carrying its own copy.
 *
 * `BackupSet::problems()` is the other half of this and stays where it is: it
 * answers "is this set internally consistent and unmodified since it was taken"
 * from the manifest, without decompressing anything. This class answers the
 * question that needs the bytes opened.
 */
final class HalfIntegrity
{
    /**
     * Is this database half a dump, or is it a file shaped like one?
     *
     * Returns null when it is sound, and one operator-facing sentence when it is
     * not. TWO of the seven backup artefacts on this server captured nothing —
     * a 20-byte .sql.gz that decompresses to zero, and a 0-byte .sql — and both
     * are named exactly like the ones that worked. Nothing ever looked inside
     * either. Every clause here exists because one of those two got past the
     * check above it.
     *
     * @param  array<string, mixed>  $config  config('backup')
     */
    public static function database(string $file, array $config): ?string
    {
        $minimum = (int) ($config['database']['minimum_bytes'] ?? 4096);
        $bytes = is_file($file) ? (int) filesize($file) : 0;

        if ($bytes < $minimum) {
            return sprintf(
                '%s is %d bytes, under the %d-byte floor. /root/backups/pre-forms-migration-20260728-233129.sql.gz is 20 bytes and decompresses to nothing; that is what this floor is for.',
                BackupSet::DATABASE_FILE,
                $bytes,
                $minimum,
            );
        }

        $gz = ArchiveIntegrity::inspectGzip($file);

        if (! $gz['ok']) {
            return sprintf('%s: %s', BackupSet::DATABASE_FILE, $gz['error']);
        }

        if ($gz['bytes'] === 0) {
            return sprintf('%s is a valid gzip stream that decompresses to nothing — the dump captured no rows.', BackupSet::DATABASE_FILE);
        }

        $marker = (string) ($config['database']['completion_marker'] ?? '-- Dump completed');

        if ($marker !== '' && ! str_contains($gz['tail'], $marker)) {
            return sprintf(
                '%s does not end with "%s", the line mysqldump writes only when it finished. The dump was truncated.',
                BackupSet::DATABASE_FILE,
                $marker,
            );
        }

        return null;
    }

    /**
     * Does the media half still hold the files its own manifest says it holds?
     *
     * Null when it does. This walks every tar header in the compressed stream,
     * so it catches the archive that was truncated after the checksum was taken
     * — and it compares FILES to FILES, never headers to files. A tar header is
     * not a file: `tar -czf … -C root .` writes one for every directory, GNU tar
     * writes an `L` record for any path over 99 bytes, and a symlink is a record
     * with no data. The production disk is 91 files in 68 DIRECTORIES, so an
     * archive holding a third of the files still carries more headers than the
     * disk has files, and that arithmetic is exactly how a short archive passed
     * this check once already. See ArchiveIntegrity::countTarEntries().
     *
     * A manifest written before the `files` field existed recorded tar headers
     * and nothing else. Comparing against it would be the same mistake in the
     * other direction, so an old set makes the comparison inert (the archive is
     * still walked, so a corrupt one is still caught) rather than making it
     * guess.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function mediaAgainstManifest(string $file, array $manifest): ?string
    {
        $walk = ArchiveIntegrity::countTarEntries($file);

        if (! $walk['ok']) {
            return sprintf('%s: %s', BackupSet::MEDIA_FILE, $walk['error']);
        }

        $recorded = $manifest['halves']['media']['files'] ?? null;
        $recorded = is_int($recorded) || (is_string($recorded) && ctype_digit($recorded)) ? (int) $recorded : null;

        if ($recorded === null) {
            return null;
        }

        if ($walk['files'] < $recorded) {
            return sprintf(
                '%s holds %d regular file(s) across %d tar header(s); its own manifest recorded %d. The archive has lost '
                .'files since it was verified, so the file half of this set is no longer the file half of the database beside it.',
                BackupSet::MEDIA_FILE,
                $walk['files'],
                $walk['entries'],
                $recorded,
            );
        }

        return null;
    }
}
