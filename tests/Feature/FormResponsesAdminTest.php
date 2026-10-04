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
 * The Form Responses admin surface.
 *
 * Forms and responses are HAND-scoped (`$masjid->forms()`), not scoped by the
 * BelongsToMasjid global scope that .claude/rules/tenant-scoping.md covers — so the
 * cross-tenant guarantee here rests entirely on the controllers doing the right thing,
 * and needs its own backstop. `a_masjid_cannot_read_another_masjids_responses` is that
 * backstop: these rows are camp registrations containing minors' medical details.
 *
 * Also covers the parts of the list that are easy to get subtly wrong: sorting must
 * happen in SQL (not per-page in the client), `sort` must be allowlisted, and the CSV
 * export must not hand a spreadsheet a live formula.
 */
class FormResponsesAdminTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private Form $formA;
    private Form $formB;

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

        $this->formA = $this->makeForm($this->masjidA, 'camp-2026');
        $this->formB = $this->makeForm($this->masjidB, 'other-camp');

        $this->makeResponse($this->formA, 'Amal Yusuf', 'amal@example.com', 'new', '2026-07-01', 2);
        $this->makeResponse($this->formA, 'Bilal Khan', 'bilal@example.com', 'confirmed', '2026-07-05', 1);
        $this->makeResponse($this->formA, 'Zaynab Ali', 'zaynab@example.com', 'new', '2026-07-10', 3);

        $this->makeResponse($this->formB, 'Someone Else', 'else@example.com', 'new', '2026-07-02', 1);
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

    private function makeForm(Masjid $masjid, string $slug): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => $slug,
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
                ],
            ],
            'settings' => [
                'identity' => ['name' => 'registrantName', 'email' => 'registrantEmail'],
            ],
        ]);
    }

    private function makeResponse(Form $form, string $name, string $email, string $status, string $date, int $entries): FormResponse
    {
        return FormResponse::create([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['registrantName' => $name, 'registrantEmail' => $email],
            'respondent_name' => $name,
            'respondent_email' => $email,
            'entry_count' => $entries,
            'status' => $status,
            'submitted_at' => $date . ' 12:00:00',
        ]);
    }

    /** Acting as an authenticated admin; route middleware handles the rest. */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function url(Masjid $masjid, Form $form, string $suffix = ''): string
    {
        return "/api/admin/masjids/{$masjid->id}/forms/{$form->id}/responses{$suffix}";
    }

    // ------------------------------------------------------------------ isolation

    #[Test]
    public function a_masjid_cannot_read_another_masjids_responses(): void
    {
        $this->actingAsAdmin();

        // Masjid A's id in the path, masjid B's form id. The form must not resolve.
        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/forms/{$this->formB->id}/responses");

        $this->assertNotEquals(200, $response->status(), 'A form from another masjid must never resolve.');
    }

    #[Test]
    public function the_list_only_returns_responses_for_the_requested_form(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson($this->url($this->masjidA, $this->formA))->assertOk();

        $emails = collect($response->json('data.data'))->pluck('respondent_email')->all();

        $this->assertCount(3, $emails);
        $this->assertNotContains('else@example.com', $emails);
    }

    // --------------------------------------------------------------------- search

    #[Test]
    public function search_matches_name_and_email(): void
    {
        $this->actingAsAdmin();

        $byName = $this->getJson($this->url($this->masjidA, $this->formA, '?q=Zaynab'))->assertOk();
        $this->assertCount(1, $byName->json('data.data'));

        $byEmail = $this->getJson($this->url($this->masjidA, $this->formA, '?q=bilal@'))->assertOk();
        $this->assertCount(1, $byEmail->json('data.data'));
    }

    #[Test]
    public function search_finds_a_child_named_in_the_answers_not_only_the_parent_who_filled_the_form_in(): void
    {
        $this->actingAsAdmin();

        // An enrolment: the parent is the respondent, the children are answers inside it.
        FormResponse::create([
            'form_id' => $this->formA->id,
            'masjid_id' => $this->formA->masjid_id,
            'data' => [
                'registrantName' => 'Maryam Idris',
                'registrantEmail' => 'maryam@example.com',
                'children' => [
                    ['firstName' => 'Tariq', 'lastName' => 'Rahmani', 'age' => 5],
                    ['firstName' => 'Layla', 'lastName' => 'Rahmani', 'age' => 8],
                ],
            ],
            'respondent_name' => 'Maryam Idris',
            'respondent_email' => 'maryam@example.com',
            'entry_count' => 2,
            'status' => 'new',
            'submitted_at' => '2026-07-09 12:00:00',
        ]);

        $find = fn (string $q) => collect(
            $this->getJson($this->url($this->masjidA, $this->formA, '?q=' . urlencode($q)))->assertOk()->json('data.data')
        )->pluck('respondent_email')->all();

        // The child's first name, in any letter case.
        $this->assertSame(['maryam@example.com'], $find('Tariq'));
        $this->assertSame(['maryam@example.com'], $find('tariq'));
        $this->assertSame(['maryam@example.com'], $find('TARIQ'));
        // The full name is two answers, so every word is looked for on its own, in any order.
        $this->assertSame(['maryam@example.com'], $find('Tariq Rahmani'));
        $this->assertSame(['maryam@example.com'], $find('rahmani layla'));
        // A word from the parent and a word from a child.
        $this->assertSame(['maryam@example.com'], $find('Maryam Tariq'));
        // Every word must be there: one that is nowhere finds nothing.
        $this->assertSame([], $find('Tariq Nobody'));
    }

    #[Test]
    public function a_number_is_not_looked_for_inside_the_answers(): void
    {
        $this->actingAsAdmin();

        // An age of 7 in the answers, and no phone, name or registration number with a 7.
        $response = FormResponse::create([
            'form_id' => $this->formA->id,
            'masjid_id' => $this->formA->masjid_id,
            'data' => ['registrantName' => 'Hana Odeh', 'registrantEmail' => 'hana@example.com', 'children' => [['firstName' => 'Sami', 'age' => 7]]],
            'respondent_name' => 'Hana Odeh',
            'respondent_email' => 'hana@example.com',
            'entry_count' => 1,
            'status' => 'new',
            'submitted_at' => '2026-07-09 12:00:00',
        ]);

        $emails = fn (string $q) => collect(
            $this->getJson($this->url($this->masjidA, $this->formA, '?q=' . urlencode($q)))->assertOk()->json('data.data')
        )->pluck('respondent_email')->all();

        // "7" is a registration number or part of a phone, never "anyone aged 7": only
        // the row whose own number is 7 could match, and this one's is not.
        $this->assertNotSame(7, (int) $response->id);
        $this->assertSame([], $emails('7'));
        // Its own registration number still finds it, with or without the hash.
        $this->assertSame(['hana@example.com'], $emails('#' . $response->id));
    }

    #[Test]
    public function search_never_reaches_another_organisations_answers(): void
    {
        $this->actingAsAdmin();

        FormResponse::create([
            'form_id' => $this->formB->id,
            'masjid_id' => $this->formB->masjid_id,
            'data' => ['registrantName' => 'Other Parent', 'registrantEmail' => 'other@example.com', 'children' => [['firstName' => 'Tariq']]],
            'respondent_name' => 'Other Parent',
            'respondent_email' => 'other@example.com',
            'entry_count' => 1,
            'status' => 'new',
            'submitted_at' => '2026-07-09 12:00:00',
        ]);

        $this->assertCount(0, $this->getJson($this->url($this->masjidA, $this->formA, '?q=Tariq'))->assertOk()->json('data.data'));
    }

    // --------------------------------------------------------------------- filter

    #[Test]
    public function status_filter_narrows_the_list(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson($this->url($this->masjidA, $this->formA, '?status=new'))->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    #[Test]
    public function date_range_filter_narrows_the_list(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson(
            $this->url($this->masjidA, $this->formA, '?from=2026-07-04&to=2026-07-06')
        )->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('bilal@example.com', $response->json('data.data.0.respondent_email'));
    }

    #[Test]
    public function an_unknown_status_is_rejected_rather_than_ignored(): void
    {
        $this->actingAsAdmin();

        $this->getJson($this->url($this->masjidA, $this->formA, '?status=deleted'))
            ->assertStatus(422);
    }

    // ----------------------------------------------------------------------- sort

    #[Test]
    public function sorting_is_applied_in_sql_across_the_whole_result_set(): void
    {
        $this->actingAsAdmin();

        // Page size of 2 proves ordering happens in the database: a client-side sort
        // would only order the rows already on the page.
        $response = $this->getJson(
            $this->url($this->masjidA, $this->formA, '?sort=respondent_name&direction=asc&per_page=2')
        )->assertOk();

        $names = collect($response->json('data.data'))->pluck('respondent_name')->all();

        $this->assertSame(['Amal Yusuf', 'Bilal Khan'], $names);
    }

    #[Test]
    public function sorting_descending_reverses_the_order(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson(
            $this->url($this->masjidA, $this->formA, '?sort=entry_count&direction=desc')
        )->assertOk();

        $counts = collect($response->json('data.data'))->pluck('entry_count')->all();

        $this->assertSame([3, 2, 1], $counts);
    }

    /** `sort` is interpolated into ORDER BY, so anything off the allowlist must 422. */
    #[Test]
    public function an_unlisted_sort_column_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson($this->url($this->masjidA, $this->formA, '?sort=admin_notes'))
            ->assertStatus(422);

        $this->getJson($this->url($this->masjidA, $this->formA, '?sort=id;drop table forms'))
            ->assertStatus(422);
    }

    // --------------------------------------------------------------------- detail

    #[Test]
    public function the_list_omits_full_submission_data_but_the_detail_includes_it(): void
    {
        $this->actingAsAdmin();

        $list = $this->getJson($this->url($this->masjidA, $this->formA))->assertOk();
        $this->assertArrayNotHasKey('data', $list->json('data.data.0'));

        $id = $list->json('data.data.0.id');
        $detail = $this->getJson($this->url($this->masjidA, $this->formA, "/{$id}"))->assertOk();

        $this->assertArrayHasKey('registrantName', $detail->json('data.data'));
    }

    #[Test]
    public function an_admin_can_triage_a_response_but_not_rewrite_the_submission(): void
    {
        $this->actingAsAdmin();

        // Order explicitly: an unordered first() picks an arbitrary row, which makes the
        // assertion below depend on storage order rather than on behaviour.
        $target = FormResponse::where('form_id', $this->formA->id)->orderBy('id')->first();
        $originalName = $target->data['registrantName'];

        $this->putJson($this->url($this->masjidA, $this->formA, "/{$target->id}"), [
            'status' => 'confirmed',
            'admin_notes' => 'Paid by Zelle.',
            // Not fillable through this endpoint — a waiver record must stay evidence.
            'data' => ['registrantName' => 'Tampered'],
        ])->assertOk();

        $target->refresh();

        $this->assertSame('confirmed', $target->status);
        $this->assertSame('Paid by Zelle.', $target->admin_notes);
        $this->assertSame($originalName, $target->data['registrantName']);
        $this->assertNotSame('Tampered', $target->data['registrantName']);
    }

    /**
     * The list's status select sends the status it showed (2026-09-27). Another admin
     * cancelled this registration after the list was read; the stale "confirmed -> new"
     * must not quietly restore it.
     */
    #[Test]
    public function a_status_change_from_a_list_read_before_another_admins_cancel_is_refused_and_changes_nothing(): void
    {
        $this->actingAsAdmin();

        $target = FormResponse::where('form_id', $this->formA->id)->where('status', 'confirmed')->firstOrFail();
        $target->forceFill(['status' => 'cancelled'])->save();

        $this->putJson($this->url($this->masjidA, $this->formA, "/{$target->id}"), [
            'status' => 'new',
            'expected_status' => 'confirmed',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'status_changed')
            ->assertJsonPath('message', 'This registration changed since the list was loaded: it is now Cancelled. Nothing was saved; the row now shows it as it stands.');

        $this->assertSame('cancelled', $target->fresh()->status);
    }

    #[Test]
    public function a_status_change_whose_expected_status_still_holds_is_saved(): void
    {
        $this->actingAsAdmin();

        $target = FormResponse::where('form_id', $this->formA->id)->where('status', 'confirmed')->firstOrFail();

        $this->putJson($this->url($this->masjidA, $this->formA, "/{$target->id}"), [
            'status' => 'waitlisted',
            'expected_status' => 'confirmed',
        ])->assertOk()->assertJsonPath('data.status', 'waitlisted');

        $this->assertSame('waitlisted', $target->fresh()->status);
    }

    /**
     * The list's cancel question says a cancelled registration still counts towards the
     * form's limit and only a delete frees a place (formResponseStatus.ts::cancelQuestion()).
     * That holds because update() never touches forms.response_count; only the deleted hook
     * lowers it. If this changes, change the sentence with it.
     */
    #[Test]
    public function cancelling_a_registration_keeps_its_place_and_only_deleting_frees_one(): void
    {
        $this->actingAsAdmin();

        $this->formA->forceFill(['capacity' => 3])->save();
        $this->assertTrue($this->formA->fresh()->isAtCapacity(), 'the premise: three registrations fill a limit of three');

        $target = FormResponse::where('form_id', $this->formA->id)->orderBy('id')->firstOrFail();

        $this->putJson($this->url($this->masjidA, $this->formA, "/{$target->id}"), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(3, $this->formA->fresh()->response_count);
        $this->assertTrue($this->formA->fresh()->isAtCapacity(), 'a cancelled registration still holds its place');

        $this->deleteJson($this->url($this->masjidA, $this->formA, "/{$target->id}"))->assertOk();

        $this->assertSame(2, $this->formA->fresh()->response_count);
        $this->assertFalse($this->formA->fresh()->isAtCapacity());
    }

    // --------------------------------------------------------------------- export

    #[Test]
    public function the_export_streams_csv_for_the_current_filter(): void
    {
        $this->actingAsAdmin();

        $response = $this->get($this->url($this->masjidA, $this->formA, '/export?status=new'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Amal Yusuf', $csv);
        $this->assertStringContainsString('Zaynab Ali', $csv);
        // 'confirmed' was filtered out.
        $this->assertStringNotContainsString('Bilal Khan', $csv);
        // Another masjid's data must never appear.
        $this->assertStringNotContainsString('Someone Else', $csv);
    }

    /**
     * A registrant can type `=HYPERLINK(...)` into a name field; Excel and Sheets would
     * execute it when a volunteer opens the export.
     */
    #[Test]
    public function the_export_neutralises_spreadsheet_formula_injection(): void
    {
        $this->actingAsAdmin();

        $this->makeResponse($this->formA, '=HYPERLINK("http://evil","click")', 'x@example.com', 'new', '2026-07-11', 1);

        $csv = $this->get($this->url($this->masjidA, $this->formA, '/export'))->streamedContent();

        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // --------------------------------------------------------------- housekeeping

    #[Test]
    public function deleting_a_response_decrements_the_forms_counter(): void
    {
        $this->actingAsAdmin();

        $this->assertSame(3, $this->formA->fresh()->response_count);

        $target = FormResponse::where('form_id', $this->formA->id)->first();

        $this->deleteJson($this->url($this->masjidA, $this->formA, "/{$target->id}"))->assertOk();

        $this->assertSame(2, $this->formA->fresh()->response_count);
    }
}
