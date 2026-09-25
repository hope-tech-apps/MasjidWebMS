<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Http\Controllers\AdminDashboard\PageSectionsController;
use App\Http\Controllers\AdminDashboard\SectionsController;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The `video` section type: an MP4 uploaded to the site, played in the page or as a
 * silent looping banner (MEC's home-page clip, VIDEO-SECTION-PLAN item 2.5).
 *
 * Two things are pinned here, because both break silently:
 *
 *  1. The content SHAPE. The MEC home-video apply script asserts these exact keys before
 *     it writes anything, and the renderer reads them; a renamed key is a banner that
 *     draws nothing.
 *  2. The UPLOAD RULE, which is the real change. Every section upload used to get the
 *     image rule. Now exactly one field takes an MP4 (a video section's `video_url`), up
 *     to 25 MB, and an MP4 is still refused everywhere an <img> draws the file.
 *
 * The SPA wiring (union, editorMap, editor file) is enforced for every case by
 * OfferingSectionTypeTest::every_section_type_is_wired_into_the_spa_union_the_editor_map_and_a_real_editor_file.
 */
class VideoSectionTypeTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Uploads land on the fake disk, never in the CI tree's storage.
        Storage::fake('public');

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
        // The page builder is an organisation capability (config/capabilities.php).
        $this->masjid->forceFill(['capability_overrides' => ['web_pages' => true]])->save();

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    /* ---------------------------------------------------------------- the type */

    #[Test]
    public function the_video_default_content_is_the_shape_the_apply_script_and_renderer_read(): void
    {
        $this->assertSame('video', SectionType::VIDEO->value);
        $this->assertSame([
            'video_url' => null,
            'poster_url' => null,
            'title' => '',
            'caption' => '',
            'layout' => 'player',
            'max_width' => 'container',
            'background_color' => '#ffffff',
        ], SectionType::VIDEO->defaultContent());
    }

    #[Test]
    public function the_video_type_is_classified_and_has_a_renderer(): void
    {
        $this->assertSame('Video', SectionType::VIDEO->label());
        $this->assertFalse(SectionType::VIDEO->usesExternalData());
        $this->assertNull(SectionType::VIDEO->requiresModule());
        // The renderer shipped first (burlington-masjid-site Video.vue), so the type is
        // never listed as undrawn and its description promises nothing it lacks.
        $this->assertNotContains(SectionType::VIDEO, SectionType::withoutRenderer());
        $this->assertTrue(SectionType::VIDEO->hasRenderer());
        $this->assertSame(
            'A video file uploaded to this site, played in the page or as a silent looping banner',
            SectionType::VIDEO->description()
        );
    }

    #[Test]
    public function both_upload_maps_send_the_mp4_and_its_poster_to_the_content(): void
    {
        foreach ([SectionsController::class, PageSectionsController::class] as $controllerClass) {
            $method = new ReflectionMethod($controllerClass, 'getImageFieldsForSectionType');
            $method->setAccessible(true);

            $this->assertSame(
                ['video_url', 'poster_url'],
                $method->invoke(new $controllerClass(), SectionType::VIDEO),
                "{$controllerClass} does not map the video section's two uploads"
            );
        }
    }

    /* ---------------------------------------------------------- the upload rule */

    #[Test]
    public function an_mp4_uploaded_as_a_video_sections_video_url_is_stored_and_written_into_the_content(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $created = $this->post($this->pageSections($page), [
            'section_type' => 'video',
            'title' => 'Home clip',
            'content' => json_encode(array_merge(SectionType::VIDEO->defaultContent(), ['layout' => 'banner', 'max_width' => 'full'])),
            'order' => 1,
            'is_active' => 1,
            'video_url' => UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'),
            'poster_url' => UploadedFile::fake()->image('still.jpg', 1920, 1080),
        ])->assertStatus(201)->json('data');

        $this->assertSame('video', $created['section_type']);
        $this->assertSame('banner', $created['content']['layout']);

        $media = DB::table('media')
            ->where('model_type', Section::class)
            ->where('model_id', $created['id'])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $media, 'the MP4 and its poster are both stored');
        $this->assertSame(['section_images', 'section_images'], $media->pluck('collection_name')->all());
        $this->assertSame(['clip.mp4', 'still.jpg'], $media->pluck('file_name')->all());

        $this->assertStringEndsWith('/' . $media[0]->id . '/clip.mp4', $created['content']['video_url']);
        $this->assertStringEndsWith('/' . $media[1]->id . '/still.jpg', $created['content']['poster_url']);
        Storage::disk('public')->assertExists($media[0]->id . '/clip.mp4');
    }

    #[Test]
    public function an_mp4_is_accepted_only_in_a_video_sections_video_url(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        // The one field that takes it.
        $this->post($this->pageSections($page), $this->videoSection([
            'video_url' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ]))->assertStatus(201);

        // An image section's picture is drawn by an <img>: an MP4 there draws nothing.
        $this->post($this->pageSections($page), [
            'section_type' => 'image',
            'content' => json_encode(SectionType::IMAGE->defaultContent()),
            'image_url' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ])->assertStatus(422)->assertJsonPath('status', 'failed')->assertJsonStructure(['data' => ['image_url']]);

        // Nor is the video section's own poster a video.
        $this->post($this->pageSections($page), $this->videoSection([
            'poster_url' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ]))->assertStatus(422)->assertJsonStructure(['data' => ['poster_url']]);

        // And nothing refused left a row behind.
        $this->assertSame(1, Section::where('section_type', 'video')->count());
        $this->assertSame(0, Section::where('section_type', 'image')->count());
    }

    #[Test]
    public function a_video_url_must_be_an_mp4_of_at_most_25_mb(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        foreach ([
            'a JPEG' => UploadedFile::fake()->image('still.jpg'),
            'a QuickTime movie' => UploadedFile::fake()->create('clip.mov', 512, 'video/quicktime'),
            'a WebM' => UploadedFile::fake()->create('clip.webm', 512, 'video/webm'),
            'an MP4 one kilobyte over 25 MB' => UploadedFile::fake()->create('big.mp4', 25601, 'video/mp4'),
        ] as $what => $file) {
            $response = $this->post($this->pageSections($page), $this->videoSection(['video_url' => $file]));
            $this->assertSame(422, $response->status(), "{$what} was not refused as video_url");
            $this->assertArrayHasKey('video_url', (array) $response->json('data'), "{$what} was refused, but not for video_url");
        }

        $this->assertSame(0, Section::where('section_type', 'video')->count());
    }

    #[Test]
    public function editing_a_video_section_without_resending_its_type_still_takes_an_mp4(): void
    {
        // The SPA always resends section_type, but the API does not require it on an
        // update; the rule must then read the stored type, as the embed rule does.
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $created = $this->post($this->pageSections($page), $this->videoSection([]))->assertStatus(201)->json('data');

        $updated = $this->post("{$this->pageSections($page)}/{$created['id']}", [
            '_method' => 'PUT',
            'video_url' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ])->assertStatus(200)->json('data');

        $this->assertStringEndsWith('/clip.mp4', $updated['content']['video_url']);

        // The same edit on an IMAGE section is still refused.
        $image = $this->post($this->pageSections($page), [
            'section_type' => 'image',
            'content' => json_encode(SectionType::IMAGE->defaultContent()),
        ])->assertStatus(201)->json('data');

        $this->post("{$this->pageSections($page)}/{$image['id']}", [
            '_method' => 'PUT',
            'image_url' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ])->assertStatus(422)->assertJsonStructure(['data' => ['image_url']]);
    }

    #[Test]
    public function the_sections_library_takes_the_mp4_through_its_own_upload_map(): void
    {
        // SectionsController owns the second copy of the image-field map and its own
        // pair of requests; the page builder's passing proves nothing about this path.
        Sanctum::actingAs($this->admin);

        $created = $this->post("/api/admin/masjids/{$this->masjid->id}/sections", $this->videoSection([
            'video_url' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ]))->assertStatus(201)->json('data');

        $this->assertStringEndsWith('/clip.mp4', $created['content']['video_url']);

        $this->post("/api/admin/masjids/{$this->masjid->id}/sections/{$created['id']}", [
            '_method' => 'PUT',
            'poster_url' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ])->assertStatus(422)->assertJsonStructure(['data' => ['poster_url']]);
    }

    #[Test]
    public function a_video_layout_and_width_must_be_words_the_renderer_knows(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $this->post($this->pageSections($page), $this->videoSection([], ['layout' => 'hero']))
            ->assertStatus(422)->assertJsonStructure(['data' => ['content']]);
        $this->post($this->pageSections($page), $this->videoSection([], ['max_width' => 'huge']))
            ->assertStatus(422)->assertJsonStructure(['data' => ['content']]);

        // The same words on another type are none of this rule's business.
        $this->post($this->pageSections($page), [
            'section_type' => 'text',
            'content' => json_encode(array_merge(SectionType::TEXT->defaultContent(), ['layout' => 'hero'])),
        ])->assertStatus(201);
    }

    /* ---------------------------------------------------------------- helpers */

    private function makePage(string $slug): Page
    {
        return Page::create([
            'masjid_id' => $this->masjid->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'is_active' => true,
            'order' => 1,
        ]);
    }

    private function pageSections(Page $page): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/pages/{$page->id}/sections";
    }

    /**
     * A video section as SectionFormModal posts it: `content` as a JSON string in a
     * multipart body, files beside it under their content key.
     */
    private function videoSection(array $files, array $content = []): array
    {
        return array_merge([
            'section_type' => 'video',
            'content' => json_encode(array_merge(SectionType::VIDEO->defaultContent(), $content)),
            'order' => 1,
            'is_active' => 1,
        ], $files);
    }
}
