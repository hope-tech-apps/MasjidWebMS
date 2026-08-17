<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /**
     * Everything the camp form actually relies on, pinned.
     *
     * ## M-F8 — why the clock is frozen
     *
     * The fee assertion below reads `feeRule()`, which resolves the tier IN
     * FORCE TODAY. `tiers[0].until` is `2026-08-14`, that date has passed, and
     * this test therefore began failing on 2026-08-15 and would fail forever
     * after: `Failed asserting that 120.0 is identical to 100.0`. It was the
     * whole of the suite's red — "1 failed, 1 skipped, 1876 passed" — so "is the
     * suite green" stopped being a question anybody could answer, and every
     * later reviewer had to be told in advance which failure to ignore. A
     * baseline with a permanent known failure hides the next real one.
     *
     * The fixture is CORRECT; the test was wall-clock dependent. Bumping the
     * date would buy a few weeks and rot again — and it would also be a lie,
     * since the file is the committee's real 2026 camp. Freezing the clock
     * inside the early-bird window asserts what the assertion always meant:
     * "during early bird, this form charges $100 per attendee."
     *
     * The tier SCHEDULE itself — that the price steps up on the 15th, and again
     * after the 3rd — is pinned by TieredFeeTest and TieredFeeTimezoneTest,
     * which own boundary behaviour and freeze the clock on both sides of each
     * boundary. This test owns the file's SHAPE.
     */
    #[Test]
    public function the_camp_form_keeps_the_shape_the_renderer_depends_on(): void
    {
        // Inside the early-bird window the fixture actually declares.
        $this->travelTo(Carbon::parse('2026-08-01 12:00:00', 'UTC'));

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

        // $100 per attendee, at the frozen instant above.
        $fee = $form->feeRule();
        $this->assertSame(100.0, $fee['amount']);
        $this->assertSame('Early bird', $fee['currentTier']['label']);
        $this->assertSame('attendees', $fee['perEntryOfSection']);

        // The whole schedule ships with the file, so the shape assertion covers
        // the prices that are NOT in force today as well — the half a frozen
        // clock could otherwise stop watching.
        $this->assertSame(
            [['Early bird', 100, '2026-08-14'], ['Standard', 120, '2026-09-03'], ['Day of camp', 140, null]],
            collect($fee['tiers'])
                ->map(fn ($t) => [$t['label'], $t['amount'], $t['until'] ?? null])
                ->all()
        );

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

    // =====================================================================
    // M-F6 — the importer is not a back door around the FEE rules either
    // =====================================================================
    //
    // The command's docblock claimed a file "cannot introduce a form the builder
    // would have rejected". True of `schema`; measurably false of `settings`,
    // which is where the money is. Measured on the identical payload:
    //
    //     form:import                    until '2026-8-14' -> exit 0, ACCEPTED
    //     POST /api/admin/…/forms        until '2026-8-14' -> 422 "…format Y-m-d"
    //     POST /api/admin/…/forms        until '2026-08-14' -> 201
    //
    // and the accepted file then priced four months of registrations at the
    // expired early-bird rate. Both doors now apply StoreFormRequest's own
    // settings rules.

    /**
     * The exact file that got in. An unpadded cut-off is the shape a human
     * types, and it is the shape that breaks the tier comparison.
     */
    #[Test]
    public function a_fee_tier_with_an_unpadded_cutoff_is_refused(): void
    {
        $this->assertImportRefuses('unpadded-until', [
            'fee' => [
                'currency' => 'USD',
                'tiers' => [
                    ['label' => 'Early bird', 'amount' => 100, 'until' => '2026-8-14'],
                    ['label' => 'Standard', 'amount' => 120],
                ],
            ],
        ]);
    }

    /** …and so is anything else that is not a calendar date. */
    #[Test]
    public function a_fee_tier_with_an_unreadable_cutoff_is_refused(): void
    {
        $this->assertImportRefuses('prose-until', [
            'fee' => ['tiers' => [['label' => 'Early bird', 'amount' => 100, 'until' => 'next friday']]],
        ]);
    }

    /** A tier with no amount is a tier that prices nothing. */
    #[Test]
    public function a_fee_tier_with_no_amount_is_refused(): void
    {
        $this->assertImportRefuses('amountless-tier', [
            'fee' => ['tiers' => [['label' => 'Early bird', 'until' => '2026-08-14']]],
        ]);
    }

    /** A negative price would credit the payer; the builder refuses it and so does this. */
    #[Test]
    public function a_negative_fee_is_refused(): void
    {
        $this->assertImportRefuses('negative-fee', [
            'fee' => ['amount' => -50, 'currency' => 'USD'],
        ]);
    }

    /** The rule that is not about money, to show the whole ruleset arrived. */
    #[Test]
    public function a_malformed_notification_address_is_refused(): void
    {
        $this->assertImportRefuses('bad-notify', [
            'notifyEmails' => ['not-an-address'],
        ]);
    }

    /** The padded form of the same file must still import — this is a gate, not a wall. */
    #[Test]
    public function a_padded_cutoff_still_imports(): void
    {
        $path = $this->writeFixture('padded-until', [
            'fee' => [
                'currency' => 'USD',
                'tiers' => [
                    ['label' => 'Early bird', 'amount' => 100, 'until' => '2026-08-14'],
                    ['label' => 'Standard', 'amount' => 120],
                ],
            ],
        ]);

        try {
            $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => $path])
                ->assertExitCode(0);

            $this->assertSame(1, Form::count());
        } finally {
            @unlink(base_path($path));
        }
    }

    /**
     * EVERY shipped definition, through the tightened rules — the other half of
     * the gate, and the one that says what it does NOT refuse.
     *
     * Widening a validator is a refusal, and a refusal has to be measured
     * against the legitimate files it now turns away. These are the real ones a
     * committee hands over; if any of them ever stops satisfying the builder's
     * own settings rules that is a build-time failure, not a discovery made the
     * week registration opens. Directory-driven so a fixture added later is
     * covered without anyone remembering to add it here.
     */
    #[Test]
    public function every_shipped_form_definition_satisfies_the_builders_settings_rules(): void
    {
        // `__test_` fixtures are written into this directory by the refusal
        // tests above and removed in their `finally`; excluded so a hard crash
        // in one of those cannot resurface here as a confusing second failure.
        $files = array_values(array_filter(
            glob(base_path('database/forms/*.json')),
            fn ($path) => ! str_starts_with(basename($path), '__test_')
        ));

        $this->assertNotEmpty($files, 'No shipped form definitions found — this test would pass vacuously.');

        foreach ($files as $file) {
            $relative = 'database/forms/' . basename($file);

            $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => $relative, '--dry-run' => true])
                ->assertExitCode(0, "{$relative} no longer validates");
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

    // ------------------------------------------------------------------ helpers

    /**
     * A minimal, otherwise-valid form definition carrying the settings under
     * test — so a refusal can only be about `settings`.
     *
     * @return string the path, relative to the app root
     */
    private function writeFixture(string $slug, array $settings): string
    {
        $relative = "database/forms/__test_{$slug}.json";

        file_put_contents(base_path($relative), json_encode([
            'slug' => $slug,
            'name' => 'Fixture ' . $slug,
            'settings' => $settings,
            'schema' => [
                'sections' => [[
                    'id' => 'one',
                    'title' => 'One',
                    'fields' => [
                        ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ],
                ]],
            ],
        ]));

        return $relative;
    }

    private function assertImportRefuses(string $slug, array $settings): void
    {
        $relative = $this->writeFixture($slug, $settings);

        try {
            $this->artisan('form:import', ['masjid' => $this->masjid->id, 'path' => $relative])
                ->assertExitCode(1);

            $this->assertSame(0, Form::count(), 'A refused import must write nothing.');
        } finally {
            @unlink(base_path($relative));
        }
    }
}
