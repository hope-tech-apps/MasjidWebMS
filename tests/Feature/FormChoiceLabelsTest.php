<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\FormOptionSources;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormChoiceLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Form $form;
    private FormResponse $response;
    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $masjid = Masjid::create([
            'name' => 'Test organisation', 'email' => 'org@example.test', 'phone' => '+15555550123',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0,
        ]);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550124']));
        $options = [
            ['value' => 'settingUp', 'label' => 'Setting up'],
            ['value' => 'chicken', 'label' => 'Chicken'],
            ['value' => 'rice,beans', 'label' => 'Rice and beans'],
        ];
        $this->form = Form::create([
            'masjid_id' => $masjid->id, 'slug' => 'test-choices', 'name' => 'Test choices',
            'schema' => ['sections' => [['id' => 'attendees', 'title' => 'Attendees', 'fields' => [
                ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => $options],
                ['name' => 'meal', 'label' => 'Meal', 'type' => 'radio', 'options' => $options],
                ['name' => 'extras', 'label' => 'Extras', 'type' => 'checkboxGroup', 'options' => $options],
            ]]]], 'settings' => [],
        ]);
        $this->response = FormResponse::create([
            'form_id' => $this->form->id, 'masjid_id' => $masjid->id,
            'data' => [], 'entry_count' => 1, 'status' => 'confirmed', 'submitted_at' => now(),
        ]);
        $this->url = "/api/admin/masjids/{$masjid->id}/forms/{$this->form->id}/responses";
    }

    public static function shapes(): array
    {
        return ['flat' => [false], 'repeating' => [true]];
    }

    private function answers(bool $repeatable): array
    {
        $schema = $this->form->schema;
        $schema['sections'][0]['repeatable'] = $repeatable;
        $this->form->update(['schema' => $schema]);
        $answers = ['role' => 'settingUp', 'meal' => 'chicken',
            'extras' => ['chicken', 'rice,beans', 'removedOption', 'Chicken']];
        $this->response->update(['data' => $repeatable ? ['attendees' => [$answers]] : $answers]);

        return $answers;
    }

    #[Test]
    #[DataProvider('shapes')]
    public function payloads_supply_current_options_and_lossless_multi_choices_without_changing_values(bool $repeatable): void
    {
        $answers = $this->answers($repeatable);
        $options = $this->form->schema['sections'][0]['fields'][0]['options'];
        $list = $this->getJson($this->url)->assertOk();
        $roster = $this->getJson($this->url.'/roster')->assertOk();

        foreach ([0, 1, 2] as $index) {
            $this->assertSame($options, $list->json("meta.columns.{$index}.options"));
            $this->assertSame($options, $roster->json("meta.columns.{$index}.options"));
        }
        $this->assertSame([
            'role' => 'settingUp', 'meal' => 'chicken', 'extras' => 'chicken, rice,beans, removedOption, Chicken',
        ], $roster->json('data.data.0.values'));
        $this->assertSame(['extras' => $answers['extras']], $roster->json('data.data.0.choice_values'));
        $detail = $this->getJson($this->url.'/'.$this->response->id)->assertOk();
        $this->assertSame($repeatable ? ['attendees' => [$answers]] : $answers, $detail->json('data.data'));
    }

    #[Test]
    #[DataProvider('shapes')]
    public function both_csv_exports_keep_stored_choice_values(bool $repeatable): void
    {
        $this->answers($repeatable);
        foreach (['/export', '/roster/export'] as $suffix) {
            $csv = $this->getJson($this->url.$suffix)->assertOk()->streamedContent();
            $this->assertStringContainsString('settingUp', $csv);
            $this->assertStringContainsString('chicken', $csv);
            $this->assertStringContainsString('chicken, rice,beans, removedOption, Chicken', $csv);
            $this->assertStringNotContainsString('Setting up', $csv);
            $this->assertStringNotContainsString('Rice and beans', $csv);
        }
    }

    private function sortableAnswers(bool $repeatable): void
    {
        $schema = $this->form->schema;
        $schema['sections'][0]['repeatable'] = $repeatable;
        foreach ($schema['sections'][0]['fields'] as &$field) {
            $field['options'] = [
                ['value' => '1', 'label' => 'Zulu'],
                ['value' => '2', 'label' => 'Alpha'],
                ['value' => 'rice,beans', 'label' => 'Rice and beans'],
            ];
        }
        unset($field);
        $this->form->update(['schema' => $schema]);
        $this->response->delete();
        foreach (['1', '2', 'removed'] as $index => $value) {
            $answers = ['role' => $value, 'meal' => $value,
                'extras' => $value === '2' ? ['2', 'rice,beans'] : [$value]];
            FormResponse::create([
                'form_id' => $this->form->id, 'masjid_id' => $this->form->masjid_id,
                'data' => $repeatable ? ['attendees' => [$answers]] : $answers,
                'entry_count' => 1, 'amount_due' => 0, 'status' => 'confirmed',
                'submitted_at' => '2026-10-06 12:0'.$index.':00',
            ]);
        }
    }

    public static function choiceSorts(): array
    {
        $cases = [];
        foreach ([false, true] as $repeatable) {
            foreach (['role', 'meal', 'extras'] as $key) {
                foreach (['asc', 'desc'] as $direction) {
                    $cases[($repeatable ? 'repeating' : 'flat')." {$key} {$direction}"] = [$repeatable, $key, $direction];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('choiceSorts')]
    public function screen_roster_sorts_displayed_choices_before_pagination(bool $repeatable, string $key, string $direction): void
    {
        $this->sortableAnswers($repeatable);
        $expected = $direction === 'asc' ? ['2', 'removed', '1'] : ['1', 'removed', '2'];
        foreach ($expected as $index => $value) {
            $page = $index + 1;
            $roster = $this->getJson($this->url."/roster?sort={$key}&direction={$direction}&per_page=1&page={$page}")->assertOk();
            $this->assertSame(3, $roster->json('data.total'));
            $this->assertSame($value, $roster->json('data.data.0.values.role'));
        }
    }

    #[Test]
    #[DataProvider('shapes')]
    public function screen_choice_sorting_keeps_zero_labels_and_empty_answers_distinct(bool $repeatable): void
    {
        $this->sortableAnswers($repeatable);
        $schema = $this->form->schema;
        $schema['sections'][0]['fields'][2]['options'][] = ['value' => 'zero', 'label' => '0'];
        $this->form->update(['schema' => $schema]);
        foreach ([['zero'], [], [null, '']] as $extras) {
            $answers = ['role' => 'extra', 'extras' => $extras];
            FormResponse::create([
                'form_id' => $this->form->id, 'masjid_id' => $this->form->masjid_id,
                'data' => $repeatable ? ['attendees' => [$answers]] : $answers,
                'entry_count' => 1, 'status' => 'confirmed', 'submitted_at' => '2026-10-06 12:03:00',
            ]);
        }
        $screen = $this->getJson($this->url.'/roster?sort=extras&direction=asc')->assertOk();
        $choices = array_map(fn ($row) => $row['choice_values']['extras'], $screen->json('data.data'));
        $this->assertSame([['zero'], ['2', 'rice,beans'], ['removed'], ['1']], array_slice($choices, 0, 4));
        // Both empty shapes display the same dash, so their relative order is immaterial.
        $this->assertEqualsCanonicalizing([[], [null, '']], array_slice($choices, 4));
    }

    public static function csvSorts(): array
    {
        return ['flat asc' => [false, 'asc'], 'flat desc' => [false, 'desc'],
            'repeating asc' => [true, 'asc'], 'repeating desc' => [true, 'desc']];
    }

    #[Test]
    #[DataProvider('csvSorts')]
    public function both_csv_exports_keep_exact_bytes_and_stored_value_order(bool $repeatable, string $direction): void
    {
        $this->sortableAnswers($repeatable);
        $lines = [
            '1' => ['"2026-10-06 12:00",confirmed,1,0.00,1,1,1', '1,1,1,,,,confirmed,"2026-10-06 12:00"'],
            '2' => ['"2026-10-06 12:01",confirmed,1,0.00,2,2,"2, rice,beans"', '2,2,"2, rice,beans",,,,confirmed,"2026-10-06 12:01"'],
            'removed' => ['"2026-10-06 12:02",confirmed,1,0.00,removed,removed,removed', 'removed,removed,removed,,,,confirmed,"2026-10-06 12:02"'],
        ];
        $listOrder = $direction === 'asc' ? ['1', '2', 'removed'] : ['removed', '2', '1'];
        $listCsv = "Submitted,Status,Entries,\"Amount due\",Role,Meal,Extras\n";
        foreach ($listOrder as $value) {
            $listCsv .= $lines[$value][0]."\n";
        }
        $rosterCsv = "Role,Meal,Extras,\"Registered by\",\"Registrant email\",\"Registrant phone\",Status,Submitted\n";
        foreach ($listOrder as $value) {
            $rosterCsv .= $lines[$value][1]."\n";
        }
        $this->assertSame($listCsv, $this->getJson($this->url."/export?sort=submitted_at&direction={$direction}")->assertOk()->streamedContent());
        $this->assertSame($rosterCsv, $this->getJson($this->url."/roster/export?sort=extras&direction={$direction}")->assertOk()->streamedContent());
    }

    public static function screens(): array
    {
        return ['responses' => [''], 'roster' => ['/roster']];
    }

    #[Test]
    #[DataProvider('screens')]
    public function twenty_calendar_columns_resolve_once_and_refresh_on_the_next_request(string $suffix): void
    {
        $fields = [];
        for ($index = 0; $index < 20; $index++) {
            $fields[] = ['name' => 'day'.$index, 'label' => 'Day '.$index,
                'type' => 'select', 'optionsSource' => FormOptionSources::SCHOOL_MEETING_DAYS];
        }
        $this->form->update(['schema' => ['sections' => [['id' => 'days', 'fields' => $fields]]]]);
        $year = SchoolYear::create([
            'masjid_id' => $this->form->masjid_id, 'label' => 'Our Sundays',
            'first_day' => '2026-10-11', 'last_day' => '2026-10-18',
        ]);
        for ($request = 0; $request < 2; $request++) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $screen = $this->getJson($this->url.$suffix)->assertOk();
            $queries = collect(DB::getQueryLog());
            DB::disableQueryLog();
            $counts = [
                'years' => $queries->filter(fn ($q) => str_contains($q['query'], 'from "school_years"'))->count(),
                'closures' => $queries->filter(fn ($q) => str_contains($q['query'], 'from "school_closures"'))->count(),
                // One organisation read for the endpoint and one for the source.
                'organisations' => $queries->filter(fn ($q) => str_contains($q['query'], 'from "masjids"'))->count(),
            ];
            $this->assertSame(['years' => 1, 'closures' => 1, 'organisations' => 2], $counts);
            $options = $screen->json('meta.columns.0.options');
            $this->assertSame(['2026-10-11', '2026-10-18'], array_column($options, 'value'));
            foreach (range(1, 19) as $index) {
                $this->assertSame($options, $screen->json("meta.columns.{$index}.options"));
            }
            $this->assertSame($request ? 'No school — Newly closed' : null, $options[0]['detail'] ?? null);
            if ($request === 0) {
                SchoolClosure::create(['masjid_id' => $this->form->masjid_id, 'school_year_id' => $year->id,
                    'closed_on' => '2026-10-11', 'reason' => 'Newly closed']);
            }
        }

        // The same source in another organisation must read that organisation's calendar.
        $other = Masjid::create([
            'name' => 'Other organisation', 'email' => 'other@example.test', 'phone' => '+15555550125',
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St', 'latitude' => 0, 'longitude' => 0,
        ]);
        app(TenantContext::class)->runWithout(fn () => SchoolYear::create([
            'masjid_id' => $other->id, 'label' => 'Their Saturdays',
            'first_day' => '2026-10-10', 'last_day' => '2026-10-17',
        ]));
        $otherForm = Form::create(['masjid_id' => $other->id, 'slug' => 'other-days', 'name' => 'Other days',
            'schema' => $this->form->schema, 'settings' => []]);
        $otherScreen = $this->getJson("/api/admin/masjids/{$other->id}/forms/{$otherForm->id}/responses".$suffix)->assertOk();
        $this->assertSame(['2026-10-10', '2026-10-17'], array_column($otherScreen->json('meta.columns.0.options'), 'value'));
    }
}
