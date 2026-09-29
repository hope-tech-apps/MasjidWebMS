<?php

namespace Tests\Feature\Studio\Concerns;

use App\Models\Masjid;
use App\Models\StudioDraft;
use App\Models\User;
use App\Support\Studio\LogoDerivatives;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * What the Studio draft tests share: the suite's SQLite set-up, a signed-in
 * SuperAdmin, a faked private disk, and uploads whose type is SNIFFED.
 *
 * UploadedFile::fake() reports a MIME type from the file NAME, so it cannot tell
 * whether the server sniffs the bytes. realUpload() builds a real UploadedFile
 * over a temp file instead, whose getMimeType() reads the content the way a
 * production upload's does.
 */
trait StudioDraftFixtures
{
    protected const DRAFTS = '/api/admin/studio/drafts';

    /**
     * The memory a Studio test that is not about the memory check gives the logo
     * decode: room for anything these tests make, whatever the PHPUnit process
     * has built up by then.
     */
    protected const ROOMY_HEADROOM_BYTES = 512 * 1024 * 1024;

    protected function setUpStudio(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Storage::fake((string) config('studio.logo.disk'));
        Storage::fake('public');

        // LogoDerivatives' memory check reads memory_limit minus what this
        // process holds, which grows over a long run and is not what these tests
        // are about. The seam is static, so it is put back when the app is torn
        // down; a test of the real path sets it to null itself.
        LogoDerivatives::$headroomBytes = self::ROOMY_HEADROOM_BYTES;
        $this->beforeApplicationDestroyed(fn () => LogoDerivatives::$headroomBytes = null);
    }

    protected function actAsSuperAdmin(): User
    {
        $user = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> the created draft's resource */
    protected function newDraft(array $body = []): array
    {
        return $this->postJson(self::DRAFTS, $body)->assertCreated()->json('data');
    }

    protected function patchDraft(int $id, int $lockVersion, array $answers, array $extra = []): TestResponse
    {
        return $this->patchJson(self::DRAFTS . "/{$id}", ['lock_version' => $lockVersion, 'answers' => $answers] + $extra);
    }

    protected function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Studio Org ' . uniqid(),
            'email' => 'studio' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
        ]);
    }

    /** Mark a draft as Step 3 would, pointing at a real organisation. */
    protected function markProvisioned(int $id): StudioDraft
    {
        $draft = StudioDraft::findOrFail($id);
        $draft->update([
            'status' => StudioDraft::STATUS_PROVISIONED,
            'provisioned_masjid_id' => $this->org()->id,
            'provisioned_at' => now(),
        ]);

        return $draft->fresh();
    }

    protected function pngBytes(int $width = 200, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 1, 177, 81));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A PNG whose header declares $width x $height and which has no pixel data:
     * the signature, an IHDR with a correct CRC and an empty IEND, 45 bytes.
     * getimagesize and the sniffed type accept it, and nothing can decode it, so
     * a refusal proves the size was read from the header alone.
     */
    protected function headerOnlyPngBytes(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NN', $width, $height) . "\x08\x02\x00\x00\x00")
            . $chunk('IEND', '');
    }

    protected function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'studio-logo-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function uploadLogo(int $id, UploadedFile $file): TestResponse
    {
        return $this->post(self::DRAFTS . "/{$id}/logo", ['logo' => $file], ['Accept' => 'application/json']);
    }

    /** @return list<string> every file on the private logo disk */
    protected function storedLogos(): array
    {
        return Storage::disk((string) config('studio.logo.disk'))->allFiles();
    }
}
