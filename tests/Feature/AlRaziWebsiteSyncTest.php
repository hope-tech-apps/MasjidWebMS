<?php

namespace Tests\Feature;

use App\Console\Commands\SyncAlRaziWebsiteSubmissions;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormResponseAttachment;
use App\Models\Masjid;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `alrazi:sync-website`: the school website's registrations and job applications,
 * copied into two switched-off Manara forms.
 *
 * These rows are children's records — medical details, custody documents, and on
 * the site an SSN. What is pinned here, in the plan's lettering:
 *
 *   (a) a first run creates the responses, the attachments and the counter;
 *   (b) a second run is a no-op: nothing written, nothing fetched again;
 *   (c) a payment change on the site updates data.websitePaymentStatus only, and
 *       staff's status and notes survive it;
 *   (d) an SSN and an SSN card are never stored, logged or even sent to be signed,
 *       whatever the export sends;
 *   (e) a file the upload path would refuse (HEIC, too large) is not stored, and
 *       says so at warning;
 *   (f) no email, ever — and a form with receipts on is refused outright;
 *   (g) unconfigured: exit 0 and no request at all (every box but production);
 *   (h) cross-tenant: another organisation's form with the same slug is never
 *       written, and a form outside the configured organisation is refused;
 *   (i) a live form is refused;
 *   (j) unknown fields are logged by path, and no log line carries a value;
 *   (k) the unique index refuses a second copy of one website row.
 *
 * The export is faked at the HTTP layer with the contract's own shapes. Every
 * value below is obviously fake; none is a real person's.
 */
class AlRaziWebsiteSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://test-project.supabase.co';

    private const URL = self::ORIGIN . '/functions/v1/export-submissions';

    private const TOKEN = 'test-export-token';

    private const FAKE_SSN = '000-00-0000';

    private const REG_ID = '00000000-0000-4000-8000-000000000001';

    private const CAREERS_ID = '00000000-0000-4000-8000-0000000000c1';

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Masjid $school;

    private Masjid $other;

    /** @var list<array<string,mixed>> what the export's registrations table holds */
    private array $registrations = [];

    /** @var list<array<string,mixed>> what the export's careers table holds */
    private array $careers = [];

    /** @var array<string,string> "<bucket>/<path>" => the object's bytes */
    private array $objects = [];

    /** @var array<string,string> signed-url token => "<bucket>/<path>" */
    private array $signed = [];

    /** Rows per page the fake export answers with. */
    private int $pageSize = 200;

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

        Storage::fake((string) config('forms.attachments.disk', 'local'));
        Mail::fake();

        $this->school = $this->makeMasjid();
        $this->other = $this->makeMasjid();

        $this->importForms($this->school);

        config(['services.alrazi_export' => [
            'url' => self::URL,
            'token' => self::TOKEN,
            'masjid_id' => $this->school->id,
            'include_insurance' => false,
            'timeout' => 5,
        ]]);

        $this->registrations = [$this->registrationRow()];
        $this->careers = [$this->careersRow()];

        $this->objects = [
            'application-documents/' . $this->docPath('birth_certificate') => self::PDF,
            'application-documents/' . $this->docPath('immunization') => self::PDF,
            'resumes/' . $this->resumePath() => self::PDF,
        ];

        $this->fakeExport();
    }

    // ------------------------------------------------------------------ fixtures

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    /** Through form:import, so the shipped JSON is proven to pass the builder's own rules. */
    private function importForms(Masjid $masjid): void
    {
        foreach (SyncAlRaziWebsiteSubmissions::TABLES as $slug) {
            $this->assertSame(0, Artisan::call('form:import', [
                'masjid' => $masjid->id,
                'path' => "database/forms/{$slug}.json",
            ]), "database/forms/{$slug}.json must import cleanly: " . Artisan::output());
        }
    }

    private function form(string $slug, ?Masjid $masjid = null): Form
    {
        return Form::query()
            ->where('masjid_id', ($masjid ?? $this->school)->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function registrationForm(): Form
    {
        return $this->form(SyncAlRaziWebsiteSubmissions::REGISTRATION_SLUG);
    }

    private function careersForm(): Form
    {
        return $this->form(SyncAlRaziWebsiteSubmissions::CAREERS_SLUG);
    }

    private function docPath(string $category, string $id = '0001'): string
    {
        return "incoming/00000000-0000-4000-8000-00000000{$id}-{$category}-test.pdf";
    }

    private function resumePath(): string
    {
        return 'kindergarten-lead-teacher/00000000-0000-4000-8000-00000000abcd-test-resume.pdf';
    }

    /** @return array<string,mixed> */
    private function doc(string $category, ?string $contentType = 'application/pdf', ?int $size = null): array
    {
        return [
            'category' => $category,
            'path' => $this->docPath($category),
            'original_filename' => "{$category}-test.pdf",
            'size_bytes' => $size ?? strlen(self::PDF),
            'content_type' => $contentType,
        ];
    }

    /** @return array<string,mixed> One row of registration_applications, as the export returns it. */
    private function registrationRow(array $overrides = []): array
    {
        return array_replace([
            'id' => self::REG_ID,
            'created_at' => '2026-09-01T10:00:00+00:00',
            'student_first_name' => 'Test',
            'student_middle_name' => null,
            'student_last_name' => 'Child',
            'student_dob' => '2021-01-01',
            'grade' => 'KG',
            'program' => 'full_time',
            'parent_first_name' => 'Test',
            'parent_last_name' => 'Parent',
            'parent_email' => 'parent@example.invalid',
            'parent_phone' => '555-555-0100',
            'data' => [
                'student' => [
                    'first_name' => 'Test',
                    'last_name' => 'Child',
                    'gender' => 'female',
                    'dob' => '2021-01-01',
                    'place_of_birth_city' => 'Testville',
                    'place_of_birth_state' => 'NC',
                    'home_street' => '1 Test St',
                    'home_city' => 'Testville',
                    'home_state' => 'NC',
                    'home_zip' => '00000',
                ],
                'language' => [
                    'first_language' => 'Test Language',
                    'languages_at_home' => 'Test Language',
                    'speaks_english_fluently' => true,
                    'reads_writes_english' => true,
                    'needs_english_support' => false,
                ],
                'parent1' => [
                    'full_name' => 'Test Parent',
                    'relationship' => 'Mother',
                    'cell_phone' => '555-555-0100',
                    'email' => 'parent@example.invalid',
                    'languages_spoken' => ['english' => true, 'arabic' => false],
                ],
                'primary_contact' => 'parent1',
                'emergency_contacts' => [
                    ['name' => 'Test Contact One', 'relationship' => 'Aunt', 'phone' => '555-555-0101'],
                    ['name' => 'Test Contact Two', 'relationship' => 'Uncle', 'phone' => '555-555-0102'],
                ],
                'authorized_pickup' => [
                    ['name' => 'Test Pickup', 'relationship' => 'Grandparent'],
                ],
                'medical' => [
                    'insurance_provider' => 'Test Insurer',
                    'conditions' => ['asthma' => false, 'heart_disease' => false, 'epilepsy' => false, 'diabetes' => false, 'adhd_add' => false],
                    'emergency_authorizations' => ['administer_first_aid' => true, 'contact_ems' => true, 'transport_to_facility' => true],
                ],
            ],
            'documents' => [$this->doc('birth_certificate'), $this->doc('immunization')],
            'tuition_plan' => 'annual',
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'base_amount_cents' => 125000,
            'surcharge_cents' => 0,
            'total_amount_cents' => 125000,
            'amount_paid_cents' => null,
            'stripe_payment_intent_id' => null,
            'status' => 'new',
        ], $overrides);
    }

    /** @return array<string,mixed> One row of careers_applications. */
    private function careersRow(array $overrides = []): array
    {
        return array_replace([
            'id' => self::CAREERS_ID,
            'created_at' => '2026-09-02T09:00:00+00:00',
            'role' => 'Kindergarten Lead Teacher',
            'applicant_first_name' => 'Test',
            'applicant_last_name' => 'Applicant',
            'email' => 'applicant@example.invalid',
            'phone' => '555-555-0199',
            'resume_path' => $this->resumePath(),
            'resume_url' => '',
            'cover_letter' => 'Test cover letter.',
            'refs' => 'Test references.',
            'start_availability' => 'Immediately',
            'status' => 'new',
        ], $overrides);
    }

    /**
     * The export, per the contract: GET ?table= pages, POST /sign, and the signed
     * Storage URLs it hands out. Reads the fixtures at call time, so a test can
     * change "the website" between two runs.
     */
    private function fakeExport(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_starts_with($path, '/storage/v1/object/sign/')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $key = $this->signed[$query['token'] ?? ''] ?? null;

                return $key !== null && isset($this->objects[$key])
                    ? Http::response($this->objects[$key], 200, ['Content-Type' => 'application/octet-stream'])
                    : Http::response('', 404);
            }

            if ($request->header('Authorization') !== ['Bearer ' . self::TOKEN]) {
                return Http::response(['error' => 'unauthorized'], 401);
            }

            if ($request->method() === 'POST' && $path === '/functions/v1/export-submissions/sign') {
                $urls = [];

                foreach ($request->data()['paths'] ?? [] as $object) {
                    $key = $object['bucket'] . '/' . $object['path'];

                    if (isset($this->objects[$key])) {
                        $token = md5($key);
                        $this->signed[$token] = $key;
                        $urls[$key] = self::ORIGIN . '/storage/v1/object/sign/' . $token . '?token=' . $token;
                    }
                }

                return Http::response(['urls' => $urls]);
            }

            if ($request->method() === 'GET' && $path === '/functions/v1/export-submissions') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                $rows = match ($query['table'] ?? null) {
                    'registrations' => $this->registrations,
                    'careers' => $this->careers,
                    default => null,
                };

                if ($rows === null) {
                    return Http::response(['error' => 'unknown table'], 400);
                }

                $offset = isset($query['after']) ? (int) substr($query['after'], strlen('cursor-')) : 0;
                $slice = array_slice($rows, $offset, $this->pageSize);
                $end = $offset + count($slice);

                return Http::response([
                    'rows' => array_values($slice),
                    'next' => $end < count($rows) ? 'cursor-' . $end : null,
                ]);
            }

            return Http::response(['error' => 'not found'], 404);
        });
    }

    private function sync(array $options = []): int
    {
        return Artisan::call('alrazi:sync-website', $options);
    }

    private function registration(): FormResponse
    {
        return FormResponse::query()->where('form_id', $this->registrationForm()->id)->sole();
    }

    private function careersResponse(): FormResponse
    {
        return FormResponse::query()->where('form_id', $this->careersForm()->id)->sole();
    }

    /** @return list<Request> */
    private function signRequests(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/sign'))
            ->values()
            ->all();
    }

    /** Every log line the application writes, message and context, for "never logged" checks. */
    private function captureLogs(): \ArrayObject
    {
        $lines = new \ArrayObject();

        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($lines) {
            $lines[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });

        return $lines;
    }

    private function allFilesOnDisk(): array
    {
        return Storage::disk((string) config('forms.attachments.disk', 'local'))->allFiles();
    }

    // ------------------------------------------------------------ (a) first run

    #[Test]
    public function a_first_run_creates_the_responses_their_attachments_and_the_counts(): void
    {
        $this->assertSame(0, $this->sync());

        $registration = $this->registration();
        $careers = $this->careersResponse();

        $this->assertSame(1, $this->registrationForm()->response_count);
        $this->assertSame(1, $this->careersForm()->response_count);

        // Tenant, identity and triage state.
        $this->assertSame($this->school->id, (int) $registration->masjid_id);
        $this->assertSame('Test Parent', $registration->respondent_name);
        $this->assertSame('parent@example.invalid', $registration->respondent_email);
        $this->assertSame('555-555-0100', $registration->respondent_phone);
        $this->assertSame('new', $registration->status);
        $this->assertSame(1, $registration->entry_count);
        $this->assertSame('2026-09-01 10:00:00', $registration->submitted_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($registration->external_synced_at);

        // The website's id is the match key, and the public uuid is still Manara's own.
        $this->assertSame(self::REG_ID, $registration->external_ref);
        $this->assertNotNull($registration->uuid);
        $this->assertNotSame(self::REG_ID, $registration->uuid);

        // Values verbatim; money as dollars; Manara's payment columns untouched.
        $this->assertSame('KG', $registration->data['grade']);
        $this->assertSame('full_time', $registration->data['program']);
        $this->assertSame('pending', $registration->data['websitePaymentStatus']);
        $this->assertSame('1250.00', $registration->data['websiteBaseAmount']);
        $this->assertSame('Test Pickup', $registration->data['pickup1Name']);
        $this->assertCount(2, $registration->data['emergencyContacts']);
        $this->assertNull($registration->payment_method);
        $this->assertNull($registration->payment_status);
        $this->assertNull($registration->amount_due);

        // Documents: on the private disk, recorded, and named in `data`.
        $this->assertEqualsCanonicalizing(
            ['docBirthCertificate', 'docImmunization'],
            $registration->attachments()->pluck('field')->all()
        );
        $this->assertSame('birth_certificate-test.pdf', $registration->data['docBirthCertificate']);
        foreach ($registration->attachments as $attachment) {
            $this->assertSame('application/pdf', $attachment->mime_type);
            $this->assertTrue($attachment->exists(), 'the bytes are on the disk');
            $this->assertStringStartsWith("form-attachments/{$this->school->id}/", $attachment->path);
        }

        $this->assertSame('Kindergarten Lead Teacher', $careers->data['role']);
        $this->assertSame('Test Applicant', $careers->respondent_name);
        $this->assertSame(['resume'], $careers->attachments()->pluck('field')->all());
        $this->assertSame('test-resume.pdf', $careers->data['resume']);

        $this->assertCount(3, $this->allFilesOnDisk());
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_follows_the_exports_cursor_across_pages(): void
    {
        $this->pageSize = 1;
        $this->registrations = [
            $this->registrationRow(),
            $this->registrationRow(['id' => '00000000-0000-4000-8000-000000000002', 'created_at' => '2026-09-01T11:00:00+00:00', 'documents' => []]),
            $this->registrationRow(['id' => '00000000-0000-4000-8000-000000000003', 'created_at' => '2026-09-01T12:00:00+00:00', 'documents' => []]),
        ];

        $this->assertSame(0, $this->sync());

        $this->assertSame(3, FormResponse::query()->where('form_id', $this->registrationForm()->id)->count());
        $this->assertSame(3, $this->registrationForm()->response_count);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'table=registrations') && str_contains($r->url(), 'after=cursor-2'));
    }

    // ----------------------------------------------------------- (b) idempotent

    #[Test]
    public function a_second_run_writes_nothing_and_fetches_nothing(): void
    {
        $this->assertSame(0, $this->sync());

        $before = FormResponse::query()->orderBy('id')->get(['id', 'data', 'updated_at', 'external_synced_at', 'respondent_name'])->toArray();
        $attachments = FormResponseAttachment::query()->orderBy('id')->pluck('path')->all();
        $signs = count($this->signRequests());

        $this->travel(10)->minutes();

        $this->assertSame(0, $this->sync());
        $this->assertStringContainsString('created=0 updated=0 unchanged=2', Artisan::output());

        $this->assertSame($before, FormResponse::query()->orderBy('id')->get(['id', 'data', 'updated_at', 'external_synced_at', 'respondent_name'])->toArray());
        $this->assertSame($attachments, FormResponseAttachment::query()->orderBy('id')->pluck('path')->all(), 'no second attachment');
        $this->assertSame($signs, count($this->signRequests()), 'nothing is signed or downloaded again');
        $this->assertSame(1, $this->registrationForm()->response_count);
        $this->assertSame(1, $this->careersForm()->response_count);
        $this->assertCount(3, $this->allFilesOnDisk());
    }

    // --------------------------------------------------- (c) payment change

    #[Test]
    public function a_payment_change_updates_the_website_fields_and_leaves_staffs_work_alone(): void
    {
        $this->assertSame(0, $this->sync());

        $row = $this->registration();
        $row->update(['status' => 'confirmed', 'admin_notes' => 'Test note from staff']);
        $dataBefore = $row->fresh()->data;

        $this->registrations = [$this->registrationRow([
            'payment_status' => 'paid',
            'amount_paid_cents' => 125000,
            'stripe_payment_intent_id' => 'pi_test_0000',
        ])];

        $this->assertSame(0, $this->sync());

        $row = $this->registration();

        $this->assertSame('paid', $row->data['websitePaymentStatus']);
        $this->assertSame('1250.00', $row->data['websiteAmountPaid']);
        $this->assertSame('pi_test_0000', $row->data['websiteStripePaymentId']);

        // Nothing else in `data` moved.
        $changed = ['websitePaymentStatus', 'websiteAmountPaid', 'websiteStripePaymentId'];
        $this->assertEquals(
            array_diff_key($dataBefore, array_flip($changed)),
            array_diff_key($row->data, array_flip($changed))
        );

        // Staff's triage survives, and Manara's own payment leg is never touched.
        $this->assertSame('confirmed', $row->status);
        $this->assertSame('Test note from staff', $row->admin_notes);
        $this->assertNull($row->payment_status);
        $this->assertNull($row->payment_method);
        $this->assertNull($row->paid_at);
        $this->assertSame(2, $row->attachments()->count());
        $this->assertSame(1, $this->registrationForm()->response_count);
    }

    // ------------------------------------------------------------ (d) SSN

    #[Test]
    public function an_ssn_and_an_ssn_card_are_never_stored_logged_or_sent_to_be_signed(): void
    {
        $logs = $this->captureLogs();

        $row = $this->registrationRow();
        $row['data']['student']['ssn'] = self::FAKE_SSN;
        $row['data']['parent1']['SSN'] = self::FAKE_SSN;
        $row['documents'][] = $this->doc('ssn_card');
        $this->registrations = [$row];
        $ssnKey = 'application-documents/' . $this->docPath('ssn_card');
        // The export holds the object, and would even sign it if asked.
        $this->objects[$ssnKey] = self::PDF;

        $this->assertSame(0, $this->sync());

        $registration = $this->registration();

        // Not in any column of any row this import writes.
        $everything = json_encode([
            DB::table('form_responses')->get(),
            DB::table('form_response_attachments')->get(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::FAKE_SSN, $everything);
        $this->assertStringNotContainsString('ssn_card', $everything);

        $this->assertEqualsCanonicalizing(
            ['docBirthCertificate', 'docImmunization'],
            $registration->attachments()->pluck('field')->all()
        );
        $this->assertCount(3, $this->allFilesOnDisk(), 'two documents and one résumé; no card');

        // Never asked for: not signed, not downloaded.
        foreach ($this->signRequests() as $request) {
            $this->assertStringNotContainsString('ssn_card', json_encode($request->data(), JSON_THROW_ON_ERROR));
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), md5($ssnKey)));

        // And not in the log either.
        $this->assertStringNotContainsString(self::FAKE_SSN, json_encode($logs->getArrayCopy(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function the_insurance_policy_number_is_imported_only_when_configured(): void
    {
        $row = $this->registrationRow();
        $row['data']['medical']['insurance_policy_number'] = 'TEST-POLICY-0000';
        $this->registrations = [$row];

        $this->assertSame(0, $this->sync());

        $this->assertArrayNotHasKey('insurancePolicyNumber', $this->registration()->data);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'table=registrations') && str_contains($r->url(), 'insurance=0'));

        config(['services.alrazi_export.include_insurance' => true]);

        $this->assertSame(0, $this->sync());

        $this->assertSame('TEST-POLICY-0000', $this->registration()->data['insurancePolicyNumber']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'table=registrations') && str_contains($r->url(), 'insurance=1'));
    }

    // ------------------------------------------------------ (e) refused files

    #[Test]
    public function a_heic_or_oversized_document_is_not_stored_and_is_logged_at_warning(): void
    {
        Log::spy();

        // A HEIC photo the family's phone produced, which the site allows and
        // Manara's attachment types do not. Declared as a JPEG, so only the bytes
        // can tell — and the bytes are what is checked.
        $heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64);
        $this->objects['application-documents/' . $this->docPath('immunization')] = $heic;
        $row = $this->registrationRow();
        $row['documents'] = [$this->doc('birth_certificate'), $this->doc('immunization', 'image/jpeg', strlen($heic))];
        $this->registrations = [$row];

        // And a résumé over the ceiling, which the site recorded no size for.
        config(['forms.attachments.max_size_kb' => 1]);
        $this->objects['resumes/' . $this->resumePath()] = self::PDF . str_repeat('%', 2048);

        $this->assertSame(0, $this->sync());

        $registration = $this->registration();
        $this->assertSame(['docBirthCertificate'], $registration->attachments()->pluck('field')->all());
        $this->assertArrayNotHasKey('docImmunization', $registration->data);
        $this->assertSame(0, $this->careersResponse()->attachments()->count());
        $this->assertArrayNotHasKey('resume', $this->careersResponse()->data);
        $this->assertCount(1, $this->allFilesOnDisk(), 'only the PDF that passes is on the disk');

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains((string) $message, 'was not stored')
                && ($context['field'] ?? null) === 'docImmunization'
                && ($context['response_id'] ?? null) === $registration->id
        )->once();

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains((string) $message, 'was not stored')
                && ($context['field'] ?? null) === 'resume'
        )->once();
    }

    // ------------------------------------------------------------- (f) email

    #[Test]
    public function nothing_is_ever_emailed(): void
    {
        $this->assertSame(0, $this->sync());
        $this->registrations = [$this->registrationRow(['payment_status' => 'paid'])];
        $this->assertSame(0, $this->sync());

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_form_that_would_email_receipts_is_refused_before_any_request(): void
    {
        foreach ([true, null] as $receipts) {
            $form = $this->registrationForm();
            $settings = $form->settings;

            if ($receipts === null) {
                // Absent means ON — FormNotifier sends receipts unless told not to.
                unset($settings['confirmationEmail']);
            } else {
                $settings['confirmationEmail'] = $receipts;
            }

            $form->settings = $settings;
            $form->save();

            $this->assertSame(1, $this->sync(), 'receipts ' . var_export($receipts, true) . ' must be refused');
            $this->assertStringContainsString('would email receipts', Artisan::output());
        }

        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertSame(0, FormResponse::query()->count());
    }

    // --------------------------------------------------------- (g) unconfigured

    #[Test]
    public function unconfigured_it_exits_zero_and_makes_no_request(): void
    {
        foreach ([
            ['url' => null, 'token' => null],
            ['url' => self::URL, 'token' => ''],
            ['url' => '', 'token' => self::TOKEN],
        ] as $blank) {
            config(['services.alrazi_export.url' => $blank['url'], 'services.alrazi_export.token' => $blank['token']]);

            $this->assertSame(0, $this->sync());
        }

        Http::assertNothingSent();
        $this->assertSame(0, FormResponse::query()->count());
    }

    // ------------------------------------------------------ (h) cross-tenant

    #[Test]
    public function another_organisations_form_with_the_same_slug_is_never_written(): void
    {
        $this->importForms($this->other);

        $this->assertSame(0, $this->sync());

        foreach (SyncAlRaziWebsiteSubmissions::TABLES as $slug) {
            $theirs = $this->form($slug, $this->other);
            $this->assertSame(0, FormResponse::query()->where('form_id', $theirs->id)->count(), "{$slug} of another organisation was written");
            $this->assertSame(0, $theirs->response_count);
            $this->assertSame(1, FormResponse::query()->where('form_id', $this->form($slug)->id)->count());
        }

        $this->assertSame(0, FormResponse::query()->where('masjid_id', $this->other->id)->count());
        $this->assertSame(0, FormResponseAttachment::query()->where('masjid_id', $this->other->id)->count());
    }

    #[Test]
    public function a_form_outside_the_configured_organisation_is_refused(): void
    {
        // The configured organisation has no such forms; the only ones are the
        // school's. They must not be picked up by slug alone.
        config(['services.alrazi_export.masjid_id' => $this->other->id]);

        $this->assertSame(1, $this->sync());
        $this->assertStringContainsString('does not exist in organisation ' . $this->other->id, Artisan::output());

        Http::assertNothingSent();
        $this->assertSame(0, FormResponse::query()->count());

        // And the ownership check itself, for a form handed in from anywhere.
        $this->assertStringContainsString(
            'belongs to another organisation',
            (string) SyncAlRaziWebsiteSubmissions::refusal($this->registrationForm(), $this->other->id, SyncAlRaziWebsiteSubmissions::REGISTRATION_SLUG)
        );
    }

    // -------------------------------------------------------- (i) live form

    #[Test]
    public function a_live_form_is_refused_before_any_request(): void
    {
        $form = $this->careersForm();
        $form->is_active = true;
        $form->save();

        $this->assertSame(1, $this->sync());
        $this->assertStringContainsString('is switched on', Artisan::output());

        Http::assertNothingSent();
        $this->assertSame(0, FormResponse::query()->count());
    }

    // ---------------------------------------------------- (j) unknown keys

    #[Test]
    public function unknown_fields_are_logged_by_path_and_no_log_line_carries_a_value(): void
    {
        $logs = $this->captureLogs();

        $row = $this->registrationRow();
        $row['data']['student']['shoe_size'] = 'TEST-SECRET-VALUE-1';
        $row['data']['emergency_contacts'][0]['email'] = 'TEST-SECRET-VALUE-2';
        $row['favourite_colour'] = 'TEST-SECRET-VALUE-3';
        $this->registrations = [$row];

        $this->assertSame(0, $this->sync());

        $warning = collect($logs)->first(
            fn (array $line) => $line['level'] === 'warning' && str_contains($line['message'], 'does not map')
        );

        $this->assertNotNull($warning, 'an unknown field must be reported at warning, the level production logs at');
        $this->assertSame(['data.emergency_contacts.*.email', 'data.student.shoe_size', 'favourite_colour'], $warning['context']['paths']);
        $this->assertSame('registrations', $warning['context']['table']);

        $this->assertStringNotContainsString('TEST-SECRET', json_encode($logs->getArrayCopy(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('TEST-SECRET', json_encode($this->registration()->data, JSON_THROW_ON_ERROR), 'an unmapped value is not imported');

        // The rest of the row still came over.
        $this->assertSame('KG', $this->registration()->data['grade']);
    }

    // ------------------------------------------------------- (k) the schema

    #[Test]
    public function the_unique_index_refuses_a_second_copy_of_one_website_row(): void
    {
        $form = $this->registrationForm();
        $other = $this->careersForm();

        $make = function (Form $form, ?string $ref): FormResponse {
            $row = new FormResponse([
                'form_id' => $form->id,
                'masjid_id' => $form->masjid_id,
                'data' => [],
                'submitted_at' => now(),
            ]);
            $row->external_ref = $ref;
            $row->save();

            return $row;
        };

        $make($form, 'test-ref-1');
        $make($other, 'test-ref-1');   // the same id on another form is a different row
        $make($form, null);
        $make($form, null);            // NULLs never collide: every response written here

        $this->expectException(QueryException::class);
        $make($form, 'test-ref-1');
    }

    #[Test]
    public function the_columns_and_index_are_what_mysql_will_be_given(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('form_responses', 'external_ref'));
        $this->assertSame('datetime', Schema::getColumnType('form_responses', 'external_synced_at'));

        $columns = collect(Schema::getColumns('form_responses'))->keyBy('name');
        $this->assertTrue($columns['external_ref']['nullable']);
        $this->assertTrue($columns['external_synced_at']['nullable']);

        $index = collect(Schema::getIndexes('form_responses'))->keyBy('name')->get('form_resp_form_external_ref_unique');
        $this->assertNotNull($index, 'the hand-named unique index exists');
        $this->assertSame(['form_id', 'external_ref'], $index['columns']);
        $this->assertTrue((bool) $index['unique']);
        $this->assertLessThanOrEqual(64, strlen('form_resp_form_external_ref_unique'));
    }

    /**
     * SQLite enforces no VARCHAR length, so the width MySQL will enforce is read off
     * the migration's own Blueprint (the HeartbeatBuildTelemetryTest pattern).
     */
    #[Test]
    public function the_migration_declares_the_width_mysql_will_enforce(): void
    {
        $migration = require database_path('migrations/2026_09_24_210000_add_external_ref_to_form_responses.php');

        $connection = DB::connection();
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, 'form_responses');

        Schema::shouldReceive('table')
            ->once()
            ->with('form_responses', Mockery::type(\Closure::class))
            ->andReturnUsing(fn (string $table, \Closure $callback) => $callback($blueprint));

        $migration->up();

        $columns = collect($blueprint->getAddedColumns())->keyBy('name');

        $this->assertSame(['external_ref', 'external_synced_at'], $columns->keys()->all());
        $this->assertSame('string', $columns['external_ref']->type);
        $this->assertSame(64, $columns['external_ref']->length);
        $this->assertTrue($columns['external_ref']->nullable);
        $this->assertSame('dateTime', $columns['external_synced_at']->type);
        $this->assertTrue($columns['external_synced_at']->nullable);

        $unique = collect($blueprint->getCommands())->firstWhere('name', 'unique');
        $this->assertNotNull($unique);
        $this->assertSame('form_resp_form_external_ref_unique', $unique->index);
        $this->assertSame(['form_id', 'external_ref'], $unique->columns);
        $this->assertNull(collect($blueprint->getCommands())->firstWhere('name', 'foreign'), 'no ->constrained(): it would rebuild the table on SQLite');
    }

    // ----------------------------------------------------------- scheduling

    #[Test]
    public function it_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        // Loading the console kernel is what reads routes/console.php.
        Artisan::call('list', ['--raw' => true]);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'alrazi:sync-website'));

        $this->assertNotNull($event, 'alrazi:sync-website is not on the schedule');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    #[Test]
    public function a_dry_run_reads_but_writes_and_fetches_nothing(): void
    {
        $this->assertSame(0, $this->sync(['--dry-run' => true]));

        $this->assertStringContainsString('created=2', Artisan::output());
        $this->assertSame(0, FormResponse::query()->count());
        $this->assertSame(0, $this->registrationForm()->response_count);
        $this->assertSame([], $this->signRequests());
        $this->assertCount(0, $this->allFilesOnDisk());
    }
}
