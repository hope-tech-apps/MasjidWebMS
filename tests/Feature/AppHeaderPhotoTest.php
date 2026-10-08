<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * The photo an organisation's app paints behind its screen headers is a setting of its own
 * (Masjid::app_header_image(), collection `app_header_images`), since 2026-10-08.
 *
 * Until then the mobile payload's `header_image_url` was read from `header_logos`, which is
 * the WEBSITE's header logo. One upload meant two things: a building photo set for the app
 * became the logo in the website's header, and a logo set for the website was stretched
 * behind every app header. These pin the two apart, in both directions.
 */
class AppHeaderPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Storage::fake('public');

        $this->masjid = $this->makeMasjid();

        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    #[Test]
    public function a_photo_uploaded_on_general_settings_is_what_the_app_is_sent_and_the_website_is_not_touched(): void
    {
        // Read once first, so the cached app payload has to be refreshed by the upload.
        $this->assertNull($this->app_payload()->json('data.header_image_url'));

        $this->postJson($this->settingsUrl(), [
            'copyright_text' => 'x',
            'app_header_image' => UploadedFile::fake()->image('building.jpg', 1200, 800),
        ])->assertOk()->assertJsonPath('data.app_header_image.collection_name', 'app_header_images');

        $photo = Media::query()->where('collection_name', 'app_header_images')->sole();

        $this->assertSame(Masjid::class, $photo->model_type);
        $this->assertSame($photo->original_url, $this->app_payload()->json('data.header_image_url'));

        $website = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $this->masjid->id])->assertOk();

        $this->assertNull($website->json('data.header_logo_url'), 'the app header photo became the logo in the website header');
    }

    #[Test]
    public function a_website_header_logo_is_not_stretched_behind_the_app_headers(): void
    {
        $this->postJson($this->settingsUrl(), [
            'copyright_text' => 'x',
            'header_logo' => UploadedFile::fake()->image('logo.png', 300, 120),
        ])->assertOk();

        $this->assertSame(1, Media::query()->where('collection_name', 'header_logos')->count());
        $this->assertNull($this->app_payload()->json('data.header_image_url'));

        $website = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $this->masjid->id])->assertOk();

        $this->assertNotNull($website->json('data.header_logo_url'), 'the website still gets its header logo');
    }

    #[Test]
    public function the_newest_photo_wins_and_the_general_settings_screen_is_told_which_one_is_on_record(): void
    {
        $first = $this->masjid->addMedia(UploadedFile::fake()->image('old.jpg', 800, 500))->toMediaCollection('app_header_images');
        Media::query()->whereKey($first->id)->update(['created_at' => now()->subDay()]);

        $this->postJson($this->settingsUrl(), [
            'copyright_text' => 'x',
            'app_header_image' => UploadedFile::fake()->image('new.jpg', 1200, 800),
        ])->assertOk();

        $newest = Media::query()->where('collection_name', 'app_header_images')->orderByDesc('id')->first();

        $this->assertSame($newest->original_url, $this->app_payload()->json('data.header_image_url'));
        $this->assertSame($newest->original_url, $this->getJson($this->settingsUrl())->assertOk()->json('data.app_header_image.original_url'));
    }

    #[Test]
    public function another_models_photo_with_the_same_id_is_never_this_organisations_app_header(): void
    {
        // `media.model_id` is half a key (Masjid::logo() says why): a service whose id is
        // this organisation's id must not lend it a header.
        $other = $this->makeMasjid();
        $service = Service::create(['masjid_id' => $other->id, 'title' => 'x', 'summary' => 'x', 'description' => 'x', 'text' => 'x']);
        $this->assertSame($this->masjid->id, $service->id, 'the fixture needs a service whose id is the organisation\'s id');

        $service->addMedia(UploadedFile::fake()->image('theirs.jpg', 800, 500))->toMediaCollection('app_header_images');

        $this->assertNull($this->app_payload()->json('data.header_image_url'));
    }

    #[Test]
    public function the_app_payload_keeps_its_key_and_carries_no_raw_media_row(): void
    {
        $this->masjid->addMedia(UploadedFile::fake()->image('building.jpg', 1200, 800))->toMediaCollection('app_header_images');

        $data = $this->app_payload()->json('data');

        $this->assertArrayHasKey('header_image_url', $data);
        $this->assertIsString($data['header_image_url']);
        $this->assertArrayNotHasKey('app_header_image', $data, 'the raw media row is not part of the app payload');
        $this->assertArrayNotHasKey('header_logo', $data);
    }

    private function settingsUrl(): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/general-settings";
    }

    private function app_payload(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/mobile/masjids/{$this->masjid->id}")->assertOk();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Header Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'listed_at' => now(),
        ]);
    }
}
