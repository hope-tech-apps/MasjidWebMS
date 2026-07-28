<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public submission endpoint — the only unauthenticated write in this feature, and
 * therefore the one an attacker reaches first.
 *
 * `/api/v1/*` carries no middleware at all in this app, so everything protecting this
 * route lives in the controller and its named throttle. These tests are what say so.
 */
class FormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
        $this->form = $this->makeForm($this->masjidA);
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

    private function makeForm(Masjid $masjid, array $overrides = []): Form
    {
        return Form::create(array_merge([
            'masjid_id' => $masjid->id,
            'slug' => 'camp-' . uniqid(),
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
                        'maxEntries' => 4,
                        'fields' => [
                            ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                            ['name' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => true, 'min' => 7],
                        ],
                    ],
                    [
                        'id' => 'consent',
                        'title' => 'Consent',
                        'fields' => [
                            ['name' => 'waiver', 'label' => 'Waiver', 'type' => 'checkbox', 'required' => true],
                        ],
                    ],
                ],
            ],
            'settings' => [
                'identity' => ['name' => 'registrantName', 'email' => 'registrantEmail'],
                'fee' => ['amount' => 100, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
            ],
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'data' => [
                'registrantName' => 'Amal Yusuf',
                'registrantEmail' => 'amal@example.com',
                'attendees' => [
                    ['fullName' => 'Amal Yusuf', 'age' => 34],
                    ['fullName' => 'Yusuf Jr', 'age' => 12],
                ],
                'waiver' => true,
            ],
        ], $overrides);
    }

    private function submit(array $payload, ?int $masjidId = null, ?Form $form = null)
    {
        $form = $form ?: $this->form;
        $headers = [];

        if ($masjidId !== null) {
            $headers['masjid-id'] = (string) $masjidId;
        }

        return $this->postJson("/api/v1/forms/{$form->id}/responses", $payload, $headers);
    }

    // ------------------------------------------------------------------ happy path

    #[Test]
    public function a_valid_submission_is_stored(): void
    {
        $this->submit($this->payload(), $this->masjidA->id)->assertOk();

        $this->assertSame(1, FormResponse::count());

        $stored = FormResponse::first();
        $this->assertSame($this->form->id, $stored->form_id);
        $this->assertSame($this->masjidA->id, $stored->masjid_id);
        $this->assertSame('Amal Yusuf', $stored->data['registrantName']);
    }

    #[Test]
    public function the_identity_columns_are_populated_for_search(): void
    {
        $this->submit($this->payload(), $this->masjidA->id)->assertOk();

        $stored = FormResponse::first();

        $this->assertSame('Amal Yusuf', $stored->respondent_name);
        $this->assertSame('amal@example.com', $stored->respondent_email);
    }

    #[Test]
    public function entry_count_and_amount_due_are_computed_server_side(): void
    {
        $this->submit($this->payload(), $this->masjidA->id)->assertOk();

        $stored = FormResponse::first();

        $this->assertSame(2, $stored->entry_count);
        $this->assertSame('200.00', (string) $stored->amount_due);
    }

    /** The client must not be able to state its own total. */
    #[Test]
    public function a_client_supplied_amount_is_ignored(): void
    {
        $payload = $this->payload();
        $payload['amount_due'] = 0;
        $payload['data']['amount_due'] = 0;

        $this->submit($payload, $this->masjidA->id)->assertOk();

        $this->assertSame('200.00', (string) FormResponse::first()->amount_due);
    }

    #[Test]
    public function submitting_increments_the_forms_counter(): void
    {
        $this->submit($this->payload(), $this->masjidA->id)->assertOk();

        $this->assertSame(1, $this->form->fresh()->response_count);
    }

    // -------------------------------------------------------------------- tenancy

    #[Test]
    public function a_form_cannot_be_submitted_against_another_masjids_header(): void
    {
        $this->submit($this->payload(), $this->masjidB->id)->assertStatus(404);

        $this->assertSame(0, FormResponse::count());
    }

    /**
     * Section::scopeFilterByMasjid no-ops when the header is missing, which would make
     * every tenant's forms writable. This endpoint must not behave that way.
     */
    #[Test]
    public function a_missing_masjid_header_is_rejected_rather_than_ignored(): void
    {
        $this->submit($this->payload(), null)->assertStatus(400);

        $this->assertSame(0, FormResponse::count());
    }

    // ----------------------------------------------------------------- validation

    #[Test]
    public function a_missing_required_field_is_rejected_with_a_field_bag(): void
    {
        $payload = $this->payload();
        unset($payload['data']['registrantEmail']);

        $response = $this->submit($payload, $this->masjidA->id)->assertStatus(422);

        $this->assertSame('failed', $response->json('status'));
        $this->assertArrayHasKey('registrantEmail', $response->json('data'));
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function an_unticked_required_consent_checkbox_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['data']['waiver'] = false;

        $this->submit($payload, $this->masjidA->id)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function exceeding_the_repeatable_maximum_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['data']['attendees'] = array_fill(0, 5, ['fullName' => 'X', 'age' => 20]);

        $this->submit($payload, $this->masjidA->id)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function an_attendee_below_the_minimum_age_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['data']['attendees'][] = ['fullName' => 'Too Young', 'age' => 4];

        $this->submit($payload, $this->masjidA->id)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function undeclared_fields_never_reach_storage(): void
    {
        $payload = $this->payload();
        $payload['data']['isAdmin'] = true;
        $payload['data']['attendees'][0]['injected'] = '<script>alert(1)</script>';

        $this->submit($payload, $this->masjidA->id)->assertOk();

        $stored = FormResponse::first();

        $this->assertArrayNotHasKey('isAdmin', $stored->data);
        $this->assertArrayNotHasKey('injected', $stored->data['attendees'][0]);
    }

    #[Test]
    public function a_non_array_data_payload_is_rejected(): void
    {
        $this->submit(['data' => 'nope'], $this->masjidA->id)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    // -------------------------------------------------------------------- windows

    #[Test]
    public function an_inactive_form_refuses_submissions(): void
    {
        $this->form->update(['is_active' => false]);

        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_closed_form_refuses_submissions(): void
    {
        $this->form->update(['closes_at' => now()->subDay()]);

        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_form_that_has_not_opened_yet_refuses_submissions(): void
    {
        $this->form->update(['opens_at' => now()->addDay()]);

        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(422);
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function capacity_is_enforced(): void
    {
        $this->form->update(['capacity' => 1]);

        $this->submit($this->payload(), $this->masjidA->id)->assertOk();
        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(422);

        $this->assertSame(1, FormResponse::count());
    }

    // ----------------------------------------------------------------------- spam

    #[Test]
    public function the_honeypot_silently_discards_a_bot_submission(): void
    {
        $payload = $this->payload();
        $payload['website'] = 'http://spam.example';

        // Reports success so a scripted submitter gets nothing to adapt to...
        $this->submit($payload, $this->masjidA->id)->assertOk();

        // ...but nothing is written.
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_soft_deleted_form_is_not_submittable(): void
    {
        $this->form->delete();

        $this->submit($this->payload(), $this->masjidA->id)->assertStatus(404);
        $this->assertSame(0, FormResponse::count());
    }
}
