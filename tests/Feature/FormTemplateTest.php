<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormResponseAttachment;
use App\Models\Masjid;
use App\Models\MobileAppFeature;
use App\Models\User;
use App\Rules\ValidFormSchema;
use App\Support\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vertical form templates (PLAN T-011) — Manara Schools' real workflows.
 *
 * Provisioning a SCHOOL tenant seeds three ready-to-edit forms — Admissions
 * Interest, Careers Application (required résumé `file` field), Withdrawal
 * Request — from config/form_templates.php. Three bars are held here:
 *
 *  - Behaviour-neutrality: a masjid or community tenant seeds ZERO forms, so
 *    provisioning them is unchanged by this feature existing.
 *  - The seeded forms are real: each accepts an actual submission through the
 *    public endpoint, résumé upload included — not just rows that look right.
 *  - Re-applying (form:apply-templates) never clobbers an admin: an edited
 *    form is skipped, a deleted one is not resurrected.
 */
class FormTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_SLUGS = ['admissions-interest', 'careers-application', 'withdrawal-request'];

    /** Full seeded catalog (MobileAppFeaturesSeeder), as ProvisionOrgTypeTest seeds it. */
    private const FEATURE_KEYS = [
        'quran', 'hadith', 'adhkar', 'qibla', 'tasbih', 'donate',
        'about_us', 'gallery', 'services', 'announcements', 'contact_us',
    ];

    private int $cityId;

    private int $countryId;

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

        // The careers résumé lands on the attachments disk — fake it so no test
        // writes into storage/app/private for real.
        Storage::fake((string) config('forms.attachments.disk', 'local'));

        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);

        foreach (self::FEATURE_KEYS as $key) {
            MobileAppFeature::create(['name' => ucfirst($key), 'key' => $key]);
        }
    }

    // ------------------------------------------------------------- the catalog

    #[Test]
    public function every_org_type_has_a_template_list_and_no_stranger_does(): void
    {
        $catalog = config('form_templates');

        // Same keys, no extras: a template list for an org_type that cannot be
        // provisioned would be dead config, and a missing key would make a new
        // vertical's behaviour depend on a config default rather than a decision.
        $this->assertEqualsCanonicalizing(Masjid::ORG_TYPES, array_keys($catalog));

        $this->assertSame([], $catalog['masjid'], 'the masjid vertical must define no form templates');
        $this->assertSame([], $catalog['community'], 'the community vertical must define no form templates');
        $this->assertSame(self::SCHOOL_SLUGS, array_column($catalog['school'], 'slug'));
    }

    /**
     * Every template must be a form the builder itself would have accepted:
     * the schema passes ValidFormSchema, the slug matches the admin API's
     * pattern, and the identity map points at questions that exist — the same
     * cross-checks StoreFormRequest and form:import run.
     */
    #[Test]
    public function every_template_passes_the_schema_rule_and_its_identity_map_resolves(): void
    {
        foreach (Masjid::ORG_TYPES as $orgType) {
            foreach (FormTemplates::forOrgType($orgType) as $template) {
                $label = "{$orgType}/{$template['slug']}";

                $this->assertMatchesRegularExpression(
                    '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    $template['slug'],
                    "{$label}: slug must match the admin API's pattern"
                );
                $this->assertNotSame('', trim($template['name'] ?? ''), "{$label}: needs a name");

                $failures = [];
                (new ValidFormSchema)->validate('schema', $template['schema'], function ($message) use (&$failures) {
                    $failures[] = $message;
                });
                $this->assertSame([], $failures, "{$label}: schema rejected: " . implode(' | ', $failures));

                $flat = [];
                foreach ($template['schema']['sections'] as $section) {
                    if (empty($section['repeatable'])) {
                        $flat = array_merge($flat, array_column($section['fields'], 'name'));
                    }
                }

                foreach (['name', 'email', 'phone'] as $slot) {
                    $declared = $template['settings']['identity'][$slot] ?? null;
                    if ($declared === null || $declared === '') {
                        continue;
                    }
                    foreach ((array) $declared as $field) {
                        $this->assertContains(
                            $field,
                            $flat,
                            "{$label}: identity.{$slot} points at \"{$field}\", which is not a question"
                        );
                    }
                }
            }
        }
    }

    // ----------------------------------------------------------- provisioning

    #[Test]
    public function provisioning_a_school_seeds_the_three_school_templates(): void
    {
        $response = $this->provision(['org_type' => 'school']);

        $response->assertCreated();
        $masjidId = $response->json('data.masjid_id');

        $forms = Form::where('masjid_id', $masjidId)->orderBy('id')->get();

        $this->assertSame(self::SCHOOL_SLUGS, $forms->pluck('slug')->all());
        $this->assertSame(
            ['Admissions Interest', 'Careers Application', 'Withdrawal Request'],
            $forms->pluck('name')->all()
        );

        foreach ($forms as $form) {
            // Active-by-default, exactly like an admin-built form; a form only
            // becomes public once placed on a page, so this is safe.
            $this->assertTrue($form->is_active, "{$form->slug} should seed active");
            $this->assertTrue($form->acceptsSubmissions(), "{$form->slug} should accept submissions");
            $this->assertSame(0, $form->response_count);
        }
    }

    #[Test]
    public function provisioning_a_masjid_seeds_no_forms(): void
    {
        // Behaviour-neutrality, the T-011 acceptance bar: the masjid path must
        // be indistinguishable from before templates existed.
        $this->provision()->assertCreated();

        $this->assertSame(0, Form::withTrashed()->count());
    }

    #[Test]
    public function provisioning_a_community_organization_seeds_no_forms(): void
    {
        $this->provision(['org_type' => 'community'])->assertCreated();

        $this->assertSame(0, Form::withTrashed()->count());
    }

    // ---------------------------------------- the seeded forms actually work

    #[Test]
    public function a_seeded_admissions_form_accepts_a_submission(): void
    {
        $masjid = $this->provisionSchool();
        $form = $this->seededForm($masjid, 'admissions-interest');

        $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => [
                'studentFirstName' => 'Maryam',
                'studentLastName' => 'Rahman',
                'studentDateOfBirth' => '2019-04-12',
                'gradeApplyingFor' => 'grade-1',
                'parentName' => 'Huda Rahman',
                'parentEmail' => 'huda@example.com',
                'parentPhone' => '+19055550101',
                'howHeard' => 'masjid',
            ],
        ], ['masjid-id' => (string) $masjid->id])->assertOk();

        $stored = FormResponse::firstOrFail();
        $this->assertSame($masjid->id, $stored->masjid_id);
        $this->assertSame('Huda Rahman', $stored->respondent_name);
        $this->assertSame('huda@example.com', $stored->respondent_email);
        $this->assertSame('grade-1', $stored->data['gradeApplyingFor']);
        $this->assertSame(1, $form->fresh()->response_count);
    }

    #[Test]
    public function a_seeded_admissions_form_rejects_an_invented_grade(): void
    {
        // The select's options made it into the stored schema, so the server
        // enforces them — proof the template produced a REAL schema, not prose.
        $masjid = $this->provisionSchool();
        $form = $this->seededForm($masjid, 'admissions-interest');

        $response = $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => [
                'studentFirstName' => 'Maryam',
                'studentLastName' => 'Rahman',
                'studentDateOfBirth' => '2019-04-12',
                'gradeApplyingFor' => 'grade-13',
                'parentName' => 'Huda Rahman',
                'parentEmail' => 'huda@example.com',
                'parentPhone' => '+19055550101',
            ],
        ], ['masjid-id' => (string) $masjid->id]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('gradeApplyingFor', $response->json('data'));
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_seeded_careers_form_accepts_a_submission_with_a_resume(): void
    {
        $masjid = $this->provisionSchool();
        $form = $this->seededForm($masjid, 'careers-application');

        // Multipart, as a real browser sends it: answers under `data`, the
        // upload under `files`, keyed by the field name.
        $this->post("/api/v1/forms/{$form->id}/responses", [
            'data' => [
                'fullName' => 'Amal Yusuf',
                'email' => 'amal@example.com',
                'phone' => '+19055550102',
                'roleAppliedFor' => 'teacher',
                'coverLetter' => 'I have taught grade school for six years.',
            ],
            'files' => [
                'resume' => UploadedFile::fake()->create('amal-cv.pdf', 200, 'application/pdf'),
            ],
        ], ['masjid-id' => (string) $masjid->id])->assertOk();

        $stored = FormResponse::firstOrFail();
        $attachment = FormResponseAttachment::firstOrFail();

        $this->assertSame('Amal Yusuf', $stored->respondent_name);
        $this->assertSame('resume', $attachment->field);
        $this->assertSame('amal-cv.pdf', $attachment->original_name);
        $this->assertSame($masjid->id, $attachment->masjid_id);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
        // The filename — and only the filename — goes back into `data`.
        $this->assertSame('amal-cv.pdf', $stored->data['resume']);
    }

    #[Test]
    public function a_seeded_careers_form_requires_the_resume(): void
    {
        $masjid = $this->provisionSchool();
        $form = $this->seededForm($masjid, 'careers-application');

        $response = $this->post("/api/v1/forms/{$form->id}/responses", [
            'data' => [
                'fullName' => 'Amal Yusuf',
                'email' => 'amal@example.com',
                'phone' => '+19055550102',
                'roleAppliedFor' => 'teacher',
            ],
        ], ['masjid-id' => (string) $masjid->id]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('resume', $response->json('data'));
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_seeded_withdrawal_form_accepts_a_submission(): void
    {
        $masjid = $this->provisionSchool();
        $form = $this->seededForm($masjid, 'withdrawal-request');

        $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => [
                'studentName' => 'Yusuf Rahman',
                'lastDay' => '2026-06-30',
                'reason' => 'relocation',
                'exitFeedback' => 'More bus routes would have kept us.',
                'parentName' => 'Huda Rahman',
                'parentEmail' => 'huda@example.com',
            ],
        ], ['masjid-id' => (string) $masjid->id])->assertOk();

        $stored = FormResponse::firstOrFail();
        // The identity name is the STUDENT — the responses list reads as a
        // roster of who is leaving, searchable by the parent's email.
        $this->assertSame('Yusuf Rahman', $stored->respondent_name);
        $this->assertSame('huda@example.com', $stored->respondent_email);
        $this->assertSame('relocation', $stored->data['reason']);
    }

    // ------------------------------------------------------- idempotent apply

    #[Test]
    public function reapplying_skips_a_form_the_admin_has_edited(): void
    {
        $masjid = $this->provisionSchool();

        $edited = $this->seededForm($masjid, 'admissions-interest');
        $edited->update(['name' => 'Enrollment 2027 (edited)', 'capacity' => 40]);

        $this->artisan('form:apply-templates', ['masjid' => $masjid->id])
            ->expectsOutputToContain('skipped: admissions-interest')
            ->assertExitCode(0);

        // Nothing duplicated, nothing restated.
        $this->assertSame(3, Form::withTrashed()->where('masjid_id', $masjid->id)->count());
        $fresh = $edited->fresh();
        $this->assertSame('Enrollment 2027 (edited)', $fresh->name);
        $this->assertSame(40, $fresh->capacity);
    }

    #[Test]
    public function reapplying_does_not_resurrect_a_form_the_admin_deleted(): void
    {
        $masjid = $this->provisionSchool();

        $this->seededForm($masjid, 'careers-application')->delete();

        $this->artisan('form:apply-templates', ['masjid' => $masjid->id])
            ->expectsOutputToContain('skipped: careers-application')
            ->assertExitCode(0);

        // Deleting it was a decision; the slug stays with the trashed row.
        $this->assertSame(3, Form::withTrashed()->where('masjid_id', $masjid->id)->count());
        $this->assertSoftDeleted('forms', ['masjid_id' => $masjid->id, 'slug' => 'careers-application']);
    }

    #[Test]
    public function the_command_seeds_templates_for_an_existing_school_tenant(): void
    {
        // The T-012 shape: Al-Razi's tenant exists before templates did.
        $school = $this->bareTenant('school');

        $this->artisan('form:apply-templates', ['masjid' => $school->id])->assertExitCode(0);

        $this->assertSame(
            self::SCHOOL_SLUGS,
            Form::where('masjid_id', $school->id)->orderBy('id')->pluck('slug')->all()
        );

        // Idempotent: a second run creates nothing further.
        $this->artisan('form:apply-templates', ['masjid' => $school->id])->assertExitCode(0);
        $this->assertSame(3, Form::where('masjid_id', $school->id)->count());
    }

    #[Test]
    public function the_command_is_a_noop_for_a_masjid_tenant(): void
    {
        $masjid = $this->bareTenant('masjid');

        $this->artisan('form:apply-templates', ['masjid' => $masjid->id])->assertExitCode(0);

        $this->assertSame(0, Form::withTrashed()->count());
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $school = $this->bareTenant('school');

        $this->artisan('form:apply-templates', ['masjid' => $school->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Form::withTrashed()->count());
    }

    // ---------------------------------------------------------------- helpers

    /** Provision a school through the real wizard endpoint and return the tenant. */
    private function provisionSchool(): Masjid
    {
        $response = $this->provision(['org_type' => 'school']);
        $response->assertCreated();

        return Masjid::findOrFail($response->json('data.masjid_id'));
    }

    private function seededForm(Masjid $masjid, string $slug): Form
    {
        return Form::where('masjid_id', $masjid->id)->where('slug', $slug)->firstOrFail();
    }

    /** A tenant created directly (no wizard), as every pre-T-011 tenant was. */
    private function bareTenant(string $orgType): Masjid
    {
        return Masjid::create([
            'name' => ucfirst($orgType) . ' ' . uniqid(),
            'org_type' => $orgType,
            'email' => 'tenant-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function provision(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->superAdmin());

        return $this->postJson('/api/admin/onboarding/provision', array_merge([
            'name' => 'Test Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+15551234567',
            'address' => '1 Test St',
            'latitude' => 43.32,
            'longitude' => -79.79,
            'timezone' => 'America/Toronto',
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
            'platforms' => ['web'],
        ], $overrides));
    }
}
