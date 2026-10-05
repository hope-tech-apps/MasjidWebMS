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

    private const DOORS = [
        'a new announcement',
        'an edited announcement',
        'a new splash announcement',
        'an edited splash announcement',
        'a gallery photo sent alone',
        'gallery photos sent together',
        'the organisation logo on Details',
        'the header logo on General settings',
        'the footer logo on General settings',
        'a new organisation: logo',
        'a new organisation: footer logo',
        'an edited organisation: logo',
        'an edited organisation: footer logo',
        'a new user: picture',
        'an edited user: picture',
        'an admin\'s own profile picture',
        'a new service: picture',
        'a new service: icon',
        'an edited service: picture',
        'an edited service: icon',
        'About, first save: picture',
        'About, first save: mission icon',
        'About, first save: vision icon',
        'About, edited: picture',
        'About, edited: mission icon',
        'About, edited: vision icon',
        'the donation link picture',
        'a push notification picture',
        'the publish composer picture',
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
        foreach (self::DOORS as $door) {
            $rows[$door] = [$door];
        }

        return $rows;
    }

    /**
     * Image bytes under a page's name and under a drawing's name, through every door.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedNames(): array
    {
        $rows = [];
        foreach (self::DOORS as $door) {
            foreach (['x.html', 'x.svg'] as $name) {
                $rows["{$door}: {$name}"] = [$door, $name];
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
    #[DataProvider('doors')]
    public function a_file_named_as_a_camera_names_it_is_stored_under_that_name(string $key): void
    {
        $door = $this->door($key);

        $response = $this->send($door, $this->realImage($door['good'], $door['bytes']));

        $this->assertContains($response->status(), [200, 201, 202], "{$key}: {$door['good']} was refused: " . $response->getContent());

        foreach ($door['collections'] as $collection) {
            $media = DB::table('media')->where('collection_name', $collection)->orderByDesc('id')->first();
            $this->assertNotNull($media, "{$key}: nothing was stored in {$collection}");
            $this->assertSame($door['good'], $media->file_name, "{$key}: stored in {$collection} under another name");
            $this->assertSame('public', $media->disk);
            Storage::disk('public')->assertExists($media->id . '/' . $door['good']);
        }
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
     * under test, where a refusal is reported, what bytes that field takes, the collections
     * a good file lands in, the tables the door writes, and the sentence a name-only
     * refusal reads. An "edited" door first creates its record through the "new" one.
     *
     * @return array{as: string, url: string, payload: array<string, mixed>, field: string, error: string,
     *               bytes: string, good: string, collections: list<string>, tables: list<string>, sentence: string}
     */
    private function door(string $key): array
    {
        $org = '/api/admin/masjids/' . $this->masjid->id;
        $photo = fn (string $what): string => "The {$what}'s file name must end in " . self::PHOTO_ENDINGS . '. Rename the file and upload it again.';
        $defaults = ['as' => 'admin', 'payload' => [], 'field' => 'image', 'bytes' => 'jpeg', 'good' => 'IMG_0001.JPG'];

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
                'good' => 'ICON_0001.PNG',
                'collections' => ['servicesIcons'],
                'tables' => ['services'],
                'sentence' => 'The service icon\'s file name must end in .png, .ico or .webp. Rename the file and upload it again.',
            ],
            'an edited service: icon' => [
                'url' => $org . '/services',
                'payload' => $this->serviceFields(),
                'field' => 'icon',
                'bytes' => 'png',
                'good' => 'ICON_0001.PNG',
                'collections' => ['servicesIcons'],
                'tables' => ['services'],
                // The edit rule has always taken two kinds the create rule does not name.
                'sentence' => 'The service icon\'s file name must end in .png, .gif, .ico, .icns or .webp. Rename the file and upload it again.',
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
                'good' => 'ICON_0001.PNG',
                'collections' => [str_contains($key, 'mission') ? 'missionIcons' : 'visionIcons'],
                'tables' => ['masjid_abouts'],
                'sentence' => 'The ' . (str_contains($key, 'mission') ? 'mission' : 'vision')
                    . ' icon\'s file name must end in .png, .ico or .webp. Rename the file and upload it again.',
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
        $image = imagecreatetruecolor(8, 8);

        ob_start();
        match ($kind) {
            'jpeg' => imagejpeg($image),
            'png' => imagepng($image),
        };

        return $this->realUpload($name, (string) ob_get_clean());
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
