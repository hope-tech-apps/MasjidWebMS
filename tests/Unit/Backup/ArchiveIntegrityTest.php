<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\ArchiveIntegrity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A tar header is not a file, and the difference is the whole guarantee.
 *
 * The one thing a backup SET has that the four legacy `mysqldump` files did not
 * is a file half, and the only thing that makes that half a guarantee rather
 * than a hope is a count of what is inside it measured against what was on the
 * disk. Counting HEADERS gets that wrong in the dangerous direction:
 * `tar -czf … -C root .` writes a header for every directory as well as every
 * file, GNU tar writes an extra `L` record ahead of any path over 99 bytes, and
 * a symlink is a `2` record with no data at all. The production media disk is 91
 * files in 68 directories, so an archive of it carries roughly 160 headers —
 * which means an archive holding 91 headers and THIRTY files cleared a floor of
 * "at least 91 entries" and was written, verified and called a backup.
 *
 * The archives below are assembled byte by byte rather than by shelling out to
 * `tar`, because the point is to pin the parse of each POSIX type flag exactly,
 * including the two spellings of "regular file" that GNU tar and BSD tar do not
 * agree about.
 */
class ArchiveIntegrityTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/manara-tar-'.bin2hex(random_bytes(6));
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) (@scandir($this->base) ?: []) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->base.'/'.$entry);
            }
        }

        @rmdir($this->base);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * One 512-byte ustar header. The checksum is computed the way tar computes
     * it — every byte summed with the checksum field itself read as spaces — so
     * these are real headers and not merely ones this parser happens to accept.
     */
    private function header(string $name, string $type, int $size): string
    {
        $header = str_pad(substr($name, 0, 100), 100, "\0");
        $header .= str_pad('0000644', 8, "\0");                 // mode
        $header .= str_pad('0000000', 8, "\0");                 // uid
        $header .= str_pad('0000000', 8, "\0");                 // gid
        $header .= sprintf('%011o', $size)."\0";                // size
        $header .= sprintf('%011o', 1755000000)."\0";           // mtime
        $header .= '        ';                                  // checksum placeholder
        $header .= $type;                                       // type flag
        $header .= str_repeat("\0", 100);                       // link name
        $header .= "ustar\0";                                   // magic
        $header .= '00';                                        // version
        $header .= str_pad('root', 32, "\0");                   // uname
        $header .= str_pad('root', 32, "\0");                   // gname
        $header .= str_repeat("\0", 8);                         // devmajor
        $header .= str_repeat("\0", 8);                         // devminor
        $header .= str_repeat("\0", 155);                       // prefix
        $header .= str_repeat("\0", 12);                        // padding

        $this->assertSame(512, strlen($header), 'a tar header is 512 bytes');

        $checksum = 0;

        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }

        return substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $entries  [name, type flag, data]
     */
    private function archive(array $entries, bool $terminate = true): string
    {
        $bytes = '';

        foreach ($entries as [$name, $type, $data]) {
            $bytes .= $this->header($name, $type, strlen($data));

            if ($data !== '') {
                $bytes .= str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0");
            }
        }

        return $bytes.($terminate ? str_repeat("\0", 1024) : '');
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $entries */
    private function write(array $entries, bool $terminate = true): string
    {
        $path = $this->base.'/'.bin2hex(random_bytes(4)).'.tar.gz';

        file_put_contents($path, gzencode($this->archive($entries, $terminate)));

        return $path;
    }

    // ------------------------------------------------------------------ tests

    #[Test]
    public function directories_symlinks_and_long_name_records_are_entries_but_are_not_files(): void
    {
        // The archive an outage would produce: plenty of headers, two files.
        $long = str_repeat('a-very-long-announcement-directory/', 4).'poster.jpg';

        $path = $this->write([
            ['./', '5', ''],
            ['./7/', '5', ''],
            ['./7/poster.jpg', '0', 'poster-bytes'],
            ['./8/', '5', ''],
            ['./8/logo-link.png', '2', ''],
            ['././@LongLink', 'L', $long."\0"],
            [substr($long, 0, 100), '0', 'logo-bytes'],
        ]);

        $result = ArchiveIntegrity::countTarEntries($path);

        $this->assertTrue($result['ok']);
        $this->assertSame(7, $result['entries'], 'every header is an entry');
        $this->assertSame(2, $result['files'], 'only the two regular files are files');
    }

    #[Test]
    public function an_archive_of_nothing_but_directories_holds_no_files_at_all(): void
    {
        // This is the shape that used to pass: six headers against a disk that
        // held three files, and the check said the archive was not short.
        $path = $this->write([
            ['./', '5', ''],
            ['./a/', '5', ''],
            ['./a/b/', '5', ''],
            ['./a/b/c/', '5', ''],
            ['./a/b/c/d/', '5', ''],
            ['./a/b/c/d/e/', '5', ''],
        ]);

        $result = ArchiveIntegrity::countTarEntries($path);

        $this->assertTrue($result['ok']);
        $this->assertSame(6, $result['entries']);
        $this->assertSame(0, $result['files']);
    }

    #[Test]
    public function a_regular_file_written_with_the_older_nul_type_flag_still_counts(): void
    {
        // Both spellings mean "regular file" and tars in the wild write both;
        // reading only '0' would call a whole valid archive empty.
        $path = $this->write([
            ['./7/poster.jpg', "\0", 'poster-bytes'],
            ['./8/logo.png', '0', 'logo-bytes'],
        ]);

        $result = ArchiveIntegrity::countTarEntries($path);

        $this->assertSame(2, $result['entries']);
        $this->assertSame(2, $result['files']);
    }

    #[Test]
    public function a_hard_link_record_is_not_counted_as_a_second_file(): void
    {
        // tar writes a '1' only for a second name of a file already in the
        // archive as a '0'. Counting it would put the archive's count above the
        // number of distinct files the disk walk found, which is the direction
        // that passes a run that should have failed.
        $path = $this->write([
            ['./7/poster.jpg', '0', 'poster-bytes'],
            ['./7/poster-copy.jpg', '1', ''],
        ]);

        $result = ArchiveIntegrity::countTarEntries($path);

        $this->assertSame(2, $result['entries']);
        $this->assertSame(1, $result['files']);
    }

    #[Test]
    public function a_truncated_archive_is_not_ok_and_reports_no_files(): void
    {
        $path = $this->write([
            ['./7/poster.jpg', '0', str_repeat('poster-bytes', 200)],
        ], terminate: false);

        // Chop the last data block off mid-entry.
        $raw = (string) gzdecode((string) file_get_contents($path));
        file_put_contents($path, gzencode(substr($raw, 0, 700)));

        $result = ArchiveIntegrity::countTarEntries($path);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['files']);
        $this->assertStringContainsString('truncated', (string) $result['error']);
    }
}
