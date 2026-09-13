<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Page;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use App\Rules\ValidFormSchema;
use App\Support\FormInsights;
use App\Support\FormOptionSources;
use App\Support\OfferingPublicPayload;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cleaning-Sunday question: a choice field whose options are the school
 * calendar's open days (optionsSource 'school_meeting_days').
 *
 * What these pin, most dangerous first: a calendar with NOTHING open refuses
 * every answer rather than switching the check off; a day that is closed, past,
 * invented or another school's is refused at submit even if the page offered
 * it; the page and both form doors read the same live set; answers stay
 * labelled after their day closes; and "pick exactly 2" means exactly 2.
 *
 * The fixture: BISS's Sundays from 2026-10-11 to 2026-12-20, Thanksgiving
 * weekend off, on New York's clock, seen from Tuesday 20 October.
 */
class FormSchoolDaysOptionsTest extends TestCase
{
    use RefreshDatabase;

    /** The open Sundays strictly after Tuesday 2026-10-20 — 11-22 is closed. */
    private const OFFERED = ['2026-10-25', '2026-11-01', '2026-11-08', '2026-11-15', '2026-11-29', '2026-12-06', '2026-12-13', '2026-12-20'];

    private Masjid $school;
    private Masjid $other;
    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();
        $this->travelTo(Carbon::parse('2026-10-20 16:00:00'));

        $this->school = $this->makeMasjid(['timezone' => 'America/New_York']);
        $this->other = $this->makeMasjid();

        $this->year = SchoolYear::create([
            'masjid_id' => $this->school->id, 'label' => '2026–27',
            'first_day' => '2026-10-11', 'last_day' => '2026-12-20',
        ]);
        $this->close('2026-11-22', 'Thanksgiving weekend');

