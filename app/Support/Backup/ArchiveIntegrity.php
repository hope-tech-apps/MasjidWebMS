<?php

namespace App\Support\Backup;

/**
 * Proof that a file a backup just wrote is actually a backup.
 *
 * /root/backups/pre-forms-migration-20260728-233129.sql.gz is 20 bytes. It is a
 * VALID gzip stream that decompresses to zero bytes: a dump that captured
 * nothing, written on 2026-07-28, still sitting there under a name identical in
 * shape to the four that worked. `gzip -t` calls it healthy. The only way to
 * tell it apart from a real dump without restoring it is to look INSIDE, which
 * is what this class does:
 *
 *   - a database half must decompress, be over a floor, and end with the
 *     `-- Dump completed` line mysqldump writes only when it finished;
 *   - a media half must decompress and contain at least as many REGULAR FILES
 *     as there were regular files in the directory it was taken from. Files,
 *     not headers — see countTarEntries() for why that distinction is the
 *     whole check.
 *
 * Both walk the compressed stream in PHP rather than shelling out, so a
 * verification cannot itself be defeated by the pipeline problem that produced
 * the empty file (a `mysqldump | gzip > out` reports gzip's exit status, not
 * mysqldump's — see BackupRun, which does not use a pipe for exactly that
 * reason).
 */
final class ArchiveIntegrity
{
    /**
     * Stream a gzip file and report whether it decompresses, how large it is
     * when it does, and its last bytes.
     *
     * @return array{ok: bool, error: ?string, bytes: int, tail: string}
     */
    public static function inspectGzip(string $path, int $tailBytes = 4096): array
    {
        $fail = fn (string $why) => ['ok' => false, 'error' => $why, 'bytes' => 0, 'tail' => ''];

        if (! is_file($path)) {
            return $fail('file does not exist');
        }

        if (! extension_loaded('zlib')) {
            return $fail('PHP has no zlib extension, so this archive cannot be verified — and an unverified backup is not a backup');
        }

        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            return $fail('not readable as a gzip stream');
        }

        $bytes = 0;
        $tail = '';

        try {
            while (! gzeof($handle)) {
                $chunk = gzread($handle, 262144);

                if ($chunk === false) {
                    return $fail('the gzip stream is corrupt');
                }

                if ($chunk === '') {
                    break;
                }

                $bytes += strlen($chunk);
                $tail = substr($tail.$chunk, -$tailBytes);
            }
        } finally {
            gzclose($handle);
        }

        return ['ok' => true, 'error' => null, 'bytes' => $bytes, 'tail' => $tail];
    }

    /**
     * Walk a gzipped tar's headers and count what is in it, separating the
     * headers that are FILES from the headers that are merely headers.
     *
     * WHY THE SEPARATION IS THE ENTIRE CHECK
     *
     * A tar header is not a file. `tar -czf … -C root .` writes a header for
     * every DIRECTORY as well as every file, GNU tar writes an extra `L`
     * record ahead of any path longer than 99 bytes, and a symlink is a `2`
     * record carrying no data at all. The production media disk is 91 files in
     * 68 directories, so its archive holds roughly 160 headers — which means an
     * archive containing 91 headers and THIRTY files would still clear a floor
     * of "at least 91". Counting headers against a file count compares unlike
     * things, and the file half being whole is the one guarantee a set has that
     * the four legacy database-only dumps did not.
     *
     * So `files` counts only headers whose type flag (offset 156) is `0` or
     * `\0` — POSIX's two spellings of "regular file" — and that is the number
     * BackupRun compares against the regular files it counted on the disk.
     * `entries` remains every header: it describes the archive, it is what a
     * set written before this distinction existed recorded, and it is the right
     * number for "is there anything in here at all".
     *
     * DELIBERATELY NOT COUNTED AS FILES: directories (`5`), symlinks (`2`),
     * hard links (`1`), and the `L`/`K`/`x`/`g` extension records. A hard-link
     * record does produce a file on extraction, but tar only ever writes one
     * for a SECOND name of a file whose first name is already in the archive as
     * a `0`, so counting it would push the archive's count above the number of
     * distinct files the walk found on disk. Undercounting fails a run that was
     * fine; overcounting passes a run that was not, and only one of those two
     * mistakes loses data.
     *
     * @return array{ok: bool, error: ?string, entries: int, files: int}
     */
    public static function countTarEntries(string $path): array
    {
        $fail = fn (string $why) => ['ok' => false, 'error' => $why, 'entries' => 0, 'files' => 0];

        if (! is_file($path)) {
            return $fail('file does not exist');
        }

        if (! extension_loaded('zlib')) {
            return $fail('PHP has no zlib extension, so this archive cannot be verified');
        }

        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            return $fail('not readable as a gzip stream');
        }

        $entries = 0;
        $files = 0;

        try {
            while (true) {
                $header = self::readExactly($handle, 512);

                if ($header === null) {
                    // Ran out mid-header: the archive is truncated.
                    return $entries > 0
                        ? $fail(sprintf('truncated after %d entries', $entries))
                        : $fail('truncated before the first entry');
                }

                if (trim($header, "\0") === '') {
                    // First of the two terminating NUL blocks: a clean end.
                    break;
                }

                if (substr($header, 257, 5) !== 'ustar') {
                    return $fail(sprintf('block %d is not a tar header', $entries + 1));
                }

                $entries++;

                // Offset 156 is the type flag. Historic tars wrote a NUL there
                // for a regular file and modern ones write '0'; both mean the
                // same thing and both have to count, because BSD tar and GNU
                // tar do not agree about which they emit.
                $type = substr($header, 156, 1);

                // '1' (hard link), '2' (symlink) and '5' (directory) are
                // deliberately NOT files. A review suggested counting '1'; it is
                // wrong, and the test beside this says why: tar writes a '1' only
                // for a SECOND NAME of a file already in the archive as a '0', so
                // counting it puts the archive's total above the distinct-file
                // count the disk walk produced — the direction that passes a run
                // which should have failed.
                if ($type === '0' || $type === "\0") {
                    $files++;
                }

                $size = (int) octdec(trim(substr($header, 124, 12), " \0"));
                $skip = (int) (ceil($size / 512) * 512);

                if ($skip > 0 && self::readExactly($handle, $skip) === null) {
                    return $fail(sprintf('truncated inside entry %d', $entries));
                }
            }
        } finally {
            gzclose($handle);
        }

        return ['ok' => true, 'error' => null, 'entries' => $entries, 'files' => $files];
    }

    /**
     * gzread returns "up to" the requested length, so a single call is not a
     * read of N bytes. Null means the stream ended early.
     */
    private static function readExactly($handle, int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            if (gzeof($handle)) {
                return null;
            }

            $chunk = gzread($handle, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
