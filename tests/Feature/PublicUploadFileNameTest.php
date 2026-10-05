<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Page;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two image uploads to the PUBLIC disk pin the file's NAME as well as its bytes:
 *
 *  - a page's title background (PagesController store and update), which the media library
 *    keeps under the client's own file name;
 *  - the Friday-lunch flyer (MealMenusController::uploadFlyer, served to the admin realm
 *    and to the lunch realm), stored as `<uuid>.<extension>`.
 *
 * Both checked the bytes only. The web server serves public/storage straight from disk
 * and picks the Content-Type from the extension, so real image bytes stored under a name
 * ending in `.html` would be answered as a page on the application's own origin. The
 * section uploads and the shop's pictures already pin the name
 * (ValidatesVideoSection::sectionUploadRules, UploadProductImagesRequest); these two had
 * been left out. So had thirteen more, which PublicUploadFileNameDoorsTest holds door by
 * door. A new upload needs its own row there: UploadFileNameCoverageTest catches the
 * ordinary ways of writing an upload rule without its name pinned, and lists the ways it
 * cannot see.
 *
 * For each of its four doors (a new page, a replacement, the flyer through the admin
 * realm and through the lunch realm) this file proves that page-like names are refused
 * and that every kind of file an office may upload there is accepted, under a lower-case
 * and an upper-case name. The kinds are stated here (PAGE_KINDS, FLYER_KINDS) and are not
 * read from the rules, so a rule whose list loses a kind turns this file red.
 *
 * Every upload here is REAL bytes in a real UploadedFile, so its type is what finfo reads
 * from the file. UploadedFile::fake() answers getMimeType() from its argument or from its
 * name, which proves nothing about image bytes that are named as something else.
 */
class PublicUploadFileNameTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private const PAGE_FIELD = 'page_title_background_image';

    private const PAGE_NAME_REFUSAL = 'The background image\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.';

    private const FLYER_NAME_REFUSAL = 'The flyer\'s file name must end in .jpg, .jpeg, .png or .webp. Rename the file and upload it again.';

    /**
     * What an office may upload as a page's title background: each kind of file (its
     * BYTES), and the endings a file of that kind is named with. Written out here on
     * purpose, as this file's own statement of what the door is for.
     */
    private const PAGE_KINDS = ['jpeg' => ['jpg', 'jpeg'], 'png' => ['png'], 'gif' => ['gif'], 'webp' => ['webp']];

    /** The same for the lunch flyer, which has never taken a GIF. */
    private const FLYER_KINDS = ['jpeg' => ['jpg', 'jpeg'], 'png' => ['png'], 'webp' => ['webp']];

    /** The extension a flyer of each kind is stored under: the one its bytes say. */
    private const FLYER_STORED_AS = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];

    private Masjid $masjid;

    private User $admin;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Uploads land on the fake disk, never in this checkout's storage.
        Storage::fake('public');

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'masjid',
        ]);
        // The page builder is a grant (config/capabilities.php); Friday lunch is on for a masjid.
        $this->masjid->forceFill(['capability_overrides' => ['web_pages' => true]])->save();

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ names */

    /**
     * Names that are not an image's, each carried by real JPEG bytes.
     *
     * @return array<string, array{string}>
     */
    public static function namesThatAreNotAnImages(): array
    {
        return [
            'a page: x.html' => ['x.html'],
            'an image name with a page name after it: x.jpg.html' => ['x.jpg.html'],
            'a page in capitals: x.HTML' => ['x.HTML'],
            'a page by its short name: x.htm' => ['x.htm'],
            'a page by its other name: x.xhtml' => ['x.xhtml'],
            'a drawing that can carry script: x.svg' => ['x.svg'],
            'a script: x.php' => ['x.php'],
            'no extension at all: x' => ['x'],
        ];
    }

    /**
     * Every name a page's title background takes, with the kind of bytes each carries:
     * each ending of each kind in PAGE_KINDS, in lower case and in capitals.
     *
     * @return array<string, array{string, string}>
     */
    public static function pageImageNames(): array
    {
        $rows = [
            'IMG_0001.JPG, as a camera or a phone names it' => ['IMG_0001.JPG', 'jpeg'],
            'Scan.PNG' => ['Scan.PNG', 'png'],
        ];

        foreach (self::PAGE_KINDS as $kind => $endings) {
            foreach ($endings as $ending) {
                foreach (['photo.' . $ending, 'PHOTO.' . strtoupper($ending)] as $name) {
                    $rows[$name] = [$name, $kind];
                }
            }
        }

        return $rows;
    }

    /**
     * Every name a flyer takes, the kind of bytes each carries, and the extension it is
     * stored under: the one its BYTES say, whatever the name says. Each ending of each
     * kind in FLYER_KINDS, in lower case and in capitals.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function flyerImageNames(): array
    {
        $rows = [
            'IMG_0001.JPG, as a camera or a phone names it' => ['IMG_0001.JPG', 'jpeg', 'jpg'],
            'PNG bytes under a .jpg name' => ['photo.jpg', 'png', 'png'],
        ];

        foreach (self::FLYER_KINDS as $kind => $endings) {
            foreach ($endings as $ending) {
                foreach (['photo.' . $ending, 'PHOTO.' . strtoupper($ending)] as $name) {
                    $rows[$name] = [$name, $kind, self::FLYER_STORED_AS[$kind]];
                }
            }
        }

        return $rows;
    }

    /* ------------------------------------------------- a page's title background */

    #[Test]
    #[DataProvider('namesThatAreNotAnImages')]
    public function a_new_pages_title_background_is_refused_by_its_name_and_nothing_is_stored(string $name): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post($this->pagesUrl(), [
            'slug' => 'about',
            'title' => 'About',
            self::PAGE_FIELD => $this->realImage($name),
        ], self::JSON);

        $this->assertRefusedByName($response, self::PAGE_FIELD, $name);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a refused background reached the public disk');
        $this->assertSame(0, DB::table('media')->count(), 'a refused background left a media row');
        $this->assertSame(0, Page::count(), 'a refused background left a page behind');
    }

    #[Test]
    #[DataProvider('namesThatAreNotAnImages')]
    public function a_replacement_title_background_is_refused_by_its_name_and_the_page_keeps_what_it_had(string $name): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->pageWithBackground('photo.jpg');
        $before = Storage::disk('public')->allFiles();
        $this->assertCount(1, $before);

        // A browser can only send a file to a PUT route as a POST that names the method.
        $response = $this->post($this->pagesUrl($page), [
            '_method' => 'PUT',
            'title' => 'Renamed',
            self::PAGE_FIELD => $this->realImage($name),
        ], self::JSON);

        $this->assertRefusedByName($response, self::PAGE_FIELD, $name);
        $this->assertSame($before, Storage::disk('public')->allFiles(), 'the public disk changed on a refused replacement');
        $this->assertSame(['photo.jpg'], DB::table('media')->pluck('file_name')->all());
        $this->assertSame('About', $page->fresh()->title, 'a refused request still changed the page');
    }

    #[Test]
    #[DataProvider('pageImageNames')]
    public function a_title_background_with_an_images_name_is_stored_under_that_name(string $name, string $kind): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post($this->pagesUrl(), [
            'slug' => 'about',
            'title' => 'About',
            self::PAGE_FIELD => $this->realImage($name, $kind),
        ], self::JSON);

        $this->assertSame(201, $response->status(), "{$name} was refused: " . $response->getContent());

        $media = DB::table('media')->get();
        $this->assertCount(1, $media);
        $this->assertSame($name, $media[0]->file_name);
        $this->assertSame('page_title_backgrounds', $media[0]->collection_name);
        Storage::disk('public')->assertExists($media[0]->id . '/' . $name);
        $this->assertStringEndsWith('/' . $name, (string) $response->json('data.page_title_background_image_url'));
    }

    #[Test]
    #[DataProvider('pageImageNames')]
    public function a_replacement_with_an_images_name_takes_the_place_of_the_old_background(string $name, string $kind): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->pageWithBackground('first.jpg');
        $old = DB::table('media')->value('id');

        $response = $this->post($this->pagesUrl($page), [
            '_method' => 'PUT',
            self::PAGE_FIELD => $this->realImage($name, $kind),
        ], self::JSON);

        $this->assertSame(200, $response->status(), "{$name} was refused: " . $response->getContent());

        $media = DB::table('media')->get();
        $this->assertCount(1, $media, 'the old background was kept beside the new one');
        $this->assertNotSame($old, $media[0]->id, 'the old background is still the page\'s');
        $this->assertSame($name, $media[0]->file_name);
        $this->assertSame([$media[0]->id . '/' . $name], Storage::disk('public')->allFiles());
    }

    /* --------------------------------------------------------- the lunch flyer */

    #[Test]
    #[DataProvider('namesThatAreNotAnImages')]
    public function a_flyer_is_refused_by_its_name_and_nothing_is_stored(string $name): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post($this->flyerUrl(), ['flyer' => $this->realImage($name)], self::JSON);

        $this->assertRefusedByName($response, 'flyer', $name);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a refused flyer reached the public disk');
    }

    #[Test]
    #[DataProvider('flyerImageNames')]
    public function a_flyer_with_an_images_name_is_stored_under_the_extension_of_its_bytes(string $name, string $kind, string $storedAs): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post($this->flyerUrl(), ['flyer' => $this->realImage($name, $kind)], self::JSON);

        $this->assertSame(201, $response->status(), "{$name} was refused: " . $response->getContent());

        $stored = Storage::disk('public')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression(
            '#^lunch-flyers/[0-9a-f-]{36}\.' . $storedAs . '$#',
            $stored[0],
            "{$name} ({$kind} bytes) was not stored as a .{$storedAs}",
        );
        $this->assertStringEndsWith('/storage/' . $stored[0], (string) $response->json('data.url'));
    }

    #[Test]
    #[DataProvider('namesThatAreNotAnImages')]
    public function lunch_staff_meet_the_same_rule_at_their_own_door(string $name): void
    {
        $url = $this->lunchStaffFlyerUrl();

        $response = $this->post($url, ['flyer' => $this->realImage($name)], self::JSON);

        $this->assertRefusedByName($response, 'flyer', $name);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a refused flyer reached the public disk');
    }

    #[Test]
    #[DataProvider('flyerImageNames')]
    public function lunch_staff_can_upload_every_kind_of_flyer_at_their_own_door(string $name, string $kind, string $storedAs): void
    {
        $url = $this->lunchStaffFlyerUrl();

        $response = $this->post($url, ['flyer' => $this->realImage($name, $kind)], self::JSON);

        $this->assertSame(201, $response->status(), "{$name} was refused: " . $response->getContent());

        $stored = Storage::disk('public')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression(
            '#^lunch-flyers/[0-9a-f-]{36}\.' . $storedAs . '$#',
            $stored[0],
            "{$name} ({$kind} bytes) was not stored as a .{$storedAs}",
        );
    }

    /* ------------------------------------------------- what the person is told */

    #[Test]
    public function a_file_refused_only_for_its_name_is_told_what_the_name_must_end_in_and_what_to_do(): void
    {
        Sanctum::actingAs($this->admin);

        // The bytes are a JPEG, so the name is the only thing wrong and this is the whole answer.
        $this->post($this->pagesUrl(), [
            'slug' => 'about',
            'title' => 'About',
            self::PAGE_FIELD => $this->realImage('photo.html'),
        ], self::JSON)->assertStatus(422)->assertExactJson([
            'status' => 'failed',
            'data' => [self::PAGE_FIELD => [self::PAGE_NAME_REFUSAL]],
        ]);

        $page = $this->pageWithBackground('photo.jpg');
        $this->post($this->pagesUrl($page), [
            '_method' => 'PUT',
            self::PAGE_FIELD => $this->realImage('photo.html'),
        ], self::JSON)->assertStatus(422)->assertExactJson([
            'status' => 'failed',
            'data' => [self::PAGE_FIELD => [self::PAGE_NAME_REFUSAL]],
        ]);

        $this->post($this->flyerUrl(), ['flyer' => $this->realImage('photo.html')], self::JSON)
            ->assertStatus(422)
            ->assertExactJson([
                'status' => 'failed',
                'data' => ['flyer' => [self::FLYER_NAME_REFUSAL]],
            ]);
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_told_so_once_and_is_not_told_to_rename_it(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->pageWithBackground('photo.jpg');
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        // Under its own name, and under an image's: renaming a PDF does not get it in, so
        // "rename the file" would be advice that cannot work. Each rule stops at the first
        // thing wrong (`bail`), which for these bytes is that they are not an image.
        foreach (['notice.pdf', 'notice.jpg'] as $name) {
            $answers = [
                'a new page' => [self::PAGE_FIELD, $this->post($this->pagesUrl(), [
                    'slug' => 'notice',
                    'title' => 'Notice',
                    self::PAGE_FIELD => $this->realUpload($name, $pdf),
                ], self::JSON)],
                'a replacement' => [self::PAGE_FIELD, $this->post($this->pagesUrl($page), [
                    '_method' => 'PUT',
                    self::PAGE_FIELD => $this->realUpload($name, $pdf),
                ], self::JSON)],
                'a flyer' => ['flyer', $this->post($this->flyerUrl(), ['flyer' => $this->realUpload($name, $pdf)], self::JSON)],
            ];

            foreach ($answers as $door => [$field, $response]) {
                $this->assertSame(422, $response->status(), "{$door}: a PDF named {$name} was answered {$response->status()}");

                $told = (array) $response->json('data');
                $this->assertSame([$field], array_keys($told), "{$door}: another field was blamed");
                $this->assertCount(1, $told[$field], "{$door}: a PDF named {$name} was told " . json_encode($told));
                $this->assertStringContainsString('must be an image', $told[$field][0]);
                $this->assertStringNotContainsString('Rename', $told[$field][0]);
            }
        }

        $this->assertSame(['photo.jpg'], DB::table('media')->pluck('file_name')->all());
        $this->assertCount(1, Storage::disk('public')->allFiles());
        $this->assertSame(1, Page::count());
    }

    /* ------------------------------------------------ what did not change */

    #[Test]
    public function the_bytes_are_still_checked_whatever_the_name_says(): void
    {
        Sanctum::actingAs($this->admin);
        $page = '<!doctype html><html><body><script>document.title = 1</script></body></html>';

        $this->post($this->pagesUrl(), [
            'slug' => 'about',
            'title' => 'About',
            self::PAGE_FIELD => $this->realUpload('photo.jpg', $page),
        ], self::JSON)->assertStatus(422)->assertJsonStructure(['data' => [self::PAGE_FIELD]]);

        $this->post($this->flyerUrl(), ['flyer' => $this->realUpload('photo.jpg', $page)], self::JSON)
            ->assertStatus(422)->assertJsonStructure(['data' => ['flyer']]);

        // A flyer has never taken a GIF; pinning the name must not have widened the list.
        $this->post($this->flyerUrl(), ['flyer' => $this->realImage('photo.gif', 'gif')], self::JSON)
            ->assertStatus(422)->assertJsonStructure(['data' => ['flyer']]);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, DB::table('media')->count());
        $this->assertSame(0, Page::count());
    }

    /* -------------------------------------------- how the rule treats capitals */

    #[Test]
    public function the_extensions_rule_lower_cases_the_files_name_and_not_its_own_list(): void
    {
        $named = fn (string $name): array => ['file' => $this->realImage($name)];

        // Illuminate\Validation\Concerns\ValidatesAttributes::validateExtensions() compares
        // strtolower(the client's extension) with the list exactly as the rule wrote it.
        // So a name in capitals passes a list in lower case...
        $this->assertTrue(Validator::make($named('IMG_0001.JPG'), ['file' => 'extensions:jpg,jpeg'])->passes());
        $this->assertTrue(Validator::make($named('IMG_0001.JpEg'), ['file' => 'extensions:jpg,jpeg'])->passes());
        $this->assertTrue(Validator::make($named('x.HTML'), ['file' => 'extensions:jpg,jpeg'])->fails());

        // ...and a list written in capitals matches no name at all, which is why every
        // `extensions:` list in this application is written in lower case.
        $this->assertTrue(Validator::make($named('IMG_0001.JPG'), ['file' => 'extensions:JPG'])->fails());
        $this->assertTrue(Validator::make($named('photo.jpg'), ['file' => 'extensions:JPG'])->fails());
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * Refused with 422 on that field. When it was NOT refused, the failure says what was
     * answered and under which name the bytes now sit on the public disk.
     */
    private function assertRefusedByName(TestResponse $response, string $field, string $name): void
    {
        $this->assertSame(422, $response->status(), sprintf(
            'JPEG bytes named "%s" were answered %d; on the public disk: %s; media rows: %s',
            $name,
            $response->status(),
            json_encode(Storage::disk('public')->allFiles()),
            json_encode(DB::table('media')->pluck('file_name')->all()),
        ));

        $response->assertJsonPath('status', 'failed')->assertJsonStructure(['data' => [$field]]);
    }

    private function pagesUrl(?Page $page = null): string
    {
        return '/api/admin/masjids/' . $this->masjid->id . '/pages' . ($page ? '/' . $page->id : '');
    }

    private function flyerUrl(): string
    {
        return '/api/admin/masjids/' . $this->masjid->id . '/jummah-lunch/flyer';
    }

    /** Signs in a lunch-staff login of this masjid and answers the lunch realm's flyer address. */
    private function lunchStaffFlyerUrl(): string
    {
        $staff = User::factory()->create([
            'type' => User::TYPE_LUNCH_STAFF,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->masjid->id,
            'user_id' => $staff->id,
            'role' => 'lunch-staff',
            'is_default' => true,
        ]);
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($staff);

        return '/api/lunch/masjids/' . $this->masjid->id . '/jummah-lunch/flyer';
    }

    /** A page titled "About" that already has a title background, uploaded through the door. */
    private function pageWithBackground(string $name): Page
    {
        $id = $this->post($this->pagesUrl(), [
            'slug' => 'about-' . uniqid(),
            'title' => 'About',
            self::PAGE_FIELD => $this->realImage($name),
        ], self::JSON)->assertStatus(201)->json('data.id');

        return Page::findOrFail($id);
    }

    /**
     * A real image of that kind (GD), under the name the client gave it.
     *
     * A kind is skipped only where this PHP cannot make its bytes: GD is built with or
     * without each format, and a build without WebP (or GIF) has no way to write one. The
     * skip names the kind, so a run that proved less says so.
     */
    private function realImage(string $name, string $kind = 'jpeg'): UploadedFile
    {
        $write = ['jpeg' => 'imagejpeg', 'png' => 'imagepng', 'gif' => 'imagegif', 'webp' => 'imagewebp'][$kind];

        if (! function_exists($write)) {
            $this->markTestSkipped("This PHP's GD has no {$write}(), so it cannot make the bytes of a {$kind} file. Nothing is proven about that kind here.");
        }

        ob_start();
        $write(imagecreatetruecolor(8, 8));

        return $this->realUpload($name, (string) ob_get_clean());
    }

    private function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
