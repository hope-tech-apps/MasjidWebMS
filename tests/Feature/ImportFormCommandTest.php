<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The form importer, and — more valuably — proof that the REAL Camp 2026 definition in
 * database/forms/camp-2026.json is valid against the same rule the admin builder uses.
 *
 * That file is the committee's actual camp registration, with a repeatable attendee list,
 * a per-person fee, consent bodies and the guardian-if-a-minor rule. If it ever stops
 * validating, this fails at build time rather than when someone tries to import it the
 * week registration opens.
 */
class ImportFormCommandTest extends TestCase
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

        $this->masjid = Masjid::create([
            'name' => 'Burlington Masjid (test)',
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private const CAMP = 'database/forms/camp-2026.json';

    #[Test]
    public function the_real_camp_2026_definition_imports(): void
    {
        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => self::CAMP])
            ->assertExitCode(0);

        $form = Form::where('masjid_id', $this->masjid->id)->where('slug', 'camp-2026')->first();

        $this->assertNotNull($form, 'The camp form should exist after import.');
        $this->assertSame('Burlington Masjid Camp 2026', $form->name);
    }

    /** Everything the camp form actually relies on, pinned. */
    #[Test]
    public function the_camp_form_keeps_the_shape_the_renderer_depends_on(): void
    {
        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => self::CAMP])
            ->assertExitCode(0);

        $form = Form::where('slug', 'camp-2026')->firstOrFail();

        $sectionIds = collect($form->sections())->pluck('id')->all();
        $this->assertSame(
            ['registrant', 'attendees', 'emergency', 'payment', 'consents', 'extras'],
            $sectionIds
        );

        // Exactly one repeatable section — FormSchema's entry counting and the fee total
        // both assume at most one.
        $repeatable = $form->repeatableSection();
        $this->assertNotNull($repeatable);
        $this->assertSame('attendees', $repeatable['id']);
        $this->assertSame(1, $repeatable['minEntries']);
        $this->assertSame(12, $repeatable['maxEntries']);

        // $100 per attendee.
        $fee = $form->feeRule();
        $this->assertSame(100.0, $fee['amount']);
        $this->assertSame('attendees', $fee['perEntryOfSection']);

        // The identity map drives the searchable columns on the responses list.
        // The name is composite: the form asks for first and last separately, and the
        // responses list must still show and search a whole name.
        $this->assertSame(
            [
                'name' => ['registrantFirstName', 'registrantLastName'],
                'email' => 'registrantEmail',
                'phone' => 'registrantPhone',
            ],
            $form->identityMap()
        );
    }

    /** The guardian rule is the whole reason conditional requirements exist. */
    #[Test]
    public function the_camp_form_requires_a_guardian_when_an_attendee_is_a_minor(): void
    {
        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => self::CAMP])
            ->assertExitCode(0);

        $form = Form::where('slug', 'camp-2026')->firstOrFail();

        $guardian = collect($form->sections())
            ->firstWhere('id', 'consents')['fields'];

        $rule = collect($guardian)->firstWhere('name', 'guardianName')['requiredIf'];

        $this->assertSame('anyEntryUnder', $rule['rule']);
        $this->assertSame('attendees', $rule['section']);
        $this->assertSame('age', $rule['field']);
        $this->assertSame(18, $rule['value']);
    }

    #[Test]
    public function importing_twice_updates_rather_than_duplicating(): void
    {
        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => self::CAMP])->assertExitCode(0);
        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => self::CAMP])->assertExitCode(0);

        $this->assertSame(1, Form::where('slug', 'camp-2026')->count());
    }

    /** Re-importing must never cost anyone their registrations. */
    #[Test]
    public function re_importing_leaves_existing_responses_intact(): void
    {
        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => self::CAMP])->assertExitCode(0);

        $form = Form::where('slug', 'camp-2026')->firstOrFail();

        FormResponse::create([
            'form_id' => $form->id,
            'masjid_id' => $this->masjid->id,
            'data' => ['registrantFirstName' => 'Amal', 'registrantLastName' => 'Yusuf'],
            'respondent_name' => 'Amal Yusuf',
            'submitted_at' => now(),
        ]);

        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => self::CAMP])->assertExitCode(0);

        $this->assertSame(1, FormResponse::where('form_id', $form->id)->count());
        $this->assertSame(1, $form->fresh()->response_count);
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->artisan('form:import', [
            'masjid' => $this->masjid->id,
            'path' => self::CAMP,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, Form::count());
    }

    #[Test]
    public function an_unknown_masjid_is_refused(): void
    {
        $this->artisan('form:import', ['masjid' => 999999, 'path' => self::CAMP])
            ->assertExitCode(1);
    }

    #[Test]
    public function a_missing_file_is_refused(): void
    {
        $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => 'database/forms/nope.json'])
            ->assertExitCode(1);
    }

    /** The importer must not be a back door around the builder's schema rules. */
    #[Test]
    public function a_schema_the_builder_would_reject_is_refused(): void
    {
        $path = base_path('database/forms/__test_invalid.json');

        file_put_contents($path, json_encode([
            'slug' => 'bad-form',
            'name' => 'Bad Form',
            'schema' => [
                'sections' => [
                    [
                        'id' => 'one',
                        'title' => 'One',
                        'fields' => [
                            // A choice field with no options — the renderer could not draw it.
                            ['name' => 'pick', 'label' => 'Pick', 'type' => 'select', 'required' => true],
                        ],
                    ],
                ],
            ],
        ]));

        try {
            $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => 'database/forms/__test_invalid.json'])
                ->assertExitCode(1);

            $this->assertSame(0, Form::count());
        } finally {
            @unlink($path);
        }
    }

    /** A typo in the identity map produces an unsearchable list; catch it at import. */
    #[Test]
    public function an_identity_map_pointing_at_a_missing_question_is_refused(): void
    {
        $path = base_path('database/forms/__test_identity.json');

        file_put_contents($path, json_encode([
            'slug' => 'identity-typo',
            'name' => 'Identity Typo',
            'settings' => ['identity' => ['email' => 'emailAddress']],
            'schema' => [
                'sections' => [
                    [
                        'id' => 'one',
                        'title' => 'One',
                        'fields' => [
                            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                        ],
                    ],
                ],
            ],
        ]));

        try {
            $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => 'database/forms/__test_identity.json'])
                ->assertExitCode(1);

            $this->assertSame(0, Form::count());
        } finally {
            @unlink($path);
        }
    }
}
