<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Page;
use App\Models\User;
use App\Support\PageDocuments;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToCreateDirectory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A PDF uploaded for a web page: POST /api/admin/masjids/{id}/pages/documents
 * (PageDocumentsController, StorePageDocumentRequest, App\Support\PageDocuments).
 *
 * The file lands on the PUBLIC disk, on the origin where the admin screens keep their sign-in token,
 * and its address is written into page content for good. So what is pinned here is what would be
 * silent if it broke:
 *
 *  1. WHAT IS LET IN: a PDF by its bytes and by its first five bytes, named `.pdf`, at most 25 MB.
 *     The type a browser declares is never read.
 *  2. WHAT IS WRITTEN: a name the server makes, ending `.pdf`, never the client's.
 *  3. WHAT IS ANSWERED: an absolute address built from configuration, never from the request's Host.
 *  4. WHO MAY: exactly the people who may save a page, thirty times an hour each.
 *  5. WHAT IS LEFT BY A FAILURE: nothing. No row without a file.
 *  6. WHAT DID NOT CHANGE: a PDF sent with a section's save is still refused (the image rule), and the
 *     three link fields carry an absolute address to the public API untouched.
 *
 * Every file here is REAL bytes (UploadedFile::fake() answers its type from its name or its argument,
 * which is the one thing these rules must not believe).
 */
class PageDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    /** The public disk's address in this test: an invented platform host, so a leak of the request's Host shows. */
    private const PUBLIC_DISK_URL = 'https://platform.example.test/storage';

    /** The smallest file every reader calls a PDF. */
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        . "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private const HTML = "<!DOCTYPE html><html><body><script>document.title = 'not a document'</script></body></html>\n";

    /** A 1x1 PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Masjid $masjid;

    private User $admin;

    /** Every temporary file this test wrote, so a refused one (never moved to a disk) is not left behind. */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        // A stored upload was moved off its temporary path; a refused one is still there, and one of
        // them is 25 MB.
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

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

        // Uploads land on the fake disk, never in the CI tree's storage. The fake drops the real
        // disk's `url`, so it is given one: production's is absolute (config/filesystems.php), and
        // the address in the answer is built from it.
        Storage::fake('public', ['url' => self::PUBLIC_DISK_URL]);

        $this->masjid = $this->organisation(['web_pages' => true]);
        $this->admin = $this->adminOf($this->masjid);
    }

    /* ------------------------------------------------------------- accepted */

    #[Test]
    public function a_pdf_is_stored_for_the_organisation_under_a_name_the_server_made_and_answered_with_its_address(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post($this->documents(), [
            'document' => $this->upload('Academic Calendar 2026.pdf', self::PDF),
        ])->assertStatus(201)->assertJsonPath('status', 'success');

        $media = DB::table('media')->get();
        $this->assertCount(1, $media);
        $row = $media[0];

        // The whole payload, not only the status.
        $this->assertSame([
            'url' => self::PUBLIC_DISK_URL . "/{$row->id}/academic-calendar-2026.pdf",
            'name' => 'Academic Calendar 2026',
            'size' => strlen(self::PDF),
        ], $response->json('data'));

        $this->assertSame(Masjid::class, $row->model_type);
        $this->assertSame($this->masjid->id, (int) $row->model_id);
        $this->assertSame(PageDocuments::COLLECTION, $row->collection_name);
        $this->assertSame('page_documents', $row->collection_name);
        $this->assertSame('application/pdf', $row->mime_type);
        $this->assertSame('public', $row->disk);
        $this->assertSame('academic-calendar-2026.pdf', $row->file_name);
        $this->assertSame('Academic Calendar 2026', $row->name);

        Storage::disk('public')->assertExists("{$row->id}/academic-calendar-2026.pdf");
        $this->assertSame(self::PDF, Storage::disk('public')->get("{$row->id}/academic-calendar-2026.pdf"));

        // And it is the organisation's own, through the relation the rest of the code reads.
        $this->assertSame([(int) $row->id], $this->masjid->pageDocuments()->pluck('id')->all());
    }

    #[Test]
    public function the_address_comes_from_configuration_and_never_from_the_requests_host(): void
    {
        Sanctum::actingAs($this->admin);

        // This deployment answers to more than one hostname, and any Host reaches the application.
        // Posted to the full address: in a test a `Host` header alone does not become the request's
        // host, which is taken from the address the request is made to.
        $url = $this->post('https://other-host.example.test' . $this->documents(), ['document' => $this->upload('calendar.pdf', self::PDF)])
            ->assertStatus(201)
            ->json('data.url');

        // The request did come in on the other host, and the answer is not built from it.
        $this->assertSame('other-host.example.test', request()->getHost());
        $this->assertStringStartsWith(self::PUBLIC_DISK_URL . '/', $url);
        $this->assertStringNotContainsString('other-host', $url);
    }

    #[Test]
    public function a_pdf_of_exactly_25_mb_is_accepted_and_one_kilobyte_more_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post($this->documents(), ['document' => $this->upload('at-the-limit.pdf', $this->pdfOfKilobytes(25600))])
            ->assertStatus(201);
        $this->assertSame(25600 * 1024, (int) DB::table('media')->value('size'));

        $response = $this->post($this->documents(), ['document' => $this->upload('over.pdf', $this->pdfOfKilobytes(25601))])
            ->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertSame(
            ['This PDF is larger than 25 MB. Export it at a lower quality, or split it into parts.'],
            $response->json('data.document')
        );

        $this->assertSame(1, DB::table('media')->count(), 'the refused file left a row');
    }

    #[Test]
    public function a_name_ending_in_capital_pdf_is_accepted_and_stored_in_lower_case(): void
    {
        Sanctum::actingAs($this->admin);

        // Scanners and some phones write the ending in capitals.
        $url = $this->post($this->documents(), ['document' => $this->upload('SCAN_0001.PDF', self::PDF)])
            ->assertStatus(201)
            ->json('data.url');

        $this->assertStringEndsWith('/scan-0001.pdf', $url);
        $this->assertSame('scan-0001.pdf', DB::table('media')->value('file_name'));
    }

    /* -------------------------------------------------------------- refused */

    #[Test]
    public function anything_that_is_not_a_pdf_by_its_bytes_and_its_name_is_refused_and_nothing_is_stored(): void
    {
        Sanctum::actingAs($this->admin);

        $notAPdf = 'This file is not a PDF. Save or print it as a PDF, then upload that.';
        $wrongName = 'This file\'s name does not end in .pdf. Save or print it as a PDF, then upload that.';

        foreach ([
            'PNG bytes named x.pdf' => [$this->upload('x.pdf', base64_decode(self::PNG_BASE64)), $notAPdf],
            'PDF bytes named x.html' => [$this->upload('x.html', self::PDF), $wrongName],
            'PDF bytes named x.pdf.html' => [$this->upload('x.pdf.html', self::PDF), $wrongName],
            'PDF bytes with no ending' => [$this->upload('calendar', self::PDF), $wrongName],
            'a web page named x.pdf' => [$this->upload('x.pdf', self::HTML), $notAPdf],
            // The sniffer calls this one application/pdf: only the first five bytes refuse it.
            'a web page with a PDF after it, named x.pdf' => [$this->upload('x.pdf', self::HTML . self::PDF), $notAPdf],
            'a PDF that starts one byte late' => [$this->upload('x.pdf', "\n" . self::PDF), $notAPdf],
            'an MP4 named clip.mp4' => [$this->upload('clip.mp4', $this->mp4()), $notAPdf],
            'an MP4 named clip.pdf' => [$this->upload('clip.pdf', $this->mp4()), $notAPdf],
            'an empty file named x.pdf' => [$this->upload('x.pdf', ''), null],
        ] as $what => [$file, $sentence]) {
            $response = $this->post($this->documents(), ['document' => $file]);

            $this->assertSame(422, $response->status(), "{$what} was not refused");
            $this->assertSame('failed', $response->json('status'), $what);
            $this->assertCount(1, (array) $response->json('data.document'), "{$what} was not given one sentence");
            if ($sentence !== null) {
                $this->assertSame([$sentence], $response->json('data.document'), $what);
            }
        }

        $response = $this->post($this->documents(), [])->assertStatus(422);
        $this->assertSame(['Choose a PDF to upload.'], $response->json('data.document'));

        // A string where the file should be is not a file.
        $response = $this->post($this->documents(), ['document' => 'https://files.example.test/calendar.pdf'])->assertStatus(422);
        $this->assertSame(['Choose a PDF to upload.'], $response->json('data.document'));

        $this->assertNothingStored();
    }

    #[Test]
    public function the_type_the_browser_declares_is_never_read(): void
    {
        Sanctum::actingAs($this->admin);

        // A PDF the browser called a web page is a PDF, and is stored as one.
        $url = $this->post($this->documents(), ['document' => $this->upload('calendar.pdf', self::PDF, 'text/html')])
            ->assertStatus(201)
            ->json('data.url');
        $this->assertStringEndsWith('/calendar.pdf', $url);
        $this->assertSame('application/pdf', DB::table('media')->value('mime_type'));

        // A web page the browser called a PDF is a web page, and is refused.
        $this->post($this->documents(), ['document' => $this->upload('page.pdf', self::HTML, 'application/pdf')])
            ->assertStatus(422);

        $this->assertSame(1, DB::table('media')->count());
    }

    /* ------------------------------------------------------ the stored name */

    #[Test]
    public function the_name_on_disk_is_never_the_clients(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([
            // Path characters and a fragment mark.
            '../../evil name#1.pdf' => 'evil-name1.pdf',
            // A middle part the media library refuses with an exception: a 500 if it ever saw it.
            'report.php.pdf' => 'reportphp.pdf',
            'page.html.pdf' => 'pagehtml.pdf',
            // Nothing a slug can keep.
            '***.pdf' => 'document.pdf',
            '.pdf' => 'document.pdf',
            // Longer than any name should be on disk: 80 characters, then the ending.
            str_repeat('a', 200) . '.pdf' => str_repeat('a', 80) . '.pdf',
        ] as $clientName => $stored) {
            $response = $this->post($this->documents(), ['document' => $this->upload($clientName, self::PDF)]);

            $this->assertSame(201, $response->status(), "{$clientName} was not stored: " . $response->getContent());
            $id = (int) DB::table('media')->max('id');
            $this->assertSame(self::PUBLIC_DISK_URL . "/{$id}/{$stored}", $response->json('data.url'), $clientName);
            $this->assertSame($stored, DB::table('media')->where('id', $id)->value('file_name'), $clientName);
            Storage::disk('public')->assertExists("{$id}/{$stored}");
        }

        // Whatever was sent, every stored name is lower-case ASCII with ONE ending, `.pdf`.
        foreach (DB::table('media')->pluck('file_name') as $name) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.pdf$/', $name);
        }
    }

    #[Test]
    public function a_name_in_another_script_is_stored_under_a_safe_ascii_name_and_kept_for_the_office_to_read(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post($this->documents(), ['document' => $this->upload('التقويم الدراسي.pdf', self::PDF)])
            ->assertStatus(201);

        $row = DB::table('media')->first();
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.pdf$/', $row->file_name);
        $this->assertStringEndsWith("/{$row->id}/{$row->file_name}", $response->json('data.url'));
        // The office's own name is kept on the row and answered, so a label can be made from it.
        $this->assertSame('التقويم الدراسي', $row->name);
        $this->assertSame('التقويم الدراسي', $response->json('data.name'));
    }

    #[Test]
    public function the_stored_name_is_made_by_one_function_that_only_ever_returns_a_safe_pdf_name(): void
    {
        foreach ([
            'Academic Calendar 2026' => 'academic-calendar-2026.pdf',
            'report.php' => 'reportphp.pdf',
            '../../etc/passwd' => 'etcpasswd.pdf',
            "tab\tand\nnewline" => 'tab-and-newline.pdf',
            '---' => 'document.pdf',
            '' => 'document.pdf',
            'Ünïcödé Çalendar' => 'unicode-calendar.pdf',
            "bad \xC3\x28 bytes" => null,
        ] as $base => $expected) {
            $name = PageDocuments::storedName((string) $base);

            $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]{0,78}[a-z0-9])?\.pdf$/', $name, (string) $base);
            if ($expected !== null) {
                $this->assertSame($expected, $name);
            }
        }
    }

    /* ---------------------------------------------------------------- gates */

    #[Test]
    public function only_the_people_who_may_save_a_page_may_upload_a_document(): void
    {
        $file = fn () => ['document' => $this->upload('calendar.pdf', self::PDF)];

        // Nobody signed in.
        $this->postJson($this->documents(), $file())->assertStatus(401);

        // A teacher. The `admin` middleware answers 401 in the legacy envelope for every signed-in
        // account that is not an administrator; it has never answered 403.
        Sanctum::actingAs(User::factory()->create(['type' => 'Teacher', 'phone' => $this->phone()]));
        $this->postJson($this->documents(), $file())->assertStatus(401)->assertJsonPath('data', 'Unauthorized.');

        // An administrator of another organisation, aiming at this one.
        $other = $this->organisation(['web_pages' => true]);
        Sanctum::actingAs($this->adminOf($other));
        $this->postJson($this->documents(), $file())->assertStatus(403);

        // This organisation's own administrator, without the grant.
        $ungranted = $this->organisation([]);
        Sanctum::actingAs($this->adminOf($ungranted));
        $this->postJson($this->documents($ungranted), $file())->assertStatus(403);

        // With the grant, and the website switched off.
        $noWebsite = $this->organisation(['web_pages' => true, 'website' => false]);
        Sanctum::actingAs($this->adminOf($noWebsite));
        $response = $this->postJson($this->documents($noWebsite), $file())->assertStatus(403);
        $this->assertStringContainsString('Web Pages Management is switched off for this organisation.', $response->getContent());

        $this->assertNothingStored();

        // A SuperAdmin passes, as on every page route, grant or no grant.
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => $this->phone()])->fresh());
        $this->postJson($this->documents($ungranted), $file())->assertStatus(201);
        $this->assertSame([$ungranted->id], DB::table('media')->pluck('model_id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function the_upload_route_sits_behind_the_page_gates_and_purges_nothing(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/admin/masjids/{masjid_id}/pages/documents'
        );

        $this->assertNotNull($route, 'the upload route is gone');
        $this->assertSame(['POST'], $route->methods());

        $middleware = $route->gatherMiddleware();
        foreach (['auth:sanctum', 'admin', 'tenant', 'capability:web_pages', 'capability:website', 'throttle:page-documents'] as $gate) {
            $this->assertContains($gate, $middleware, "the upload route lost {$gate}");
        }
        // An upload changes nothing a visitor sees; the section save that links it purges.
        $this->assertNotContains('renderer.purge', $middleware);
    }

    #[Test]
    public function an_unknown_organisation_is_a_404_and_not_a_500(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => $this->phone()])->fresh());

        $this->postJson('/api/admin/masjids/999999/pages/documents', ['document' => $this->upload('calendar.pdf', self::PDF)])
            ->assertStatus(404);

        $this->assertNothingStored();
    }

    #[Test]
    public function a_public_disk_with_no_absolute_address_refuses_the_upload_and_keeps_nothing(): void
    {
        // The fake disk as other suites make it: no `url`, so an address would be root-relative, and
        // the public website (another host) would look for the file on itself.
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $this->post($this->documents(), ['document' => $this->upload('calendar.pdf', self::PDF)])
            ->assertStatus(500)
            ->assertJsonPath('status', 'failed');

        $this->assertNothingStored();
    }

    #[Test]
    public function a_copy_that_fails_leaves_no_row_without_a_file(): void
    {
        Sanctum::actingAs($this->admin);

        // The media library saves the row and THEN copies the file. This disk fails the copy as a
        // full or unwritable one does when the file's directory cannot be made: with an exception
        // that is not the refused write the library cleans up after.
        $real = Storage::disk('public');
        Storage::set('public', new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                throw UnableToCreateDirectory::atLocation(dirname((string) $path), 'no space left on device');
            }
        });

        // Twice: each retry used to leave one more row pointing at nothing.
        foreach (['the first try', 'the retry'] as $attempt) {
            $response = $this->post($this->documents(), ['document' => $this->upload('Academic Calendar 2026.pdf', self::PDF)]);

            $this->assertSame(500, $response->status(), $attempt);
            $this->assertSame('failed', $response->json('status'), $attempt);
        }

        $this->assertNothingStored();
        $this->assertSame(0, $this->masjid->pageDocuments()->count());
    }

    /** What the hourly limit says. True of whoever meets it, whether their uploads were stored or refused. */
    private const TOO_MANY = 'You have tried to upload a lot of documents in the last hour. Wait a little, then try again.';

    #[Test]
    public function the_thirty_first_upload_in_an_hour_is_refused_with_a_sentence_and_the_next_hour_is_open_again(): void
    {
        $send = fn (?Masjid $masjid = null) => $this->post($this->documents($masjid), ['document' => $this->upload('calendar.pdf', self::PDF)]);

        Sanctum::actingAs($this->admin);
        for ($upload = 1; $upload <= 30; $upload++) {
            $this->assertSame(201, $send()->status(), "upload {$upload} was not stored");
        }

        $response = $send()->assertStatus(429);
        $this->assertSame(['status' => 'error', 'message' => self::TOO_MANY], $response->json());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame(30, DB::table('media')->count(), 'the refused upload was stored');

        // Counted for each PERSON: a colleague in the same office is not held up.
        Sanctum::actingAs($this->adminOf($this->masjid));
        $send()->assertStatus(201);

        // And the hour passes.
        Sanctum::actingAs($this->admin);
        $send()->assertStatus(429);
        $this->travel(61)->minutes();
        $send()->assertStatus(201);
        $this->assertSame(32, DB::table('media')->count());
    }

    #[Test]
    public function one_person_has_thirty_an_hour_for_each_organisation_however_its_number_is_written(): void
    {
        $send = fn (string $organisation) => $this->post("/api/admin/masjids/{$organisation}/pages/documents", ['document' => $this->upload('calendar.pdf', self::PDF)]);

        // One person setting two organisations up: thirty for the first...
        $second = $this->organisation(['web_pages' => true]);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => $this->phone()])->fresh());
        for ($upload = 1; $upload <= 30; $upload++) {
            $this->assertSame(201, $send((string) $this->masjid->id)->status(), "upload {$upload} to the first was not stored");
        }
        $send((string) $this->masjid->id)->assertStatus(429);

        // ...and thirty more for the second, which the first's count does not touch.
        for ($upload = 1; $upload <= 30; $upload++) {
            $this->assertSame(201, $send((string) $second->id)->status(), "upload {$upload} to the second was not stored");
        }
        $send((string) $second->id)->assertStatus(429);
        $this->assertSame(60, DB::table('media')->count());

        // The organisation is the NUMBER in the address, as the tenant gate reads it: writing the
        // same number another way is the same organisation, and opens no new thirty.
        foreach (['0' . $this->masjid->id, $this->masjid->id . '.0', '%20' . $this->masjid->id] as $spelling) {
            $send($spelling)->assertStatus(429);
        }
        $this->assertSame(60, DB::table('media')->count());
    }

    #[Test]
    public function every_signed_in_request_to_the_route_is_counted_and_one_with_no_sign_in_is_not(): void
    {
        $send = fn (Masjid $masjid, string $bytes = self::PDF) => $this->postJson($this->documents($masjid), ['document' => $this->upload('calendar.pdf', $bytes)]);

        // Nobody signed in: there is no user to count for, and the answer is always the 401.
        for ($attempt = 1; $attempt <= 31; $attempt++) {
            $this->assertSame(401, $send($this->masjid)->status(), "attempt {$attempt}");
        }

        // Signed in, the limiter runs BEFORE the tenant and capability gates and before the
        // upload's own rule (Laravel orders `throttle` straight after `auth`, whatever the route
        // lists). So what is counted is REQUESTS to this address by this person, and a request
        // those refuse is counted like one that is stored. The page tool refuses a wrong or
        // oversize file before it sends one.
        Sanctum::actingAs($this->admin);
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->assertSame(422, $send($this->masjid, self::HTML)->status(), "attempt {$attempt}");
        }
        // Not one of the thirty was stored, and the sentence is still true of this person: they
        // TRIED to upload a lot of documents.
        $this->assertSame(self::TOO_MANY, $send($this->masjid)->assertStatus(429)->json('message'));
        $this->assertStringNotContainsString('have uploaded', self::TOO_MANY);

        // Someone aiming at an organisation that is not theirs is refused thirty times, and then
        // limited, for THAT organisation's address. Their own organisation has its own thirty.
        $other = $this->organisation(['web_pages' => true]);
        Sanctum::actingAs($this->adminOf($other));
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->assertSame(403, $send($this->masjid)->status(), "attempt {$attempt}");
        }
        $send($this->masjid)->assertStatus(429);
        $this->assertNothingStored();

        $send($other)->assertStatus(201);
        $this->assertSame([$other->id], DB::table('media')->pluck('model_id')->map(fn ($id) => (int) $id)->all());
    }

    /* -------------------------------------------- what this did not change */

    #[Test]
    public function a_pdf_sent_with_a_sections_save_is_still_refused_on_both_routes(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->page('documents');

        // The three sections whose link fields take a document's ADDRESS. None takes the file.
        $attempts = [
            'link_list' => ['links_0_url', ['heading' => '', 'description' => '', 'layout' => 'stack',
                'background_color' => '#ffffff', 'links' => [['label' => 'Calendar', 'url' => '', 'icon' => '', 'style' => 'primary']]]],
            'programs' => ['programs_0_link_url', ['heading' => '', 'description' => '', 'layout' => 'cards', 'columns' => 3,
                'background_color' => '#ffffff', 'programs' => [['name' => 'Curriculum', 'link_url' => '', 'link_text' => '']]]],
            'cta' => ['button_link', ['heading' => 'Read it', 'description' => '', 'button_text' => 'Open',
                'button_link' => '', 'button_style' => 'primary', 'background_image_url' => null, 'background_color' => '#f8f9fa']],
        ];

        foreach ($attempts as $type => [$field, $content]) {
            foreach ([
                'the page-section route' => "/api/admin/masjids/{$this->masjid->id}/pages/{$page->id}/sections",
                'the library route' => "/api/admin/masjids/{$this->masjid->id}/sections",
            ] as $where => $url) {
                $response = $this->post($url, [
                    'section_type' => $type,
                    'content' => json_encode($content),
                    'order' => 1,
                    'is_active' => 1,
                    $field => $this->upload('calendar.pdf', self::PDF),
                ]);

                $this->assertSame(422, $response->status(), "{$where} took a PDF as {$type}'s {$field}");
                $this->assertArrayHasKey($field, (array) $response->json('data'), "{$where}, {$type}");
            }
        }

        $this->assertSame(0, DB::table('sections')->count());
        $this->assertNothingStored();
    }

    #[Test]
    public function the_three_link_fields_carry_the_documents_address_to_the_public_api_untouched(): void
    {
        Sanctum::actingAs($this->admin);

        $address = $this->post($this->documents(), ['document' => $this->upload('Academic Calendar 2026.pdf', self::PDF)])
            ->assertStatus(201)
            ->json('data.url');
        $this->assertStringStartsWith('https://', $address);

        $sections = [
            'link_list' => [
                ['heading' => '', 'description' => '', 'layout' => 'stack', 'background_color' => '#ffffff',
                    'links' => [['label' => 'Academic Calendar', 'url' => $address, 'icon' => 'bi-file-earmark-arrow-down', 'style' => 'primary']]],
                'links.0.url',
            ],
            'programs' => [
                ['heading' => '', 'description' => '', 'layout' => 'cards', 'columns' => 3, 'background_color' => '#ffffff',
                    'programs' => [['name' => 'Curriculum', 'level' => '', 'schedule' => '', 'summary' => '', 'highlights' => [],
                        'image_url' => null, 'link_url' => $address, 'link_text' => 'Academic Calendar 2026']]],
                'programs.0.link_url',
            ],
            'cta' => [
                ['heading' => 'Read the calendar', 'description' => '', 'button_text' => 'Open', 'button_link' => $address,
                    'button_style' => 'primary', 'background_image_url' => null, 'background_color' => '#f8f9fa'],
                'button_link',
            ],
        ];

        foreach ($sections as $type => [$content, $path]) {
            $page = $this->page("with-{$type}");

            // Saved the way SectionFormModal saves it: `content` as a JSON string in a multipart body.
            $this->post("/api/admin/masjids/{$this->masjid->id}/pages/{$page->id}/sections", [
                'section_type' => $type,
                'content' => json_encode($content),
                'order' => 1,
                'is_active' => 1,
            ])->assertStatus(201);

            // What a visitor's browser is given: the public API, as the website calls it.
            $served = $this->withHeader('masjid-id', (string) $this->masjid->id)
                ->getJson("/api/v1/pages/{$page->slug}")
                ->assertStatus(200)
                ->json("data.sections.0.content.{$path}");

            $this->assertSame($address, $served, "{$type} did not serve the address as it was saved");
        }
    }

    #[Test]
    public function an_address_with_no_file_behind_it_is_a_404_from_the_application_and_never_the_admin_screen(): void
    {
        // On the servers nginx answers for a file that exists and hands every other path to the
        // application, whose catch-all route answers the admin shell with a 200 for any path it is
        // given. A document that was taken offline, or never was, must not come back as a page.
        $this->get('/storage/999999/academic-calendar-2026.pdf')->assertStatus(404);
        $this->get('/storage/lunch-flyers/gone.jpg')->assertStatus(404);

        // Control: what answered is the route in routes/web.php. The framework's own
        // `storage/{path}` (signed links to the private disk) is still behind the catch-all: a route
        // of the same address would have been replaced by it, here, ahead of the catch-all.
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/storage/999999/academic-calendar-2026.pdf'));
        $this->assertSame('storage/{missing}', $route->uri());
        $this->assertNull($route->getName());
    }

    /* -------------------------------------------------------------- helpers */

    /** An upload backed by REAL bytes; `$declared` is the type a browser would claim for it. */
    private function upload(string $name, string $bytes, ?string $declared = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, $declared, null, true);
    }

    /** A PDF of exactly this many kilobytes: a real header, then padding. */
    private function pdfOfKilobytes(int $kilobytes): string
    {
        return str_pad(self::PDF, $kilobytes * 1024, "% padding\n");
    }

    /** The opening of an MP4 (an `ftyp` box), which is all a type sniffer reads. */
    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 256);
    }

    private function documents(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjid)->id . '/pages/documents';
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, DB::table('media')->count(), 'a refused upload left a media row');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a refused upload left a file');
    }

    private function phone(): string
    {
        return '+1' . random_int(1000000000, 9999999999);
    }

    /** An organisation with these capability decisions (config/capabilities.php). */
    private function organisation(array $capabilities): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Test Organisation ' . uniqid(),
            'email' => 'org-' . uniqid() . '@example.test',
            'phone' => $this->phone(),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
        $masjid->forceFill(['capability_overrides' => $capabilities])->save();

        return $masjid;
    }

    private function adminOf(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => $this->phone()]);

        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $user->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $user->fresh();
    }

    private function page(string $slug): Page
    {
        return Page::create([
            'masjid_id' => $this->masjid->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'is_active' => true,
            'order' => 1,
        ]);
    }
}
