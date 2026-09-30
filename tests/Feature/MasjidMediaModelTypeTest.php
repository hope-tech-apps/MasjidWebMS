<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * `media.model_id` is half a key. Masjid::gallery(), header_logo() and
 * footer_logo() read it with the other half, `model_type`, since 2026-09-27,
 * the way logo() already did (see its docblock).
 *
 * Every test here builds the collision the missing half allowed: a photo that
 * belongs to a SERVICE, filed in the masjid's collection name, whose service
 * id equals the masjid's id. The service belongs to the OTHER organisation, so
 * reading by `model_id` alone was a cross-tenant read as well as a cross-model
 * one. Production held no such row when this shipped (all 26 `galleries` rows
 * were Masjid 13's, and `header_logos`/`footer_logos` held none), so these
 * tests are the only place the collision exists.
 */
class MasjidMediaModelTypeTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Masjid $other;

    /** Belongs to $other, and its id is $masjid's id. */
    private Service $collidingService;

    private User $admin;

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

        // The admin gallery index deletes every row whose
        // `file_exists($media->getPath())` is false. getPath() resolves
        // through Storage::disk('public'), so it follows the fake, and
        // makeMedia() writes each file there unless told not to.
        Storage::fake('public');

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->other = $this->makeMasjid();

        $this->collidingService = Service::create([
            'masjid_id' => $this->other->id,
            'title' => 'Other org service',
            'summary' => 'x',
            'description' => 'x',
            'text' => 'x',
        ]);

        // The premise, asserted: without it nothing below collides.
        $this->assertSame($this->masjid->id, $this->collidingService->id,
            'the fixture needs a service whose id is the masjid\'s id');
        $this->assertNotSame($this->masjid->id, $this->collidingService->masjid_id);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    #[Test]
    public function model_id_alone_matches_both_photos_so_every_test_here_collides(): void
    {
        $own = $this->makeMedia(Masjid::class, $this->masjid->id, 'galleries');
        $foreign = $this->makeMedia(Service::class, $this->collidingService->id, 'galleries');

        // Half the key finds both rows...
        $this->assertSame(2, Media::query()
            ->where('model_id', $this->masjid->id)
            ->where('collection_name', 'galleries')
            ->count());

        // ...the whole key finds the masjid's, through every way callers read it.
        $this->assertSame([$own->id], $this->masjid->gallery()->pluck('id')->all());
        $this->assertSame(1, $this->masjid->gallery()->count());
        $this->assertSame([$own->id], Masjid::with('gallery')->findOrFail($this->masjid->id)->gallery->pluck('id')->all());
        $this->assertSame(1, Masjid::withCount('gallery')->findOrFail($this->masjid->id)->gallery_count);
        $this->assertNull($this->masjid->gallery()->find($foreign->id));
    }

    #[Test]
    public function the_public_gallery_serves_only_the_masjids_own_photos(): void
    {
        $own = $this->makeMedia(Masjid::class, $this->masjid->id, 'galleries');
        $foreign = $this->makeMedia(Service::class, $this->collidingService->id, 'galleries');

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $index = $this->getJson('/api/v1/gallery', $headers)->assertOk();

        $this->assertSame([$own->id], array_column($index->json('data.items'), 'id'),
            'another organisation\'s service photo was served in this masjid\'s public gallery');
        $this->assertSame(1, $index->json('data.pagination.total'));

        $this->getJson("/api/v1/gallery/{$foreign->id}", $headers)->assertNotFound();
        $this->getJson("/api/v1/gallery/{$own->id}", $headers)->assertOk();
    }

    #[Test]
    public function the_app_gallery_serves_only_the_masjids_own_photos(): void
    {
        $own = $this->makeMedia(Masjid::class, $this->masjid->id, 'galleries');
        $this->makeMedia(Service::class, $this->collidingService->id, 'galleries');

        $response = $this->getJson("/api/mobile/masjids/{$this->masjid->id}/gallery")->assertOk();

        $this->assertSame([$own->id], array_column($response->json('data'), 'id'),
            'another organisation\'s service photo was served in this masjid\'s app gallery');
    }

    #[Test]
    public function the_admin_gallery_neither_lists_nor_cleans_up_another_models_photo(): void
    {
        $own = $this->makeMedia(Masjid::class, $this->masjid->id, 'galleries');
        $listed = $this->makeMedia(Service::class, $this->collidingService->id, 'galleries');
        // No file: the index's orphan cleanup deletes whatever it reads that
        // has none. Read through half the key, that was the other
        // organisation's row.
        $fileless = $this->makeMedia(Service::class, $this->collidingService->id, 'galleries', withFile: false);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/admin/masjids/{$this->masjid->id}/gallery")->assertOk();

        $this->assertSame([$own->id], array_column($response->json('data.data'), 'id'),
            'the admin gallery listed another organisation\'s service photo');
        $this->assertNotNull(Media::query()->find($listed->id));
        $this->assertNotNull(Media::query()->find($fileless->id),
            'opening this masjid\'s gallery editor deleted another organisation\'s service photo');
    }

    #[Test]
    public function the_admin_gallery_cannot_delete_another_models_photo(): void
    {
        $this->makeMedia(Masjid::class, $this->masjid->id, 'galleries');
        $foreign = $this->makeMedia(Service::class, $this->collidingService->id, 'galleries');

        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/gallery/{$foreign->id}");

        $this->assertFalse($response->isSuccessful(),
            'the delete reported success for a photo that is not in this masjid\'s gallery');
        $this->assertNotNull(Media::query()->find($foreign->id),
            'this masjid\'s admin deleted another organisation\'s service photo');
    }

    #[Test]
    public function the_header_and_footer_logos_are_only_the_masjids_own(): void
    {
        $this->makeMedia(Service::class, $this->collidingService->id, 'header_logos');
        $this->makeMedia(Service::class, $this->collidingService->id, 'footer_logos');

        $headers = ['masjid-id' => (string) $this->masjid->id];

        // Nothing of its own: nothing shown, rather than another model's image.
        $settings = $this->getJson('/api/v1/settings', $headers)->assertOk();

        $this->assertNull($settings->json('data.header_logo_url'),
            'another organisation\'s service image became this masjid\'s website header logo');
        $this->assertNull($settings->json('data.footer_logo_url'),
            'another organisation\'s service image became this masjid\'s website footer logo');

        $app = $this->getJson("/api/mobile/masjids/{$this->masjid->id}")->assertOk();

        $this->assertNull($app->json('data.header_image_url'),
            'another organisation\'s service image became this masjid\'s app header');

        // And its own, once it has them. The predicate narrows, it does not blind.
        $header = $this->makeMedia(Masjid::class, $this->masjid->id, 'header_logos');
        $footer = $this->makeMedia(Masjid::class, $this->masjid->id, 'footer_logos');

        $fresh = Masjid::with('header_logo', 'footer_logo')->findOrFail($this->masjid->id);

        $this->assertSame($header->id, $fresh->header_logo?->id);
        $this->assertSame($footer->id, $fresh->footer_logo?->id);

        $settings = $this->getJson('/api/v1/settings', $headers)->assertOk();

        $this->assertSame($header->original_url, $settings->json('data.header_logo_url'));
        $this->assertSame($footer->original_url, $settings->json('data.footer_logo_url'));
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Media Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    /** One media row owned by `$type` #`$id`, with its file on disk unless told otherwise. */
    private function makeMedia(string $type, int $id, string $collection, bool $withFile = true): Media
    {
        $media = new Media;

        $media->model_type = $type;
        $media->model_id = $id;
        $media->uuid = (string) Str::uuid();
        $media->collection_name = $collection;
        $media->name = 'image';
        $media->file_name = 'image-'.Str::random(6).'.png';
        $media->mime_type = 'image/png';
        $media->disk = 'public';
        $media->size = 12;
        $media->manipulations = [];
        $media->custom_properties = [];
        $media->generated_conversions = [];
        $media->responsive_images = [];
        $media->order_column = 1;
        $media->save();

        if ($withFile) {
            Storage::disk('public')->put($media->getPathRelativeToRoot(), 'imagebytes');
        }

        return $media;
    }
}
