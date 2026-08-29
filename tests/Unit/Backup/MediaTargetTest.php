<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\MediaTarget;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The file half of a backup has to know where the files ARE, and the answer is
 * configuration rather than a constant. These pin both halves of that:
 *
 *   - the target FOLLOWS config('media-library.disk_name') and the disk
 *     definition behind it, so moving media moves the backup;
 *   - a disk this build cannot archive is a REFUSAL, not a warning, because the
 *     alternative is a database-only backup — and restoring one of those is what
 *     re-creates rows pointing at files that are gone.
 */
class MediaTargetTest extends TestCase
{
    private function disks(): array
    {
        return [
            'local' => ['driver' => 'local', 'root' => '/srv/app/storage/app/private'],
            'public' => ['driver' => 'local', 'root' => '/srv/app/storage/app/public'],
            's3' => ['driver' => 's3', 'bucket' => 'manara-media'],
        ];
    }

    private function config(array $media = []): array
    {
        return ['media' => array_merge([
            'disk' => null,
            'prefix' => null,
            'strategies' => ['local' => 'tar', 's3' => null],
            'tar_binary' => 'tar',
        ], $media)];
    }

    #[Test]
    public function it_follows_the_disk_media_library_is_configured_on(): void
    {
        $target = MediaTarget::resolve($this->config(), $this->disks(), 'public', '');

        $this->assertTrue($target->isSupported());
        $this->assertSame('public', $target->disk);
        $this->assertSame(MediaTarget::STRATEGY_TAR, $target->strategy);
        $this->assertSame('/srv/app/storage/app/public', $target->archiveRoot());
    }

    #[Test]
    public function moving_media_to_another_local_disk_moves_the_backup_with_it(): void
    {
        // Nothing here names "public". Change MEDIA_DISK and the archive follows.
        $target = MediaTarget::resolve($this->config(), $this->disks(), 'local', '');

        $this->assertTrue($target->isSupported());
        $this->assertSame('/srv/app/storage/app/private', $target->archiveRoot());
    }

    #[Test]
    public function an_explicit_backup_media_disk_overrides_media_library(): void
    {
        $target = MediaTarget::resolve($this->config(['disk' => 'local']), $this->disks(), 'public', '');

        $this->assertSame('local', $target->disk);
        $this->assertSame('/srv/app/storage/app/private', $target->archiveRoot());
    }

    #[Test]
    public function the_media_prefix_is_part_of_the_archive_root_and_of_a_rows_path(): void
    {
        $target = MediaTarget::resolve($this->config(), $this->disks(), 'public', 'manara');

        $this->assertSame('/srv/app/storage/app/public/manara', $target->archiveRoot());
        $this->assertSame('manara/42/poster.jpg', $target->relativePathFor(42, 'poster.jpg'));
    }

    #[Test]
    public function a_row_with_no_prefix_maps_to_the_id_directory_media_library_uses(): void
    {
        $target = MediaTarget::resolve($this->config(), $this->disks(), 'public', '');

        $this->assertSame('42/poster.jpg', $target->relativePathFor(42, 'poster.jpg'));
    }

    #[Test]
    public function media_on_s3_with_no_configured_strategy_is_refused_not_downgraded(): void
    {
        $target = MediaTarget::resolve($this->config(), $this->disks(), 's3', '');

        $this->assertFalse($target->isSupported());
        $this->assertStringContainsString('s3', (string) $target->reason);
        // The refusal has to name the setting that fixes it...
        $this->assertStringContainsString('BACKUP_S3_STRATEGY', (string) $target->reason);
        // ...and say why it is a refusal rather than a database-only backup.
        $this->assertStringContainsString('database-only', (string) $target->reason);
    }

    #[Test]
    public function media_on_s3_with_a_strategy_and_a_bucket_resolves_to_a_sync(): void
    {
        $target = MediaTarget::resolve(
            $this->config(['strategies' => ['local' => 'tar', 's3' => MediaTarget::STRATEGY_S3_SYNC]]),
            $this->disks(),
            's3',
            'manara',
        );

        $this->assertTrue($target->isSupported());
        $this->assertSame(MediaTarget::STRATEGY_S3_SYNC, $target->strategy);
        $this->assertSame('manara-media', $target->bucket);
        $this->assertNull($target->archiveRoot());
    }

    #[Test]
    public function an_s3_disk_with_no_bucket_is_refused(): void
    {
        $disks = $this->disks();
        $disks['s3']['bucket'] = '';

        $target = MediaTarget::resolve(
            $this->config(['strategies' => ['local' => 'tar', 's3' => MediaTarget::STRATEGY_S3_SYNC]]),
            $disks,
            's3',
            '',
        );

        $this->assertFalse($target->isSupported());
        $this->assertStringContainsString('bucket', (string) $target->reason);
    }

    #[Test]
    public function a_disk_filesystems_does_not_define_is_refused_and_lists_the_ones_it_does(): void
    {
        $target = MediaTarget::resolve($this->config(), $this->disks(), 'dropbox', '');

        $this->assertFalse($target->isSupported());
        $this->assertStringContainsString('dropbox', (string) $target->reason);
        $this->assertStringContainsString('public', (string) $target->reason);
    }

    #[Test]
    public function a_local_disk_with_no_root_is_refused(): void
    {
        $target = MediaTarget::resolve($this->config(), ['broken' => ['driver' => 'local']], 'broken', '');

        $this->assertFalse($target->isSupported());
        $this->assertStringContainsString('no root', (string) $target->reason);
    }

    #[Test]
    public function no_media_disk_at_all_is_refused(): void
    {
        $target = MediaTarget::resolve($this->config(), $this->disks(), null, '');

        $this->assertFalse($target->isSupported());
        $this->assertStringContainsString('No media disk', (string) $target->reason);
    }
}
