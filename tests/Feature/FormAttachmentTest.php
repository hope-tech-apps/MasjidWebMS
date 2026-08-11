<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormResponseAttachment;
use App\Models\Masjid;
use App\Models\User;
use App\Rules\ValidFormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * File uploads on sign-up forms (T-004) — the Manara Schools careers form's résumé.
 *
 * Two things are being defended here, and they pull in opposite directions:
 *
 *  - A résumé arrives through the PUBLIC, unauthenticated submit endpoint, so the
 *    type and size ceiling have to hold against a caller who is not a user of this
 *    system at all.
 *  - Once stored it is some of the most sensitive PII the platform holds — a name,
 *    an address and an employment history in one document. It must not be readable
 *    by guessing a URL, and it must not be readable by another masjid's admin.
 *
 * The acceptance bar for the whole feature is that a form WITHOUT a file field
 * submits exactly as it did before, which `a_form_without_file_fields_submits_
 * exactly_as_before` pins.
 */
class FormAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private Form $careers;

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

        // Fake the disk the attachments config points at, so nothing in this test
        // writes into storage/app/private for real.
        Storage::fake($this->disk());

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
        $this->careers = $this->makeCareersForm($this->masjidA);
    }

    private function disk(): string
    {
        return (string) config('forms.attachments.disk', 'local');
    }

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

    /** A careers form: name, email, and a résumé the school actually needs. */
    private function makeCareersForm(Masjid $masjid, bool $resumeRequired = true): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'careers-' . uniqid(),
            'name' => 'Teaching Position',
            'schema' => [
                'sections' => [
                    [
                        'id' => 'applicant',
                        'title' => 'About you',
                        'fields' => [
                            ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                            ['name' => 'resume', 'label' => 'Résumé', 'type' => 'file', 'required' => $resumeRequired],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
            ],
        ]);
    }

    /** The pre-T-004 shape: no file field anywhere. */
    private function makePlainForm(Masjid $masjid): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'rsvp-' . uniqid(),
            'name' => 'Iftar RSVP',
            'schema' => [
                'sections' => [
                    [
                        'id' => 'registrant',
                        'title' => 'Your Information',
                        'fields' => [
                            ['name' => 'registrantName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                            ['name' => 'registrantEmail', 'label' => 'Email', 'type' => 'email', 'required' => true],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => ['name' => 'registrantName', 'email' => 'registrantEmail'],
            ],
        ]);
    }

    private function pdf(string $name = 'resume.pdf', int $kilobytes = 120): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    /**
     * A multipart submission: scalar answers under `data`, uploads under `files`.
     * Deliberately NOT postJson — a real browser sends this as form-data.
     */
    private function submit(Form $form, array $payload, ?int $masjidId)
    {
        $headers = $masjidId === null ? [] : ['masjid-id' => (string) $masjidId];

        return $this->post("/api/v1/forms/{$form->id}/responses", $payload, $headers);
    }

    private function applicant(array $files = []): array
    {
        $payload = [
            'data' => [
                'fullName' => 'Amal Yusuf',
                'email' => 'amal@example.com',
            ],
        ];

        if ($files !== []) {
            $payload['files'] = $files;
        }

        return $payload;
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    /** A MasjidAdmin owning $masjid — how ResolveMasjidTenant resolves a real request. */
    private function actingAsAdminOf(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function attachmentUrl(FormResponseAttachment $attachment, ?int $masjidId = null): string
    {
        $response = $attachment->response;
        $masjidId = $masjidId ?? $response->masjid_id;

        return "/api/admin/masjids/{$masjidId}/forms/{$response->form_id}"
            . "/responses/{$response->id}/attachments/{$attachment->id}";
    }

    // ------------------------------------------------------------------ happy path

    #[Test]
    public function a_submission_with_a_valid_file_is_stored(): void
    {
        $this->submit($this->careers, $this->applicant(['resume' => $this->pdf()]), $this->masjidA->id)
            ->assertOk();

        $this->assertSame(1, FormResponse::count());
        $this->assertSame(1, FormResponseAttachment::count());

        $attachment = FormResponseAttachment::first();
        $stored = FormResponse::first();

        $this->assertSame($stored->id, $attachment->form_response_id);
        // Server-derived, never taken from the payload.
        $this->assertSame($this->masjidA->id, $attachment->masjid_id);
        $this->assertSame('resume', $attachment->field);
        $this->assertSame('resume.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime_type);

        Storage::disk($this->disk())->assertExists($attachment->path);

        // The admin table and the CSV export need a cell to show, so the respondent's
        // filename — and only the filename — goes back into `data`.
        $this->assertSame('resume.pdf', $stored->data['resume']);
    }

    /**
     * The whole point of the feature: a résumé must not be reachable by anyone who
     * guesses a URL. That rests on two things — a disk with no public URL, and a
     * stored name that has nothing to do with the one the applicant chose.
     */
    #[Test]
    public function the_uploaded_file_is_private_and_unguessable(): void
    {
        $this->submit(
            $this->careers,
            $this->applicant(['resume' => $this->pdf('Amal Yusuf CV.pdf')]),
            $this->masjidA->id
        )->assertOk();

        $attachment = FormResponseAttachment::first();

        $this->assertNotSame('public', $attachment->disk);
        // A disk with a `url` is a disk something can be fetched from without auth.
        $this->assertNull(config("filesystems.disks.{$attachment->disk}.url"));

        $this->assertStringNotContainsString('Amal Yusuf CV', $attachment->path);
        // The stored name is random alphanumerics, so nothing the applicant typed —
        // spaces included — survives into the path.
        $this->assertStringNotContainsString(' ', $attachment->path);
        // Scoped to the tenant that collected it, so nothing is ever co-mingled.
        $this->assertStringContainsString("/{$this->masjidA->id}/", $attachment->path);
    }

    #[Test]
    public function an_optional_file_field_submits_without_one(): void
    {
        $form = $this->makeCareersForm($this->masjidA, resumeRequired: false);

        $this->submit($form, $this->applicant(), $this->masjidA->id)->assertOk();

        $this->assertSame(1, FormResponse::count());
        $this->assertSame(0, FormResponseAttachment::count());
    }

    /**
     * `only()` drops undeclared answers; the uploads bag is filtered the same way, so
     * a caller cannot make the server write a file for a question that does not exist.
     */
    #[Test]
    public function an_upload_for_an_undeclared_field_is_ignored(): void
    {
        $this->submit($this->careers, $this->applicant([
            'resume' => $this->pdf(),
            'payload' => $this->pdf('extra.pdf'),
        ]), $this->masjidA->id)->assertOk();

        $this->assertSame(1, FormResponseAttachment::count());
        $this->assertSame('resume', FormResponseAttachment::first()->field);
        $this->assertCount(1, Storage::disk($this->disk())->allFiles());
    }

    // ----------------------------------------------------------------- validation

    #[Test]
    public function a_disallowed_file_type_is_rejected(): void
    {
        $response = $this->submit($this->careers, $this->applicant([
            'resume' => UploadedFile::fake()->create('shell.php', 4, 'application/x-httpd-php'),
        ]), $this->masjidA->id);

        $response->assertStatus(422);
        $this->assertSame('failed', $response->json('status'));

        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, FormResponseAttachment::count());
        $this->assertEmpty(Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function an_oversized_file_is_rejected(): void
    {
        $maxKb = (int) config('forms.attachments.max_size_kb');

        $response = $this->submit($this->careers, $this->applicant([
            'resume' => $this->pdf('huge.pdf', $maxKb + 1),
        ]), $this->masjidA->id);

        $response->assertStatus(422);
        $this->assertSame('failed', $response->json('status'));

        $this->assertSame(0, FormResponse::count());
        $this->assertEmpty(Storage::disk($this->disk())->allFiles());
    }

    /** A required upload reports under its own field name, with every other error. */
    #[Test]
    public function a_missing_required_file_is_rejected(): void
    {
        $response = $this->submit($this->careers, $this->applicant(), $this->masjidA->id);

        $response->assertStatus(422);
        $this->assertSame('failed', $response->json('status'));
        $this->assertArrayHasKey('resume', $response->json('data'));

        $this->assertSame(0, FormResponse::count());
    }

    /**
     * One upload per row — a résumé per attendee — has no shape in the submitted
     * payload and no cell in the responses table, so it is refused when the form is
     * SAVED rather than discovered when somebody submits one.
     */
    #[Test]
    public function a_file_field_cannot_live_inside_a_repeatable_section(): void
    {
        $failures = [];

        (new ValidFormSchema)->validate('schema', [
            'sections' => [
                [
                    'id' => 'attendees',
                    'title' => 'Attendees',
                    'repeatable' => true,
                    'fields' => [
                        ['name' => 'fullName', 'label' => 'Name', 'type' => 'text'],
                        ['name' => 'photo', 'label' => 'Photo', 'type' => 'file'],
                    ],
                ],
            ],
        ], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('repeatable', $failures[0]);
    }

    // ----------------------------------------------------------- behaviour-neutral

    /**
     * The acceptance bar. Every form that existed before this feature has no file
     * field, sends no `files` bag, and must be completely unaffected by it.
     */
    #[Test]
    public function a_form_without_file_fields_submits_exactly_as_before(): void
    {
        $form = $this->makePlainForm($this->masjidA);

        $response = $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => [
                'registrantName' => 'Bilal Khan',
                'registrantEmail' => 'bilal@example.com',
            ],
        ], ['masjid-id' => (string) $this->masjidA->id]);

        $response->assertOk();

        $stored = FormResponse::first();

        $this->assertSame('Bilal Khan', $stored->data['registrantName']);
        $this->assertSame('Bilal Khan', $stored->respondent_name);
        $this->assertSame('bilal@example.com', $stored->respondent_email);
        $this->assertSame(1, $stored->entry_count);
        $this->assertSame(1, $form->fresh()->response_count);

        $this->assertSame(0, FormResponseAttachment::count());
        $this->assertEmpty(Storage::disk($this->disk())->allFiles());
    }

    // ---------------------------------------------------------------------- admin

    #[Test]
    public function an_admin_can_see_and_download_an_attachment(): void
    {
        $this->submit($this->careers, $this->applicant(['resume' => $this->pdf()]), $this->masjidA->id)
            ->assertOk();

        $attachment = FormResponseAttachment::first();
        $stored = FormResponse::first();

        $this->actingAsSuperAdmin();

        $detail = $this->getJson(
            "/api/admin/masjids/{$this->masjidA->id}/forms/{$this->careers->id}/responses/{$stored->id}"
        )->assertOk();

        $this->assertCount(1, $detail->json('data.attachments'));
        $this->assertSame('resume.pdf', $detail->json('data.attachments.0.file_name'));
        $this->assertStringContainsString(
            "/responses/{$stored->id}/attachments/{$attachment->id}",
            $detail->json('data.attachments.0.download_url')
        );

        $download = $this->get($this->attachmentUrl($attachment));

        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('resume.pdf', $download->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
    }

    /** The list stays light — attachments ride with the detail, like `data` does. */
    #[Test]
    public function the_response_list_does_not_carry_attachments(): void
    {
        $this->submit($this->careers, $this->applicant(['resume' => $this->pdf()]), $this->masjidA->id)
            ->assertOk();

        $this->actingAsSuperAdmin();

        $list = $this->getJson(
            "/api/admin/masjids/{$this->masjidA->id}/forms/{$this->careers->id}/responses"
        )->assertOk();

        $this->assertArrayNotHasKey('attachments', $list->json('data.data.0'));
    }

    // ------------------------------------------------------------------ isolation

    /**
     * MySQL has no row-level security, so this chain — masjid → form → response →
     * attachment — is the entire guarantee. Masjid B's id in the path with masjid A's
     * attachment must not resolve, for a SuperAdmin as much as anyone.
     */
    #[Test]
    public function another_masjids_attachment_does_not_resolve(): void
    {
        $this->submit($this->careers, $this->applicant(['resume' => $this->pdf()]), $this->masjidA->id)
            ->assertOk();

        $attachment = FormResponseAttachment::first();

        $this->actingAsSuperAdmin();

        $this->get($this->attachmentUrl($attachment, $this->masjidB->id))
            ->assertStatus(404);
    }

    /** And a MasjidAdmin cannot even address another masjid's path. */
    #[Test]
    public function a_masjid_admin_cannot_download_another_masjids_attachment(): void
    {
        $this->submit($this->careers, $this->applicant(['resume' => $this->pdf()]), $this->masjidA->id)
            ->assertOk();

        $attachment = FormResponseAttachment::first();

        $this->actingAsAdminOf($this->masjidB);

        $this->get($this->attachmentUrl($attachment))->assertStatus(403);

        // Nor by naming their own masjid and hoping the ids elsewhere are enough.
        $this->get($this->attachmentUrl($attachment, $this->masjidB->id))->assertStatus(404);
    }

    #[Test]
    public function an_unauthenticated_caller_cannot_download_an_attachment(): void
    {
        $this->submit($this->careers, $this->applicant(['resume' => $this->pdf()]), $this->masjidA->id)
            ->assertOk();

        $this->getJson($this->attachmentUrl(FormResponseAttachment::first()))
            ->assertStatus(401);
    }

    // --------------------------------------------------------------- housekeeping

    #[Test]
    public function deleting_a_response_removes_its_attachment_and_the_file(): void
    {
        $this->submit($this->careers, $this->applicant(['resume' => $this->pdf()]), $this->masjidA->id)
            ->assertOk();

        $attachment = FormResponseAttachment::first();
        $path = $attachment->path;

        $this->actingAsSuperAdmin();

        $this->deleteJson(
            "/api/admin/masjids/{$this->masjidA->id}/forms/{$this->careers->id}/responses/{$attachment->form_response_id}"
        )->assertOk();

        $this->assertSame(0, FormResponseAttachment::count());
        Storage::disk($this->disk())->assertMissing($path);
    }
}
