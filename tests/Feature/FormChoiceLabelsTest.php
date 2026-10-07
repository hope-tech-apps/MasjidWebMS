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
    public function both_csv_exports_print_choice_wording_and_keep_removed_values(bool $repeatable): void
    {
        $this->answers($repeatable);
        foreach (['/export', '/roster/export'] as $suffix) {
            $csv = $this->getJson($this->url.$suffix)->assertOk()->streamedContent();
            $this->assertStringContainsString('Setting up', $csv);
            $this->assertStringContainsString('Chicken', $csv);
            $this->assertStringContainsString('Chicken, Rice and beans, removedOption, Chicken', $csv);
            $this->assertStringNotContainsString('settingUp', $csv);
            $this->assertStringNotContainsString('rice,beans', $csv);
        }
    }

    #[Test]
    #[DataProvider('unsafeLabels')]
    public function csv_labels_keep_formula_guards_and_repeating_separators(bool $repeatable, string $prefix): void
    {
        $this->answers($repeatable);
        $schema = $this->form->schema;
        foreach ($schema['sections'][0]['fields'] as &$field) {
            $field['options'] = [['value' => 'safe', 'label' => $prefix.'2+2']];
        }
        unset($field);
        $this->form->update(['schema' => $schema]);
        $answers = ['role' => 'safe', 'meal' => 'safe', 'extras' => ['safe', 'gone']];
        $this->response->update(['data' => $repeatable ? ['attendees' => [$answers, $answers]] : $answers]);
        $csv = $this->getJson($this->url.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($repeatable ? "'{$prefix}2+2 | {$prefix}2+2" : "'{$prefix}2+2", $csv);
        $this->assertStringContainsString($repeatable ? "'{$prefix}2+2, gone | {$prefix}2+2, gone" : "'{$prefix}2+2, gone", $csv);
        $roster = $this->getJson($this->url.'/roster/export')->assertOk()->streamedContent();
        $lines = fopen('php://memory', 'r+');
        fwrite($lines, $roster);
        rewind($lines);
        fgetcsv($lines);
        $line = fgetcsv($lines);
        fclose($lines);
        $this->assertSame(["'{$prefix}2+2", "'{$prefix}2+2", "'{$prefix}2+2, gone"], array_slice($line, 0, 3));
    }

    #[Test]
    public function csv_choices_keep_empty_answers_empty_and_zero_wording(): void
    {
        $schema = $this->form->schema;
        foreach ($schema['sections'][0]['fields'] as &$field) {
            $field['options'] = [['value' => '', 'label' => 'Unexpected'], ['value' => 'zero', 'label' => '0']];
        }
        unset($field);
        $this->form->update(['schema' => $schema]);
        $this->response->update(['data' => ['role' => '', 'meal' => null, 'extras' => ['', null, 'zero']]]);
        foreach (['/export' => 4, '/roster/export' => 0] as $suffix => $offset) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $this->getJson($this->url.$suffix)->assertOk()->streamedContent());
            rewind($stream);
            fgetcsv($stream);
            $cells = fgetcsv($stream);
            fclose($stream);
            $this->assertSame(['', '', ', , 0'], array_slice($cells, $offset, 3));
        }
    }

    public static function unsafeLabels(): array
    {
        $cases = [];
        foreach ([false, true] as $repeatable) {
            foreach (['=', '+', '-', '@', "\t", "\r"] as $index => $prefix) {
                $cases[($repeatable ? 'repeating' : 'flat').' prefix '.$index] = [$repeatable, $prefix];
            }
        }
        return $cases;
    }

    #[Test]
    public function sourced_choice_labels_are_searchable_on_save_and_after_rebuilding(): void
    {
        $schema = $this->form->schema;
        foreach ($schema['sections'][0]['fields'] as &$field) {
            unset($field['options']);
            $field['optionsSource'] = FormOptionSources::SCHOOL_MEETING_DAYS;
        }
        unset($field);
        $this->form->update(['schema' => $schema]);
        SchoolYear::create(['masjid_id' => $this->form->masjid_id, 'label' => 'Test year',
            'first_day' => '2026-10-11', 'last_day' => '2026-10-18']);
        $this->response->update(['data' => ['role' => '2026-10-11', 'meal' => '2026-10-11', 'extras' => ['2026-10-11']]]);
        $this->assertSame(3, substr_count($this->response->answers_text, ' sunday october 11 2026'));
        $search = $this->url.'?q=Sunday%20October';
        $this->assertSame(1, $this->getJson($search)->assertOk()->json('data.total'));
        DB::table('form_responses')->where('id', $this->response->id)->update(['answers_text' => ' 2026 10 11']);
        $this->assertSame(0, $this->getJson($search)->assertOk()->json('data.total'));
        $this->artisan('forms:rebuild-answers-text', ['--form' => $this->form->id, '--all' => true])->assertSuccessful();
        $this->assertSame(1, $this->getJson($search)->assertOk()->json('data.total'));
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
            '1' => ['"2026-10-06 12:00",confirmed,1,0.00,Zulu,Zulu,Zulu', 'Zulu,Zulu,Zulu,,,,confirmed,"2026-10-06 12:00"'],
            '2' => ['"2026-10-06 12:01",confirmed,1,0.00,Alpha,Alpha,"Alpha, Rice and beans"', 'Alpha,Alpha,"Alpha, Rice and beans",,,,confirmed,"2026-10-06 12:01"'],
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

    public static function backslashQuoteCells(): array
    {
        $cases = [];
        foreach ([false, true] as $repeatable) {
            foreach (['/export', '/roster/export'] as $suffix) {
                foreach (['label', 'answer', 'header'] as $source) {
                    $cases[($repeatable ? 'repeating' : 'flat')." {$suffix} {$source}"] = [$repeatable, $suffix, $source];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('backslashQuoteCells')]
    public function both_csv_exports_double_quotes_after_backslashes(bool $repeatable, string $suffix, string $source): void
    {
        $value = 'safe\\",=1+1,tail';
        $cell = '"safe\\"",=1+1,tail"';
        $schema = $this->form->schema;
        $schema['sections'][0]['repeatable'] = $repeatable;
        foreach ($schema['sections'][0]['fields'] as &$field) {
            $field['label'] = $source === 'header' ? $value : $field['label'];
            $field['type'] = $source === 'answer' ? 'text' : $field['type'];
            $field['options'] = [['value' => 'safe', 'label' => $source === 'label' ? $value : 'Ordinary']];
        }
        unset($field);
        $this->form->update(['schema' => $schema]);
        $answer = $source === 'answer' ? $value : 'safe';
        $answers = ['role' => $answer, 'meal' => $answer, 'extras' => $source === 'answer' ? $answer : [$answer]];
        $this->response->update(['data' => $repeatable ? ['attendees' => [$answers]] : $answers,
            'submitted_at' => '2026-10-06 12:00:00', 'amount_due' => 0]);
        $headers = $source === 'header' ? "{$cell},{$cell},{$cell}" : 'Role,Meal,Extras';
        $cells = $source === 'header' ? 'Ordinary,Ordinary,Ordinary' : "{$cell},{$cell},{$cell}";
        $expected = $suffix === '/export'
            ? "Submitted,Status,Entries,\"Amount due\",{$headers}\n\"2026-10-06 12:00\",confirmed,1,0.00,{$cells}\n"
            : "{$headers},\"Registered by\",\"Registrant email\",\"Registrant phone\",Status,Submitted\n{$cells},,,,confirmed,\"2026-10-06 12:00\"\n";
        $csv = $this->getJson($this->url.$suffix)->assertOk()->streamedContent();
        $this->assertSame($expected, $csv);

        // Read as a spreadsheet does: doubled quotes, with no backslash escape.
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        $row = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);
        $this->assertCount($suffix === '/export' ? 7 : 8, $header);
        $this->assertCount(count($header), $row);
        $offset = $suffix === '/export' ? 4 : 0;
        $this->assertSame(array_fill(0, 3, $value), array_slice($source === 'header' ? $header : $row, $offset, 3));
    }

    public static function unusualChoiceValues(): array
    {
        $cases = [];
        foreach ([false, true] as $repeatable) {
            foreach (['digest' => '0123456789abcdef0123456789abcdef',
                'provider' => 'pi_3Qa123456789012345', 'spaces' => ' x '] as $kind => $value) {
                $cases[($repeatable ? 'repeating' : 'flat').' '.$kind] = [$repeatable, $value];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('unusualChoiceValues')]
    public function unusual_choice_labels_are_searchable_on_save_and_after_rebuilding(bool $repeatable, string $value): void
    {
        $schema = $this->form->schema;
        $schema['sections'][0]['repeatable'] = $repeatable;
        foreach ($schema['sections'][0]['fields'] as &$field) {
            $field['options'] = [['value' => 'x', 'label' => 'Alpha'], ['value' => $value, 'label' => 'Lunch helper']];
        }
        unset($field);
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['schema' => $schema],
            ['schema' => [new \App\Rules\ValidFormSchema]])->passes());
        $this->form->update(['schema' => $schema]);
        $answers = ['role' => $value, 'meal' => $value, 'extras' => [$value]];
        $data = $repeatable ? ['attendees' => [$answers]] : $answers;
        $this->response->update(['data' => $data]);

        foreach (['save', 'rebuild'] as $stage) {
            if ($stage === 'rebuild') {
                DB::table('form_responses')->where('id', $this->response->id)->update(['answers_text' => ' stale']);
                $this->artisan('forms:rebuild-answers-text', ['--form' => $this->form->id, '--all' => true])->assertSuccessful();
            }
            $text = $this->response->fresh()->answers_text;
            $this->assertSame(3, substr_count($text, ' lunch helper'), $stage);
            $this->assertStringNotContainsString(' alpha', $text);
            $this->assertSame($value === ' x ' ? ' x lunch helper x lunch helper x lunch helper'
                : ' lunch helper lunch helper lunch helper', $text);
            $this->assertSame(1, $this->getJson($this->url.'?q=Lunch%20helper')->assertOk()->json('data.total'));
            $this->assertSame(0, $this->getJson($this->url.'?q=Alpha')->assertOk()->json('data.total'));
            $this->assertSame($data, $this->response->fresh()->data);
        }
        foreach (['/export', '/roster/export'] as $suffix) {
            $csv = $this->getJson($this->url.$suffix)->assertOk()->streamedContent();
            $this->assertStringContainsString('"Lunch helper","Lunch helper","Lunch helper"', $csv);
            $this->assertStringNotContainsString('Alpha', $csv);
        }
    }

    public static function screens(): array
    {
        return ['responses' => [''], 'roster' => ['/roster'], 'responses CSV' => ['/export'], 'roster CSV' => ['/roster/export']];
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
        DB::table('form_responses')->where('id', $this->response->id)->update([
            'data' => json_encode(array_fill_keys(array_column($fields, 'name'), '2026-10-11')),
        ]);
        DB::table('form_responses')->insert([
            'form_id' => $this->form->id, 'masjid_id' => $this->form->masjid_id,
            'uuid' => '00000000-0000-4000-8000-000000000002',
            'data' => json_encode(array_fill_keys(array_column($fields, 'name'), '2026-10-11')),
            'entry_count' => 1, 'status' => 'confirmed', 'submitted_at' => '2026-10-06 12:00:00',
        ]);
        $year = SchoolYear::create([
            'masjid_id' => $this->form->masjid_id, 'label' => 'Our Sundays',
            'first_day' => '2026-10-11', 'last_day' => '2026-10-18',
        ]);
        for ($request = 0; $request < 2; $request++) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $screen = $this->getJson($this->url.$suffix)->assertOk();
            if (str_ends_with($suffix, '/export')) {
                $this->assertStringContainsString('Oct', $screen->streamedContent());
            }
            $queries = collect(DB::getQueryLog());
            DB::disableQueryLog();
            $counts = [
                'years' => $queries->filter(fn ($q) => str_contains($q['query'], 'from "school_years"'))->count(),
                'closures' => $queries->filter(fn ($q) => str_contains($q['query'], 'from "school_closures"'))->count(),
                // One organisation read for the endpoint and one for the source.
                'organisations' => $queries->filter(fn ($q) => str_contains($q['query'], 'from "masjids"'))->count(),
            ];
            $this->assertSame(['years' => 1, 'closures' => 1, 'organisations' => 2], $counts);
            $options = str_ends_with($suffix, '/export')
                ? $this->getJson($this->url)->assertOk()->json('meta.columns.0.options')
                : $screen->json('meta.columns.0.options');
            $this->assertSame(['2026-10-11', '2026-10-18'], array_column($options, 'value'));
            foreach (range(1, 19) as $index) {
                if (! str_ends_with($suffix, '/export')) {
                    $this->assertSame($options, $screen->json("meta.columns.{$index}.options"));
                }
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
        if (str_ends_with($suffix, '/export')) {
            return;
        }
        $otherScreen = $this->getJson("/api/admin/masjids/{$other->id}/forms/{$otherForm->id}/responses".$suffix)->assertOk();
        $this->assertSame(['2026-10-10', '2026-10-17'], array_column($otherScreen->json('meta.columns.0.options'), 'value'));
    }
}
