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
 * The attendee roster: one row per PERSON, not per submission.
 *
 * The responses list answers "who filled in the form"; a camp coordinator needs "who is
 * coming", and those differ — one parent registering four people is one response and four
 * attendees. Before this, building a check-in sheet meant opening every registration.
 */
class FormRosterTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);

        $this->form = Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'camp',
            'name' => 'Camp',
            'schema' => ['sections' => [
                ['id' => 'registrant', 'title' => 'You', 'fields' => [
                    ['name' => 'registrantFirstName', 'label' => 'First name', 'type' => 'text', 'required' => true],
                    ['name' => 'registrantLastName', 'label' => 'Last name', 'type' => 'text', 'required' => true],
                ]],
                ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'fields' => [
                    ['name' => 'firstName', 'label' => 'First name', 'type' => 'text', 'required' => true],
                    ['name' => 'lastName', 'label' => 'Last name', 'type' => 'text', 'required' => true],
                    ['name' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => true],
                    ['name' => 'group', 'label' => 'Program track', 'type' => 'select', 'required' => true, 'options' => [
                        ['value' => 'brothers', 'label' => 'Brothers'],
                        ['value' => 'sisters', 'label' => 'Sisters'],
                    ]],
                    ['name' => 'allergies', 'label' => 'Allergies', 'type' => 'textarea', 'required' => false],
                ]],
            ]],
            'settings' => ['identity' => [
                'name' => ['registrantFirstName', 'registrantLastName'],
            ]],
        ]);

        // Two submissions, five people between them.
        $this->submit('Moneeb Sayed', 'moneeb@example.com', '336-000-0001', [
            ['Moneeb', 'Sayed', 41, 'brothers', 'None'],
            ['Aisha', 'Sayed', 9, 'sisters', 'Peanuts'],
            ['Yusuf', 'Sayed', 12, 'brothers', ''],
            ['Maryam', 'Sayed', 7, 'sisters', ''],
        ], '2026-07-29 16:19:00');

        $this->submit('Bilal Khan', 'bilal@example.com', '336-000-0002', [
            ['Bilal', 'Khan', 34, 'brothers', ''],
        ], '2026-07-30 09:00:00', 'confirmed');
    }

    private function submit(string $name, string $email, string $phone, array $people, string $at, string $status = 'new'): void
    {
        [$first, $last] = explode(' ', $name, 2);

        FormResponse::create([
            'form_id' => $this->form->id,
            'masjid_id' => $this->masjid->id,
            'data' => [
                'registrantFirstName' => $first,
                'registrantLastName' => $last,
                'attendees' => collect($people)->map(fn ($p) => [
                    'firstName' => $p[0], 'lastName' => $p[1], 'age' => $p[2],
                    'group' => $p[3], 'allergies' => $p[4],
                ])->all(),
            ],
            'respondent_name' => $name,
            'respondent_email' => $email,
            'respondent_phone' => $phone,
            'entry_count' => count($people),
            'amount_due' => count($people) * 100,
            'status' => $status,
            'submitted_at' => $at,
        ]);
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/responses/roster{$suffix}";
    }

    #[Test]
    public function the_roster_returns_one_row_per_person_not_per_submission(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson($this->url())->assertOk();

        // 2 submissions, 5 attendees.
        $this->assertSame(5, $response->json('data.total'));
        $this->assertSame(5, $response->json('meta.summary.people'));
        $this->assertSame(2, $response->json('meta.summary.submissions'));
    }

    #[Test]
    public function each_row_carries_the_attendees_own_answers(): void
    {
        $this->actingAsAdmin();

        $rows = collect($this->getJson($this->url() . '?sort=firstName&direction=asc')->json('data.data'));

        $aisha = $rows->first(fn ($r) => $r['values']['firstName'] === 'Aisha');

        $this->assertSame('Sayed', $aisha['values']['lastName']);
        $this->assertSame(9, $aisha['values']['age']);
        $this->assertSame('sisters', $aisha['values']['group']);
        $this->assertSame('Peanuts', $aisha['values']['allergies']);
    }

    /** Every attendee must be reachable without opening the submission. */
    #[Test]
    public function each_row_carries_who_registered_them_and_how_to_reach_them(): void
    {
        $this->actingAsAdmin();

        $rows = collect($this->getJson($this->url())->json('data.data'));
        $aisha = $rows->first(fn ($r) => $r['values']['firstName'] === 'Aisha');

        $this->assertSame('Moneeb Sayed', $aisha['registered_by']);
        $this->assertSame('moneeb@example.com', $aisha['registrant_email']);
        $this->assertSame('336-000-0001', $aisha['registrant_phone']);
        $this->assertSame('new', $aisha['status']);
    }

    #[Test]
    public function the_roster_exposes_a_column_per_attendee_question(): void
    {
        $this->actingAsAdmin();

        $columns = collect($this->getJson($this->url())->json('meta.columns'))->pluck('key')->all();

        $this->assertSame(['firstName', 'lastName', 'age', 'group', 'allergies'], $columns);
    }

    /** The head count an organiser reads first. */
    #[Test]
    public function the_summary_gives_the_brothers_sisters_split(): void
    {
        $this->actingAsAdmin();

        $breakdowns = collect($this->getJson($this->url())->json('meta.summary.breakdowns'));
        $track = $breakdowns->firstWhere('field', 'group');

        $counts = collect($track['options'])->pluck('count', 'value');

        $this->assertSame(3, $counts['brothers']);
        $this->assertSame(2, $counts['sisters']);
    }

    #[Test]
    public function a_family_stays_together_and_in_order_by_default(): void
    {
        $this->actingAsAdmin();

        $rows = collect($this->getJson($this->url())->json('data.data'));

        // Newest submission first, then the order the parent listed people in.
        $this->assertSame('Bilal', $rows[0]['values']['firstName']);
        $this->assertSame(['Moneeb', 'Aisha', 'Yusuf', 'Maryam'], $rows->slice(1)->pluck('values.firstName')->values()->all());
    }

    /** Ages must sort numerically: 7, 9, 12 — not 12, 7, 9. */
    #[Test]
    public function sorting_by_age_is_numeric(): void
    {
        $this->actingAsAdmin();

        $ages = collect($this->getJson($this->url() . '?sort=age&direction=asc')->json('data.data'))
            ->pluck('values.age')->all();

        $this->assertSame([7, 9, 12, 34, 41], $ages);
    }

    #[Test]
    public function the_roster_can_be_sorted_by_any_attendee_column(): void
    {
        $this->actingAsAdmin();

        $tracks = collect($this->getJson($this->url() . '?sort=group&direction=asc')->json('data.data'))
            ->pluck('values.group')->all();

        $this->assertSame(['brothers', 'brothers', 'brothers', 'sisters', 'sisters'], $tracks);
    }

    /**
     * An attendee column name must never reach the SQL ORDER BY of the submission list.
     */
    #[Test]
    public function an_attendee_column_is_not_accepted_as_a_sql_sort_on_the_list(): void
    {
        $this->actingAsAdmin();

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/responses?sort=age")
            ->assertStatus(422);
    }

    #[Test]
    public function a_dangerous_sort_value_is_still_rejected_on_the_roster(): void
    {
        $this->actingAsAdmin();

        $this->getJson($this->url() . '?sort=id;drop table forms')->assertStatus(422);
    }

    #[Test]
    public function the_roster_honours_the_status_filter(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson($this->url() . '?status=confirmed')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('Bilal', $response->json('data.data.0.values.firstName'));
    }

    #[Test]
    public function the_roster_honours_the_search_filter(): void
    {
        $this->actingAsAdmin();

        // Search matches the REGISTRANT, and returns all of that family's attendees.
        $response = $this->getJson($this->url() . '?q=Moneeb')->assertOk();

        $this->assertSame(4, $response->json('data.total'));
    }

    #[Test]
    public function the_roster_is_paginated(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson($this->url() . '?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data.data'));
        $this->assertSame(5, $response->json('data.total'));
        $this->assertSame(3, $response->json('data.last_page'));
    }

    #[Test]
    public function the_roster_exports_a_check_in_sheet(): void
    {
        $this->actingAsAdmin();

        $response = $this->get($this->url() . '/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();

        // A line per person, with the registrant alongside.
        foreach (['Moneeb', 'Aisha', 'Yusuf', 'Maryam', 'Bilal'] as $person) {
            $this->assertStringContainsString($person, $csv);
        }
        $this->assertStringContainsString('Registered by', $csv);
        $this->assertStringContainsString('moneeb@example.com', $csv);

        // 5 people + 1 header row.
        $this->assertSame(6, count(array_filter(explode("\n", trim($csv)))));
    }

    #[Test]
    public function another_masjid_cannot_read_the_roster(): void
    {
        $other = Masjid::create([
            'name' => 'Other', 'email' => 'o-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);

        $this->actingAsAdmin();

        $this->getJson("/api/admin/masjids/{$other->id}/forms/{$this->form->id}/responses/roster")
            ->assertStatus(500); // findOrFail on the scoped relation
    }

    /** A submission with no attendees must not silently vanish from the roster. */
    #[Test]
    public function a_submission_with_no_entries_still_appears(): void
    {
        FormResponse::create([
            'form_id' => $this->form->id,
            'masjid_id' => $this->masjid->id,
            'data' => ['registrantFirstName' => 'Empty', 'registrantLastName' => 'Case', 'attendees' => []],
            'respondent_name' => 'Empty Case',
            'entry_count' => 1,
            'status' => 'new',
            'submitted_at' => '2026-07-31 10:00:00',
        ]);

        $this->actingAsAdmin();

        $rows = collect($this->getJson($this->url())->json('data.data'));
        $orphan = $rows->first(fn ($r) => $r['registered_by'] === 'Empty Case');

        $this->assertNotNull($orphan, 'A registration with no attendees must still be visible to chase.');
        $this->assertTrue($orphan['incomplete'] ?? false);
    }
}
