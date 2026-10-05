<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every OTHER image upload that the media library keeps on the PUBLIC disk under the
 * client's own file name pins that name as well as the bytes. PublicUploadFileNameTest
 * holds the first two (a page's title background, the lunch flyer); this file holds the
 * thirteen uploads found beside them, one row of DOORS per rule line:
 *
 *   announcements, splash announcements, the gallery (one picture or a bag of them), the
 *   organisation logo on Details, the header and footer logos on General settings, the
 *   logos on organisation create and edit, a user's picture on the Users screen, an
 *   admin's own profile picture, a service's picture and icon, the About picture and its
 *   two icons, the donation link's picture, a push notification's picture, and the publish
 *   composer's main picture, which is copied onward into announcements and notifications.
 *
 * Each checked what a file IS and not what it is CALLED. The web server serves
 * public/storage from disk and picks the Content-Type from the extension, so image bytes
 * kept as `<media id>/x.html` would be answered as a page on the application's own origin.
 *
 * What this file proves, door by door:
 *
 *  - image bytes under a page-like name (`x.html`, `x.HTML`, `x.jpg.html`, `x.svg`, a name
 *    with no extension) are refused and nothing is stored;
 *  - every kind of file an office may upload at that door is accepted, under a lower-case
 *    and an upper-case name, and kept under that name. The kinds are stated in DOORS, in
 *    this file, and are NOT read from the rule: a rule whose list loses `webp` turns that
 *    door's rows red, which it could not if the list under test were the list being read;
 *  - a file refused for its name alone reads one sentence that says what to do, and a
 *    file that is not an image is told that once.
 *
 * What it does not prove: that a list is not too WIDE, beyond the page-like names above.
 * A new upload to the public disk needs a new row in DOORS. UploadFileNameCoverageTest
 * catches the ordinary ways of writing its rule without a pin, and says which it cannot.
 *
 * Every upload here is REAL bytes in a real UploadedFile sent through the real route, so
 * its type is what finfo reads from the file. UploadedFile::fake() answers getMimeType()
 * from its argument or its name, which proves nothing about bytes named as something else.
 *
 * "Nothing is stored" is the whole state, compared before and after the refused request:
 * every file on the public disk, every media row, and every row of the tables that door
 * writes. A new record is therefore not created and an existing one is not changed.
 */
class PublicUploadFileNameDoorsTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private const PHOTO_ENDINGS = '.jpg, .jpeg, .png, .gif or .webp';

    /** What a bare `image` rule admits (Laravel's own list), for the two rules with no `mimes:`. */
    private const ANY_IMAGE_ENDINGS = '.jpg, .jpeg, .png, .gif, .bmp or .webp';

    /**
     * What an office may upload at a door: each kind of file (its BYTES), and the endings
     * a file of that kind is named with. Written out here on purpose, as this file's own
     * statement of what each door is for.
     */
    private const PHOTOS = ['jpeg' => ['jpg', 'jpeg'], 'png' => ['png'], 'gif' => ['gif'], 'webp' => ['webp']];

    /** The two doors whose rule is a bare `image` have always taken a bitmap as well. */
    private const PHOTOS_OR_BITMAP = self::PHOTOS + ['bmp' => ['bmp']];

    /**
     * An icon is a PNG or a WebP. The icon rules' `mimes:` lists also name `ico` (and
     * `icns` on the edit form), but `image` beside them refuses those bytes first, so no
     * real file can be an icon under those names and the name lists leave them out.
     */
    private const ICONS = ['png' => ['png'], 'webp' => ['webp']];

    /** The service EDIT form's icon has always taken a GIF too; the create form's has not. */
    private const ICONS_OR_GIF = ['png' => ['png'], 'gif' => ['gif'], 'webp' => ['webp']];

    /** Every door, and what an office may upload there. */
    private const DOORS = [
        'a new announcement' => self::PHOTOS,
        'an edited announcement' => self::PHOTOS,
        'a new splash announcement' => self::PHOTOS,
        'an edited splash announcement' => self::PHOTOS,
        'a gallery photo sent alone' => self::PHOTOS,
        'gallery photos sent together' => self::PHOTOS,
        'the organisation logo on Details' => self::PHOTOS,
        'the header logo on General settings' => self::PHOTOS,
        'the footer logo on General settings' => self::PHOTOS,
        'a new organisation: logo' => self::PHOTOS,
        'a new organisation: footer logo' => self::PHOTOS,
        'an edited organisation: logo' => self::PHOTOS,
        'an edited organisation: footer logo' => self::PHOTOS,
        'a new user: picture' => self::PHOTOS,
        'an edited user: picture' => self::PHOTOS,
        'an admin\'s own profile picture' => self::PHOTOS,
        'a new service: picture' => self::PHOTOS,
        'a new service: icon' => self::ICONS,
        'an edited service: picture' => self::PHOTOS,
        'an edited service: icon' => self::ICONS_OR_GIF,
        'About, first save: picture' => self::PHOTOS,
        'About, first save: mission icon' => self::ICONS,
        'About, first save: vision icon' => self::ICONS,
        'About, edited: picture' => self::PHOTOS,
        'About, edited: mission icon' => self::ICONS,
        'About, edited: vision icon' => self::ICONS,
        'the donation link picture' => self::PHOTOS_OR_BITMAP,
        'a push notification picture' => self::PHOTOS_OR_BITMAP,
        'the publish composer picture' => self::PHOTOS,
    ];

    /** The doors that take an icon, and the sentence each reads when a file is not an image. */
    private const ICON_DOORS = [
        'a new service: icon' => 'The icon field must be an image.',
        'an edited service: icon' => 'The icon field must be an image.',
        'About, first save: mission icon' => 'The mission icon field must be an image.',
        'About, first save: vision icon' => 'The vision icon field must be an image.',
        'About, edited: mission icon' => 'The mission icon field must be an image.',
        'About, edited: vision icon' => 'The vision icon field must be an image.',
    ];

    private Masjid $masjid;

    private User $admin;

    private User $super;

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

        // A splash, a push and the composer each call OneSignal; nothing here may leave.
        config([
            'onesignal.api_url' => 'https://onesignal.example.test/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Uploads land on the fake disk, never in this checkout's storage.
        Storage::fake('public');

        // Every screen behind these doors is a module a masjid has by default.
        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'masjid',
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'email' => 'office@example.test',
            'phone' => '+15550001111',
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->super = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+15550004444',
        ]);
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

    /* -------------------------------------------------------------- the tables */

    /** @return array<string, array{string}> */
    public static function doors(): array
    {
        $rows = [];
        foreach (array_keys(self::DOORS) as $door) {
            $rows[$door] = [$door];
        }

        return $rows;
    }

    /**
     * Image bytes under a name that is not an image's, through every door: a page, a page
     * in capitals (the rule lower-cases the name before it looks), an image's name with a
     * page's after it (the LAST extension is the one that counts), a drawing that can
     * carry script, and no extension at all.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedNames(): array
    {
        $rows = [];
        foreach (array_keys(self::DOORS) as $door) {
            foreach (['x.html', 'x.HTML', 'x.jpg.html', 'x.svg', 'x'] as $name) {
                $rows["{$door}: {$name}"] = [$door, $name];
            }
        }

        return $rows;
    }

    /**
     * Every kind of file an office may upload at each door, under a lower-case name and
     * under an upper-case one (phones and cameras write `IMG_0001.JPG`), with the kind of
     * bytes the name says.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function namesAnOfficeMayUpload(): array
    {
        $rows = [];
        foreach (self::DOORS as $door => $kinds) {
            foreach ($kinds as $kind => $endings) {
                foreach ($endings as $ending) {
                    foreach (['photo.' . $ending, 'PHOTO.' . strtoupper($ending)] as $name) {
                        $rows["{$door}: {$name}"] = [$door, $name, $kind];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * PNG bytes under an icon's name that no real icon file can use: `.ico` at every icon
     * door, `.icns` too (the service edit form's `mimes:` names it).
     *
     * @return array<string, array{string, string}>
     */
    public static function iconNamesNoRealFileCanUse(): array
    {
        $rows = [];
        foreach (array_keys(self::ICON_DOORS) as $door) {
            foreach (['icon.ico', 'icon.icns'] as $name) {
                $rows["{$door}: {$name}"] = [$door, $name];
            }
        }

        return $rows;
    }

    /**
     * A REAL icon file of each kind the icon rules' `mimes:` lists name and `image` refuses,
     * at every icon door, with the sentence that door reads for a file that is not an image.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function realIconFiles(): array
    {
        $rows = [];
        foreach (self::ICON_DOORS as $door => $sentence) {
            foreach (['ico', 'icns'] as $kind) {
                $rows["{$door}: a real .{$kind}"] = [$door, $kind, $sentence];
            }
        }

        return $rows;
    }

    /* ---------------------------------------------------------------- refusals */

    #[Test]
    #[DataProvider('refusedNames')]
    public function image_bytes_under_a_name_that_is_not_an_images_are_refused_and_nothing_is_stored(string $key, string $name): void
    {
        $door = $this->door($key);
        $before = $this->state($door['tables']);

        $response = $this->send($door, $this->realImage($name, $door['bytes']));

        $this->assertSame(422, $response->status(), sprintf(
            '%s: %s bytes named "%s" were answered %d; on the public disk: %s; media rows: %s',
            $key,
            $door['bytes'],
            $name,
            $response->status(),
            json_encode(Storage::disk('public')->allFiles()),
            json_encode(DB::table('media')->pluck('file_name', 'collection_name')->all()),
        ));
        $response->assertJsonPath('status', 'failed');
        $this->assertArrayHasKey($door['error'], (array) $response->json('data'), $response->getContent());

        $this->assertSame($before, $this->state($door['tables']), "{$key}: a refused upload still wrote something");
    }

    /* ----------------------------------------------------------- still accepted */

    #[Test]
    #[DataProvider('namesAnOfficeMayUpload')]
    public function every_kind_of_file_an_office_may_upload_here_is_stored_under_the_name_it_came_with(string $key, string $name, string $kind): void
    {
        $door = $this->door($key);

        $response = $this->send($door, $this->realImage($name, $kind));

        $this->assertContains($response->status(), [200, 201, 202], "{$key}: {$kind} bytes named {$name} were refused: " . $response->getContent());

        foreach ($door['collections'] as $collection) {
            $media = DB::table('media')->where('collection_name', $collection)->orderByDesc('id')->first();
            $this->assertNotNull($media, "{$key}: nothing was stored in {$collection}");
            $this->assertSame($name, $media->file_name, "{$key}: stored in {$collection} under another name");
            $this->assertSame('public', $media->disk);
            Storage::disk('public')->assertExists($media->id . '/' . $name);
        }
    }

    /* ------------------------------------------------------------------- icons */

    #[Test]
    #[DataProvider('iconNamesNoRealFileCanUse')]
    public function png_bytes_under_an_icon_name_no_real_icon_can_use_are_refused_and_nothing_is_stored(string $key, string $name): void
    {
        $door = $this->door($key);
        $before = $this->state($door['tables']);

        // The bytes are a PNG, which the field takes, so the name is the only thing wrong
        // and the field's own sentence is the whole answer.
        $this->send($door, $this->realImage($name, 'png'))
            ->assertStatus(422)
            ->assertExactJson([
                'status' => 'failed',
                'data' => [$door['error'] => [$door['sentence']]],
            ]);

        $this->assertSame($before, $this->state($door['tables']), "{$key}: a refused icon still wrote something");
    }

    #[Test]
    #[DataProvider('realIconFiles')]
    public function a_real_icon_file_is_not_an_image_to_this_application_and_is_told_so(string $key, string $kind, string $notAnImage): void
    {
        $door = $this->door($key);
        $before = $this->state($door['tables']);

        $icon = $this->realUpload('icon.' . $kind, $this->iconBytes($kind));
        $this->assertSame($kind, $icon->guessExtension(), "PREMISE: finfo reads these bytes as a .{$kind} ({$icon->getMimeType()}).");

        // `image` refuses these bytes before `mimes:` (which names `ico`, and `icns` on the
        // edit form) is ever asked, and did so before any name was pinned. So the answer is
        // "not an image", once, and never "rename it".
        $this->send($door, $icon)
            ->assertStatus(422)
            ->assertExactJson([
                'status' => 'failed',
                'data' => [$door['error'] => [$notAnImage]],
            ]);

        $this->assertSame($before, $this->state($door['tables']), "{$key}: a refused icon still wrote something");
    }

    /* --------------------------------------------------- what the person is told */

    #[Test]
    #[DataProvider('doors')]
    public function a_file_refused_only_for_its_name_reads_one_sentence_that_says_what_to_do(string $key): void
    {
        $door = $this->door($key);

        // The bytes are an image this field takes, so the name is the only thing wrong.
        $this->send($door, $this->realImage('photo.html', $door['bytes']))
            ->assertStatus(422)
            ->assertExactJson([
                'status' => 'failed',
                'data' => [$door['error'] => [$door['sentence']]],
            ]);
    }

    #[Test]
    #[DataProvider('doors')]
    public function a_file_that_is_not_an_image_is_told_so_once_and_is_not_told_to_rename_it(string $key): void
    {
        $door = $this->door($key);
        $before = $this->state($door['tables']);

        // Under its own name, and under an image's name: renaming a PDF does not get it in,
        // so "rename the file" would be advice that cannot work.
        foreach (['notice.pdf', 'notice.jpg', 'notice.png'] as $name) {
            $response = $this->send($door, $this->realUpload($name, $this->pdfBytes()));

            $this->assertSame(422, $response->status(), "{$key}: a PDF named {$name} was answered {$response->status()}");
            // Read by key, not by path: the bag reports under "images.1", a key with a dot in it.
            $told = (array) $response->json('data');
            $said = (array) ($told[$door['error']] ?? []);

            $this->assertCount(1, $said, "{$key}: a PDF named {$name} was told " . json_encode($told));
            $this->assertStringContainsString('must be an image', $said[0]);
            $this->assertStringNotContainsString('Rename', $said[0]);
            $this->assertSame([$door['error']], array_keys($told), "{$key}: another field was blamed");
        }

        $this->assertSame($before, $this->state($door['tables']), "{$key}: a refused PDF still wrote something");
    }

    /* -------------------------------------------------- the composer's copies */

    #[Test]
    public function one_refused_composer_picture_leaves_nothing_in_any_of_its_three_collections(): void
    {
        $door = $this->door('the publish composer picture');

        // What a good picture leaves, so that "nothing" below is being compared with something.
        $this->send($door, $this->realImage('IMG_0001.JPG'))->assertStatus(202);
        $this->assertSame(
            ['announcements' => 'IMG_0001.JPG', 'broadcasts' => 'IMG_0001.JPG', 'notifications' => 'IMG_0001.JPG'],
            DB::table('media')->orderBy('collection_name')->pluck('file_name', 'collection_name')->all(),
            'PREMISE: one composer picture is kept three times, once per collection.',
        );
        $this->assertCount(3, Storage::disk('public')->allFiles());

        $before = $this->state($door['tables']);

        foreach (['x.html', 'x.HTML', 'x.jpg.html', 'x.svg'] as $name) {
            $this->send($door, $this->realImage($name))->assertStatus(422);
        }

        $this->assertSame($before, $this->state($door['tables']));
        $this->assertSame(3, DB::table('media')->count(), 'a refused picture was copied onward');
        $this->assertSame(1, DB::table('broadcasts')->count());
        $this->assertSame(1, DB::table('announcements')->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    #[Test]
    public function the_composer_does_not_say_the_same_thing_twice_when_the_feed_is_ticked(): void
    {
        $door = $this->door('the publish composer picture');

        // The announcements feed borrows the announcement's own rules. Its picture rule is
        // the composer's plus `required`, so what it would say about a picture that IS there
        // is what the composer has just said.
        $this->send($door, $this->realImage('photo.html'))
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['image' => [$door['sentence']]]]);

        // With no picture at all, the feed's rule is the only one that objects, and it still does.
        $payload = $door['payload'];
        Sanctum::actingAs($this->admin);
        $this->post($door['url'], $payload, self::JSON)
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['image' => ['Announcements feed: The image field is required.']]]);
    }

    /* ---------------------------------------------------------------- the doors */

    /**
     * One door: who knocks, where, with what beside the file, which field carries the file
     * under test, where a refusal is reported, the bytes a refused NAME is sent with (a kind
     * the field takes, so that the name is the only thing wrong), the collections a good
     * file lands in, the tables the door writes, and the sentence a name-only refusal
     * reads. An "edited" door first creates its record through the "new" one.
     *
     * @return array{as: string, url: string, payload: array<string, mixed>, field: string, error: string,
     *               bytes: string, collections: list<string>, tables: list<string>, sentence: string}
     */
    private function door(string $key): array
    {
        $org = '/api/admin/masjids/' . $this->masjid->id;
        $photo = fn (string $what): string => "The {$what}'s file name must end in " . self::PHOTO_ENDINGS . '. Rename the file and upload it again.';
        $defaults = ['as' => 'admin', 'payload' => [], 'field' => 'image', 'bytes' => 'jpeg'];

        $door = match ($key) {
            'a new announcement', 'an edited announcement' => [
                'url' => $org . '/announcements',
                'payload' => $this->announcementFields(),
                'collections' => ['announcements'],
                'tables' => ['announcements'],
                'sentence' => $photo('announcement image'),
            ],
            'a new splash announcement', 'an edited splash announcement' => [
                'url' => $org . '/splash-announcements',
                'payload' => $this->splashFields(),
                'collections' => ['splash_announcements'],
                'tables' => ['splash_announcements'],
                'sentence' => $photo('splash image'),
            ],
            'a gallery photo sent alone' => [
                'url' => $org . '/gallery',
                'collections' => ['galleries'],
                'tables' => [],
                'sentence' => 'A gallery photo\'s file name must end in ' . self::PHOTO_ENDINGS . '. Rename the file and upload it again.',
            ],
            'gallery photos sent together' => [
                'url' => $org . '/gallery',
                // The file under test goes second, after a good one: one bad name refuses the bag.
                'field' => 'images',
                'error' => 'images.1',
                'collections' => ['galleries'],
                'tables' => [],
                'sentence' => 'A gallery photo\'s file name must end in ' . self::PHOTO_ENDINGS . '. Rename the file and upload it again.',
            ],
            'the organisation logo on Details' => [
                'url' => $org . '/details',
                'payload' => [
                    'name' => 'Renamed On Details', 'email' => 'details@example.test', 'phone' => '+15550002222',
                    'timezone' => 'UTC', 'latitude' => '1', 'longitude' => '1',
                ],
                'field' => 'logo',
                'collections' => ['logos'],
                'tables' => ['masjids', 'masjid_social_media_links'],
                'sentence' => $photo('logo'),
            ],
            'the header logo on General settings', 'the footer logo on General settings' => [
                'url' => $org . '/general-settings',
                'payload' => ['copyright_text' => 'Changed on General settings'],
                'field' => str_contains($key, 'header') ? 'header_logo' : 'footer_logo',
                'collections' => [str_contains($key, 'header') ? 'header_logos' : 'footer_logos'],
                'tables' => ['masjids'],
                'sentence' => $photo(str_contains($key, 'header') ? 'header logo' : 'footer logo'),
            ],
            'a new organisation: logo', 'a new organisation: footer logo',
            'an edited organisation: logo', 'an edited organisation: footer logo' => [
                'as' => 'super',
                'url' => '/api/admin/masjids',
                'payload' => $this->organisationFields(),
                'field' => str_contains($key, 'footer') ? 'footer_logo' : 'logo',
                'collections' => [str_contains($key, 'footer') ? 'footer_logos' : 'logos'],
                'tables' => ['masjids', 'masjid_user'],
                'sentence' => $photo(str_contains($key, 'footer') ? 'footer logo' : 'logo'),
            ],
            'a new user: picture', 'an edited user: picture' => [
                'as' => 'super',
                'url' => '/api/admin/users',
                'payload' => [
                    'name' => 'New Person', 'email' => 'new.person@example.test', 'phone' => '+15550007777',
                    'type' => 'MasjidAdmin', 'password' => 'Upload#2026x', 'password_confirmation' => 'Upload#2026x',
                ],
                'field' => 'avatar',
                'collections' => ['avatars'],
                'tables' => ['users'],
                'sentence' => $photo('avatar'),
            ],
            'an admin\'s own profile picture' => [
                'url' => '/api/admin/profile',
                'payload' => ['name' => 'Renamed On Profile', 'email' => 'office@example.test', 'phone' => '+15550003333'],
                'field' => 'avatar',
                'collections' => ['avatars'],
                'tables' => ['users'],
                'sentence' => $photo('avatar'),
            ],
            'a new service: picture', 'an edited service: picture' => [
                'url' => $org . '/services',
                'payload' => $this->serviceFields(),
                'collections' => ['services'],
                'tables' => ['services'],
                'sentence' => $photo('service image'),
            ],
            'a new service: icon' => [
                'url' => $org . '/services',
                'payload' => $this->serviceFields(),
                'field' => 'icon',
                'bytes' => 'png',
                'collections' => ['servicesIcons'],
                'tables' => ['services'],
                'sentence' => 'The service icon\'s file name must end in .png or .webp. Rename the file and upload it again.',
            ],
            'an edited service: icon' => [
                'url' => $org . '/services',
                'payload' => $this->serviceFields(),
                'field' => 'icon',
                'bytes' => 'png',
                'collections' => ['servicesIcons'],
                'tables' => ['services'],
                // The edit rule has always taken a GIF, which the create rule does not.
                'sentence' => 'The service icon\'s file name must end in .png, .gif or .webp. Rename the file and upload it again.',
            ],
            'About, first save: picture', 'About, edited: picture' => [
                'url' => $org . '/about',
                'payload' => $this->aboutFields(),
                'field' => 'about_image',
                'collections' => ['aboutImages'],
                'tables' => ['masjid_abouts'],
                'sentence' => $photo('About Us image'),
            ],
            'About, first save: mission icon', 'About, edited: mission icon',
            'About, first save: vision icon', 'About, edited: vision icon' => [
                'url' => $org . '/about',
                'payload' => $this->aboutFields(),
                'field' => str_contains($key, 'mission') ? 'mission_icon' : 'vision_icon',
                'bytes' => 'png',
                'collections' => [str_contains($key, 'mission') ? 'missionIcons' : 'visionIcons'],
                'tables' => ['masjid_abouts'],
                'sentence' => 'The ' . (str_contains($key, 'mission') ? 'mission' : 'vision')
                    . ' icon\'s file name must end in .png or .webp. Rename the file and upload it again.',
            ],
            'the donation link picture' => [
                'url' => $org . '/donation-link',
                'payload' => ['link' => 'https://give.example.test/donate', 'title' => 'Give', 'message' => 'Donate now'],
                'collections' => ['donation_link'],
                'tables' => ['donation_links'],
                'sentence' => 'The donation image\'s file name must end in ' . self::ANY_IMAGE_ENDINGS . '. Rename the file and upload it again.',
            ],
            'a push notification picture' => [
                'url' => $org . '/notifications',
                'payload' => ['title' => 'Snow closure', 'message' => 'All programs are cancelled today.'],
                'collections' => ['notifications'],
                'tables' => ['notifications'],
                'sentence' => 'The notification image\'s file name must end in ' . self::ANY_IMAGE_ENDINGS . '. Rename the file and upload it again.',
            ],
            'the publish composer picture' => [
                'url' => $org . '/broadcasts',
                'payload' => [
                    'title' => 'Snow closure',
                    'body' => 'All programs are cancelled today because of the storm.',
                    'starts_on' => Carbon::now()->toDateString(),
                    'ends_on' => Carbon::now()->addDays(3)->toDateString(),
                    'audience' => 'everyone',
                    // The two channels that take a copy of the picture.
                    'channels' => ['announcement', 'push'],
                ],
                // The composer's own copy, the announcement's and the notification's.
                'collections' => ['broadcasts', 'announcements', 'notifications'],
                'tables' => ['broadcasts', 'broadcast_deliveries', 'announcements', 'notifications'],
                'sentence' => $photo('image'),
            ],
        } + $defaults;

        $door['error'] ??= $door['field'];

        // Files a door requires beside the one under test, each good.
        $door['payload'] += match (true) {
            str_starts_with($key, 'a new organisation') => [
                'logo' => $this->realImage('logo.png', 'png'),
                'footer_logo' => $this->realImage('footer.png', 'png'),
            ],
            str_starts_with($key, 'a new service') => [
                'image' => $this->realImage('service.jpg'),
                'icon' => $this->realImage('icon.png', 'png'),
            ],
            str_starts_with($key, 'About, first save') => [
                'about_image' => $this->realImage('about.jpg'),
                'mission_icon' => $this->realImage('mission.png', 'png'),
                'vision_icon' => $this->realImage('vision.png', 'png'),
            ],
            default => [],
        };

        // An "edited" door: make the record through the "new" one, then knock on its own address.
        if (str_starts_with($key, 'an edited') || str_starts_with($key, 'About, edited')) {
            $door = $this->edited($key, $door);
        }

        return $door;
    }

    /**
     * @param  array<string, mixed>  $door
     * @return array<string, mixed>
     */
    private function edited(string $key, array $door): array
    {
        $new = match (true) {
            str_contains($key, 'announcement') && ! str_contains($key, 'splash') => 'a new announcement',
            str_contains($key, 'splash') => 'a new splash announcement',
            str_contains($key, 'organisation') => 'a new organisation: logo',
            str_contains($key, 'user') => 'a new user: picture',
            str_contains($key, 'service') => 'a new service: picture',
            default => 'About, first save: picture',
        };

        $made = $this->door($new);
        $response = $this->send($made, $this->realImage('first.' . ($made['bytes'] === 'png' ? 'png' : 'jpg'), $made['bytes']));
        $this->assertContains($response->status(), [200, 201], 'PREMISE: could not make the record to edit: ' . $response->getContent());

        // About is one record per organisation, saved at one address; the others are edited at /{id}.
        if (! str_starts_with($key, 'About')) {
            $door['url'] .= '/' . $response->json('data.id');
        }

        if (str_contains($key, 'user')) {
            // The edit form sends no password unless it is being changed.
            unset($door['payload']['password'], $door['payload']['password_confirmation']);
        }

        return $door;
    }

    /** @param  array<string, mixed>  $door */
    private function send(array $door, UploadedFile $file): TestResponse
    {
        Sanctum::actingAs($door['as'] === 'super' ? $this->super : $this->admin);

        $payload = $door['payload'];
        $payload[$door['field']] = $door['field'] === 'images'
            ? [$this->realImage('first-of-two.jpg'), $file]
            : $file;

        return $this->post($door['url'], $payload, self::JSON);
    }

    /**
     * Everything a door could have written: the public disk, the media table and the
     * door's own tables, row for row.
     *
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    private function state(array $tables): array
    {
        $files = Storage::disk('public')->allFiles();
        sort($files);

        $state = ['public disk' => $files];
        foreach (['media', ...$tables] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    /** @return array<string, string> */
    private function announcementFields(): array
    {
        return [
            'title' => 'Snow closure',
            'details' => 'All programs are cancelled today.',
            'text' => 'All programs are cancelled today.',
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => Carbon::now()->addDays(3)->toDateString(),
        ];
    }

    /** @return array<string, string> */
    private function splashFields(): array
    {
        return [
            'title' => 'Welcome',
            'starts_at' => Carbon::now()->toIso8601String(),
            'ends_at' => Carbon::now()->addDays(3)->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function serviceFields(): array
    {
        return ['title' => 'Counselling', 'description' => 'By appointment.', 'text' => 'By appointment.'];
    }

    /** @return array<string, string> */
    private function aboutFields(): array
    {
        return ['about' => 'About us.', 'mission' => 'Our mission.', 'vision' => 'Our vision.'];
    }

    /**
     * Country and City declare no $fillable, so they go in by query builder.
     *
     * @return array<string, string>
     */
    private function organisationFields(): array
    {
        $countryId = DB::table('countries')->where('code', 'ZZ')->value('id')
            ?? DB::table('countries')->insertGetId(['name' => 'Testland', 'code' => 'ZZ']);
        $cityId = DB::table('cities')->where('country_id', $countryId)->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Testville', 'country_id' => $countryId]);

        return [
            'name' => 'Second Organisation', 'email' => 'second@example.test', 'phone' => '+15550005555',
            'longitude' => '0', 'latitude' => '0', 'address' => '2 Test St', 'timezone' => 'UTC',
            'country_id' => (string) $countryId, 'city_id' => (string) $cityId,
        ];
    }

    /* ---------------------------------------------------------------- the bytes */

    /** A real image of that kind (GD), under the name the client gave it. */
    private function realImage(string $name, string $kind = 'jpeg'): UploadedFile
    {
        return $this->realUpload($name, $this->imageBytes($kind));
    }

    /**
     * The bytes of a real image of that kind, made by GD.
     *
     * A kind is skipped only where this PHP cannot make its bytes: GD is built with or
     * without each format, and a build without WebP (or BMP, or GIF) has no way to write
     * one. The skip names the kind, so a run that proved less says so.
     */
    private function imageBytes(string $kind): string
    {
        $write = ['jpeg' => 'imagejpeg', 'png' => 'imagepng', 'gif' => 'imagegif', 'webp' => 'imagewebp', 'bmp' => 'imagebmp'][$kind];

        if (! function_exists($write)) {
            $this->markTestSkipped("This PHP's GD has no {$write}(), so it cannot make the bytes of a {$kind} file. Nothing is proven about that kind here.");
        }

        ob_start();
        $write(imagecreatetruecolor(8, 8));

        return (string) ob_get_clean();
    }

    /**
     * A real icon file holding one 8 by 8 PNG as its picture, which both formats allow.
     * `ico` is a Windows icon (the six-byte header and one directory entry), which finfo
     * reads as `image/vnd.microsoft.icon`. `icns` is an Apple icon (the `icns` header and
     * one `icp4` element), which finfo reads as `image/x-icns`.
     */
    private function iconBytes(string $kind): string
    {
        $png = $this->imageBytes('png');

        if ($kind === 'ico') {
            return pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 8, 8, 0, 0, 1, 32, strlen($png), 22) . $png;
        }

        $element = 'icp4' . pack('N', 8 + strlen($png)) . $png;

        return 'icns' . pack('N', 8 + strlen($element)) . $element;
    }

    /** The smallest file finfo reads as a PDF. */
    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
