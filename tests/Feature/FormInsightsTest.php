<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manara Insights.
 *
 * Two things matter here and they are tested hardest: that the entitlement gate is
 * enforced by the SERVER (the SPA's router guard deliberately fails open while the masjid
 * is loading, so the client gate is cosmetic), and that PII cannot leak into the payload.
 *
 * The PII test is the important one. A camp form carries children's names, allergies and
 * medications; an analytics panel that quietly included a free-text medical note would be
 * a serious breach with no visible symptom.
 */
class FormInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Form $form;

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
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'assistant_enabled' => true,
        ]);

        $this->form = Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'camp',
            'name' => 'Camp Registration',
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
                    [
                        'id' => 'attendees',
                        'title' => 'Attendees',
                        'repeatable' => true,
                        'minEntries' => 1,
                        'fields' => [
                            ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                            ['name' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => true],
                            [
                                'name' => 'group', 'label' => 'Program track', 'type' => 'select', 'required' => true,
                                'options' => [
                                    ['value' => 'brothers', 'label' => 'Brothers'],
                                    ['value' => 'sisters', 'label' => 'Sisters'],
                                ],
                            ],
                            ['name' => 'allergies', 'label' => 'Allergies', 'type' => 'textarea', 'required' => false],
                            ['name' => 'medications', 'label' => 'Medications', 'type' => 'textarea', 'required' => false],
                        ],
                    ],
                ],
            ],
            'settings' => ['identity' => ['name' => 'registrantName', 'email' => 'registrantEmail']],
        ]);

        $this->seedResponses();
    }

    private function seedResponses(): void
    {
        $people = [
            ['Amal Yusuf', 'amal@example.com', [['Amal Yusuf', 34, 'sisters'], ['Yusuf Jr', 11, 'brothers']]],
            ['Bilal Khan', 'bilal@example.com', [['Bilal Khan', 41, 'brothers']]],
            ['Zaynab Ali', 'zaynab@example.com', [['Zaynab Ali', 29, 'sisters'], ['Hana Ali', 15, 'sisters']]],
            ['Omar Farouk', 'omar@example.com', [['Omar Farouk', 52, 'brothers']]],
        ];

        foreach ($people as $i => [$name, $email, $attendees]) {
            FormResponse::create([
                'form_id' => $this->form->id,
                'masjid_id' => $this->masjid->id,
                'data' => [
                    'registrantName' => $name,
                    'registrantEmail' => $email,
                    'attendees' => collect($attendees)->map(fn ($a) => [
                        'fullName' => $a[0],
                        'age' => $a[1],
                        'group' => $a[2],
                        // The sensitive free text this feature must never surface.
                        'allergies' => 'SECRET_ALLERGY_PEANUTS',
                        'medications' => 'SECRET_MEDICATION_INSULIN',
                    ])->all(),
                ],
                'respondent_name' => $name,
                'respondent_email' => $email,
                'entry_count' => count($attendees),
                'amount_due' => count($attendees) * 100,
                'status' => $i === 1 ? 'confirmed' : 'new',
                'submitted_at' => now()->subDays(4 - $i),
            ]);
        }
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    private function url(): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/insights";
    }

    // ------------------------------------------------------------------- the gate

    #[Test]
    public function insights_are_refused_when_the_masjid_has_no_assistant_entitlement(): void
    {
        $this->masjid->update(['assistant_enabled' => false]);
        $this->actingAsAdmin();

        $response = $this->getJson($this->url());

        $this->assertNotEquals(200, $response->status(), 'The server must refuse, not rely on the SPA hiding the panel.');
    }

    #[Test]
    public function insights_are_available_when_the_entitlement_is_on(): void
    {
        $this->actingAsAdmin();

        $this->getJson($this->url())->assertOk();
    }

    // -------------------------------------------------------------------- privacy

    /**
     * The single most important assertion in this file.
     */
    #[Test]
    public function no_personal_data_appears_anywhere_in_the_payload(): void
    {
        $this->actingAsAdmin();

        $body = $this->getJson($this->url())->assertOk()->getContent();

        foreach ([
            'Amal Yusuf', 'Bilal Khan', 'Zaynab Ali', 'Omar Farouk', 'Yusuf Jr', 'Hana Ali',
            'amal@example.com', 'bilal@example.com',
            'SECRET_ALLERGY_PEANUTS', 'SECRET_MEDICATION_INSULIN',
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                "Insights leaked \"{$secret}\" — free-text and identity answers must never be summarised."
            );
        }
    }

    #[Test]
    public function free_text_fields_are_not_summarised_at_all(): void
    {
        $this->actingAsAdmin();

        $fields = collect($this->getJson($this->url())->json('data.breakdowns'))->pluck('field')->all();

        $this->assertNotContains('allergies', $fields);
        $this->assertNotContains('medications', $fields);
        $this->assertNotContains('registrantName', $fields);
        $this->assertNotContains('registrantEmail', $fields);
    }

    // --------------------------------------------------------------------- totals

    #[Test]
    public function totals_distinguish_submissions_from_people(): void
    {
        $this->actingAsAdmin();

        $totals = $this->getJson($this->url())->json('data.totals');

        $this->assertSame(4, $totals['responses']);
        $this->assertSame(6, $totals['entries'], 'Six people across four submissions.');
        $this->assertSame(600.0, (float) $totals['amount_due_total']);
    }

    #[Test]
    public function outstanding_money_excludes_confirmed_responses(): void
    {
        $this->actingAsAdmin();

        $totals = $this->getJson($this->url())->json('data.totals');

        // Bilal is confirmed (1 attendee, $100), so $500 is still outstanding.
        $this->assertSame(500.0, (float) $totals['amount_due_outstanding']);
    }

    #[Test]
    public function choice_fields_are_broken_down_by_option(): void
    {
        $this->actingAsAdmin();

        $group = collect($this->getJson($this->url())->json('data.breakdowns'))
            ->firstWhere('field', 'group');

        $this->assertNotNull($group);

        $counts = collect($group['options'])->pluck('count', 'value');

        $this->assertSame(3, $counts['brothers']);
        $this->assertSame(3, $counts['sisters']);
    }

    /** Ages are bucketed rather than listed, so a number cannot identify a child. */
    #[Test]
    public function numeric_fields_are_bucketed_not_listed(): void
    {
        $this->actingAsAdmin();

        $age = collect($this->getJson($this->url())->json('data.breakdowns'))
            ->firstWhere('field', 'age');

        $this->assertNotNull($age);
        $this->assertArrayHasKey('buckets', $age);
        $this->assertArrayNotHasKey('values', $age);

        $buckets = collect($age['buckets'])->pluck('count', 'label');

        // The six attendees are 34, 11, 41, 29, 15, 52.
        $this->assertSame(1, $buckets['Under 13']);  // 11
        $this->assertSame(1, $buckets['13–17']);     // 15
        $this->assertSame(2, $buckets['18–34']);     // 34, 29
        $this->assertSame(2, $buckets['35–54']);     // 41, 52
        $this->assertSame(0, $buckets['55+']);

        // Every attendee lands in exactly one bucket.
        $this->assertSame(6, collect($age['buckets'])->sum('count'));
        $this->assertSame(6, $age['answered']);
    }

    #[Test]
    public function the_filters_from_the_responses_list_apply_to_insights_too(): void
    {
        $this->actingAsAdmin();

        $totals = $this->getJson($this->url() . '?status=confirmed')->json('data.totals');

        $this->assertSame(1, $totals['responses']);
        $this->assertSame(1, $totals['entries']);
    }

    #[Test]
    public function status_breakdown_counts_both_responses_and_people(): void
    {
        $this->actingAsAdmin();

        $byStatus = collect($this->getJson($this->url())->json('data.by_status'))->keyBy('status');

        $this->assertSame(3, $byStatus['new']['responses']);
        $this->assertSame(5, $byStatus['new']['entries']);
        $this->assertSame(1, $byStatus['confirmed']['responses']);
    }

    /** A breakdown over one or two people would identify them. */
    #[Test]
    public function breakdowns_are_suppressed_for_very_small_groups(): void
    {
        FormResponse::where('form_id', $this->form->id)->take(3)->get()->each->delete();

        $this->actingAsAdmin();

        $this->assertSame([], $this->getJson($this->url())->json('data.breakdowns'));
    }

    #[Test]
    public function another_masjid_cannot_read_these_insights(): void
    {
        $other = Masjid::create([
            'name' => 'Other Masjid',
            'email' => 'other-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '2 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'assistant_enabled' => true,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson("/api/admin/masjids/{$other->id}/forms/{$this->form->id}/insights");

        $this->assertNotEquals(200, $response->status());
    }
}