        // Another school, meeting on Saturdays.
        SchoolYear::create([
            'masjid_id' => $this->other->id, 'label' => 'Theirs',
            'first_day' => '2026-10-10', 'last_day' => '2026-12-19',
        ]);
    }

    // ------------------------------------------------------------ serving

    #[Test]
    public function the_page_serves_only_the_open_days_after_today_and_stores_none(): void
    {
        $form = $this->makeForm(['minSelections' => 2, 'maxSelections' => 2]);

        $field = $this->servedField($form);

        $this->assertSame(self::OFFERED, array_column($field['options'], 'value'));
        $this->assertSame('Sunday, October 25, 2026', $field['options'][0]['label']);

        // The renderer is told how many to pick.
        $this->assertSame(2, $field['minSelections']);
        $this->assertSame(2, $field['maxSelections']);

        // A reference, never a copy: nothing was written back to the form.
        $this->assertArrayNotHasKey('options', $form->fresh()->schema['sections'][0]['fields'][1]);
    }

    #[Test]
    public function the_offering_payload_serves_the_same_days(): void
    {
        $form = $this->makeForm();
        $offering = Offering::factory()->forMasjid($this->school)->create();
        $offering->forceFill(['intake_form_id' => $form->id])->save();

        $schema = OfferingPublicPayload::build($offering->fresh())['intake_form']['schema'];

        $this->assertSame(self::OFFERED, array_column($schema['sections'][0]['fields'][1]['options'], 'value'));
    }

    // ----------------------------------------------------------- submitting

    #[Test]
    public function an_offered_day_is_accepted_and_stored_as_its_date(): void
    {
        $form = $this->makeForm();

        $this->submit($form, ['2026-10-25', '2026-12-20'])->assertOk();

        $this->assertSame(['2026-10-25', '2026-12-20'], FormResponse::where('form_id', $form->id)->first()->data['cleaning']);
    }

    #[Test]
    public function a_closed_past_invented_or_other_schools_day_is_refused(): void
    {
        $form = $this->makeForm();
        $radio = $this->makeForm(['type' => 'radio']);

        foreach ([
            '2026-11-22' => 'closed',
            '2026-10-18' => 'already past',
            '2026-10-26' => 'a Monday',
            '2026-10-24' => "the other school's Saturday",
            'next week' => 'not a date at all',
        ] as $day => $why) {
            $this->assertSame([FormOptionSources::NO_LONGER_OPEN], $this->errors($this->submit($form, [$day]))['cleaning.0'] ?? null, $why);
            $this->assertSame([FormOptionSources::NO_LONGER_OPEN], $this->errors($this->submit($radio, $day))['cleaning'] ?? null, "radio: {$why}");
        }

        $this->submit($radio, '2026-10-25')->assertOk();
        $this->assertSame(0, FormResponse::where('form_id', $form->id)->count());
    }

    #[Test]
    public function today_is_not_offered_and_today_is_the_schools_today(): void
    {
        $form = $this->makeForm();

        // Saturday 23:30 in New York, although UTC is already Sunday: Sunday is open.
        $this->travelTo(Carbon::parse('2026-10-25 03:30:00'));
        $this->submit($form, ['2026-10-25'])->assertOk();

        // Sunday 10:00 in New York: that Sunday has started.
        $this->travelTo(Carbon::parse('2026-10-25 14:00:00'));
        $this->assertSame([FormOptionSources::NO_LONGER_OPEN], $this->errors($this->submit($form, ['2026-10-25']))['cleaning.0'] ?? null);
    }

    #[Test]
    public function a_day_closed_after_the_page_loaded_is_refused(): void
    {
        $form = $this->makeForm();

        $this->assertContains('2026-11-29', array_column($this->servedField($form)['options'], 'value'));

        $this->close('2026-11-29', 'Snow day');

        $this->assertSame([FormOptionSources::NO_LONGER_OPEN], $this->errors($this->submit($form, ['2026-11-29']))['cleaning.0'] ?? null);
    }

    // ------------------------------------------------- nothing open: must_fix 2

    #[Test]
    public function with_no_school_year_every_answer_is_refused_and_a_required_question_is_not_passed(): void
    {
        $bare = $this->makeMasjid();
        $optional = $this->makeForm([], $bare);
        $required = $this->makeForm(['required' => true], $bare);
        $radio = $this->makeForm(['type' => 'radio'], $bare);

        $this->assertSame([], $this->servedField($optional, $bare)['options']);

        $this->assertSame([FormOptionSources::NONE_OPEN], $this->errors($this->submit($optional, ['2026-10-25'], $bare))['cleaning.0'] ?? null);
        $this->assertSame([FormOptionSources::NONE_OPEN], $this->errors($this->submit($radio, '2026-10-25', $bare))['cleaning'] ?? null);
        $this->assertSame([FormOptionSources::NONE_OPEN], $this->errors($this->submit($required, [], $bare))['cleaning'] ?? null);
        $this->assertSame([FormOptionSources::NONE_OPEN], $this->errors($this->submit($required, null, $bare))['cleaning'] ?? null);

        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function when_the_year_is_over_every_answer_is_refused(): void
    {
        $form = $this->makeForm();
        $required = $this->makeForm(['required' => true]);

        $this->travelTo(Carbon::parse('2027-01-10 15:00:00'));

        $this->assertSame([FormOptionSources::NONE_OPEN], $this->errors($this->submit($form, ['2026-12-20']))['cleaning.0'] ?? null);
        $this->assertSame([FormOptionSources::NONE_OPEN], $this->errors($this->submit($required, null))['cleaning'] ?? null);
    }

    #[Test]
    public function when_every_remaining_day_is_closed_every_answer_is_refused(): void
    {
        $form = $this->makeForm();

        $this->travelTo(Carbon::parse('2026-11-30 15:00:00'));
        foreach (['2026-12-06', '2026-12-13', '2026-12-20'] as $day) {
            $this->close($day, 'Building work');
        }

        $this->assertSame([FormOptionSources::NONE_OPEN], $this->errors($this->submit($form, ['2026-12-06']))['cleaning.0'] ?? null);
    }

    // ------------------------------------------------------- how many to pick

    #[Test]
    public function exactly_two_sundays_means_exactly_two(): void
    {
        $form = $this->makeForm(['minSelections' => 2, 'maxSelections' => 2]);

        $this->submit($form, ['2026-10-25', '2026-11-01'])->assertOk();

        $this->assertSame(['Pick exactly 2 Sundays.'], $this->errors($this->submit($form, ['2026-10-25']))['cleaning'] ?? null);
        $this->assertSame(['Pick exactly 2 Sundays.'], $this->errors($this->submit($form, ['2026-10-25', '2026-11-01', '2026-11-08']))['cleaning'] ?? null);
    }

    #[Test]
    public function an_optional_question_counts_only_an_answer_that_was_given(): void
    {
        $optional = $this->makeForm(['minSelections' => 2]);

        // Left blank: passes, whatever the count says.
        $this->submit($optional, [])->assertOk();
        $this->submit($optional, null)->assertOk();

        // Answered with one of at least two: refused.
        $this->assertSame(['Pick at least 2 Sundays.'], $this->errors($this->submit($optional, ['2026-10-25']))['cleaning'] ?? null);

        // Required: blank fails as required, and a given answer still counts.
        $required = $this->makeForm(['required' => true, 'minSelections' => 2, 'maxSelections' => 2]);
        $this->assertArrayHasKey('cleaning', $this->errors($this->submit($required, [])));
        $this->assertSame(['Pick exactly 2 Sundays.'], $this->errors($this->submit($required, ['2026-10-25']))['cleaning'] ?? null);
    }

    #[Test]
    public function fewer_days_open_than_must_be_picked_is_refused_never_passed(): void
    {
        $form = $this->makeForm(['minSelections' => 2, 'maxSelections' => 2]);
        $required = $this->makeForm(['required' => true, 'minSelections' => 2, 'maxSelections' => 2]);

        // Monday 14 December: only the 20th is left.
        $this->travelTo(Carbon::parse('2026-12-14 15:00:00'));

        $this->assertSame([FormOptionSources::NOT_ENOUGH_OPEN], $this->errors($this->submit($form, ['2026-12-20']))['cleaning'] ?? null);
        $this->assertSame([FormOptionSources::NOT_ENOUGH_OPEN], $this->errors($this->submit($required, null))['cleaning'] ?? null);

        // Optional and left blank is still a blank.
        $this->submit($form, [])->assertOk();
    }

    #[Test]
    public function the_counts_read_plainly_on_a_typed_list_too(): void
    {
        $typed = ['optionsSource' => null, 'options' => [
            ['value' => 'setup', 'label' => 'Setup'], ['value' => 'food', 'label' => 'Food'], ['value' => 'games', 'label' => 'Games'],
        ]];

        $atLeast = $this->makeForm($typed + ['minSelections' => 2]);
        $atMost = $this->makeForm($typed + ['maxSelections' => 2]);
        $exactlyOne = $this->makeForm($typed + ['minSelections' => 1, 'maxSelections' => 1]);

        $this->assertSame(['Pick at least 2 options.'], $this->errors($this->submit($atLeast, ['setup']))['cleaning'] ?? null);
        $this->assertSame(['Pick no more than 2 options.'], $this->errors($this->submit($atMost, ['setup', 'food', 'games']))['cleaning'] ?? null);
        $this->assertSame(['Pick exactly 1 option.'], $this->errors($this->submit($exactlyOne, ['setup', 'food']))['cleaning'] ?? null);

        $this->submit($atLeast, ['setup', 'games'])->assertOk();
    }

    // ------------------------------------------------------------- reading

    #[Test]
    public function insights_label_a_day_chosen_before_it_closed(): void
    {
        $form = $this->makeForm();

        foreach (range(1, 3) as $i) {
            $this->submit($form, ['2026-11-29'])->assertOk();
        }

        $this->close('2026-11-29', 'Snow day');

        $insights = FormInsights::for($form)->build(FormResponse::where('form_id', $form->id)->get());
        $day = collect(collect($insights['breakdowns'])->firstWhere('field', 'cleaning')['options'])->firstWhere('value', '2026-11-29');

        $this->assertSame(['value' => '2026-11-29', 'label' => 'Sunday, November 29, 2026', 'count' => 3, 'detail' => 'No school — Snow day'], $day);

        // A stored date outside every school year still gets a label, never a blank.
        $labels = FormOptionSources::resolve($form, $form->schema['sections'][0]['fields'][1], FormOptionSources::LABEL, ['2025-05-04', 'garbage']);
        $this->assertSame('Sunday, May 4, 2025', collect($labels)->firstWhere('value', '2025-05-04')['label']);
        $this->assertNull(collect($labels)->firstWhere('value', 'garbage'));
    }

    // ------------------------------------------------------------ the builder

    #[Test]
    public function the_builder_refuses_a_source_or_a_count_it_cannot_honour(): void
    {
        $cleaning = ['name' => 'cleaning', 'label' => 'Cleaning Sundays', 'type' => 'checkboxGroup', 'optionsSource' => 'school_meeting_days'];
        $typed = ['name' => 'shift', 'label' => 'Shift', 'type' => 'checkboxGroup', 'options' => [
            ['value' => 'am', 'label' => 'Morning'], ['value' => 'pm', 'label' => 'Afternoon'],
        ]];

        foreach ([
            'a calendar question' => [$cleaning, false, true],
            'a calendar question with an empty options list' => [$cleaning + ['options' => []], false, true],
            'a calendar dropdown' => [['type' => 'select'] + $cleaning, false, true],
            'pick exactly two, as numbers' => [$cleaning + ['minSelections' => 2, 'maxSelections' => 2], false, true],
            'pick one or two, as form-post strings' => [$typed + ['minSelections' => '1', 'maxSelections' => '2'], false, true],
            'an unknown source' => [['optionsSource' => 'the_moon'] + $cleaning, false, false],
            'a source on a text question' => [['type' => 'text'] + $cleaning, false, false],
            'a source inside a repeatable section' => [$cleaning, true, false],
            'a source beside typed options' => [$cleaning + ['options' => [['value' => 'x', 'label' => 'X']]], false, false],
            'a count on a text question' => [['name' => 'note', 'label' => 'Note', 'type' => 'text', 'minSelections' => 2], false, false],
            'a count on a dropdown' => [['type' => 'select', 'maxSelections' => 1] + $cleaning, false, false],
            'a minimum above the maximum' => [$cleaning + ['minSelections' => 3, 'maxSelections' => 2], false, false],
            'a minimum of zero' => [$cleaning + ['minSelections' => 0], false, false],
            'a fractional count' => [$cleaning + ['minSelections' => 1.5], false, false],
            'more picks than a typed list has' => [$typed + ['minSelections' => 3], false, false],
        ] as $label => [$field, $repeatable, $accepted]) {
            $passes = Validator::make(['schema' => ['sections' => [
                ['id' => 'family', 'title' => 'Family', 'repeatable' => $repeatable, 'fields' => [$field]],
            ]]], ['schema' => [new ValidFormSchema]])->passes();

            $this->assertSame($accepted, $passes, $label);
        }
    }

    #[Test]
    public function the_builder_is_told_what_the_calendar_and_the_counts_offer(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]));

        $response = $this->getJson("/api/admin/masjids/{$this->school->id}/forms/field-types")->assertOk();
        $response->assertJsonPath('options_sources.0', [
            'key' => 'school_meeting_days', 'label' => 'School calendar — meeting days', 'available' => true,
        ]);

        $types = collect($response->json('data'))->keyBy('value');
        $this->assertSame(['minSelections', 'maxSelections'], $types['checkboxGroup']['selection_keys']);
        $this->assertSame([], $types['text']['selection_keys']);

        $bare = $this->makeMasjid();
        $this->getJson("/api/admin/masjids/{$bare->id}/forms/field-types")
            ->assertOk()->assertJsonPath('options_sources.0.available', false);
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string,mixed> $question overrides on the cleaning question */
    private function makeForm(array $question = [], ?Masjid $masjid = null): Form
    {
        return Form::create([
            'masjid_id' => ($masjid ?? $this->school)->id,
            'slug' => 'register-'.uniqid(),
            'name' => 'Sunday School Registration',
            'schema' => ['sections' => [[
                'id' => 'family', 'title' => 'Your family',
                'fields' => [
                    ['name' => 'parentName', 'label' => 'Parent name', 'type' => 'text', 'required' => true],
                    array_merge([
                        'name' => 'cleaning', 'label' => 'Cleaning Sundays',
                        'type' => 'checkboxGroup', 'optionsSource' => 'school_meeting_days',
                    ], $question),
                ],
            ]]],
            'settings' => ['identity' => ['name' => 'parentName']],
        ]);
    }

    private function submit(Form $form, mixed $cleaning, ?Masjid $masjid = null): TestResponse
    {
        $data = ['parentName' => 'Amal Yusuf'];

        if ($cleaning !== null) {
            $data['cleaning'] = $cleaning;
        }

        return $this->postJson("/api/v1/forms/{$form->id}/responses", ['data' => $data], [
            'masjid-id' => (string) ($masjid ?? $this->school)->id,
        ]);
    }

    /** @return array<string,array<int,string>> */
    private function errors(TestResponse $response): array
    {
        return $response->assertUnprocessable()->json('data');
    }

    /** The cleaning question as the public page serves it (SectionContentBinder::bindForm). */
    private function servedField(Form $form, ?Masjid $masjid = null): array
    {
        $masjid ??= $this->school;

        $page = Page::create([
            'masjid_id' => $masjid->id, 'slug' => 'register-'.uniqid(),
            'title' => 'Register', 'is_active' => true, 'order' => 1,
        ]);
        $section = Section::create([
            'masjid_id' => $masjid->id, 'section_type' => 'form', 'title' => 'Registration',
            'content' => ['form_id' => $form->id], 'is_active' => true,
        ]);
        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $this->withHeader('masjid-id', (string) $masjid->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertOk()
            ->json('data.sections.0.content.form.schema.sections.0.fields.1');
    }

    private function close(string $day, string $reason): void
    {
        SchoolClosure::create([
            'masjid_id' => $this->school->id, 'school_year_id' => $this->year->id,
            'closed_on' => $day, 'reason' => $reason,
        ]);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'school',
        ], $overrides));
    }
}
