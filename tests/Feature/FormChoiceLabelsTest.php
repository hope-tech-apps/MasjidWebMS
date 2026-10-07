<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
