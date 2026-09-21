<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A choice whose value contains a comma must be answerable.
 *
 * Select and radio answers were checked with the STRING rule
 * 'in:' . implode(',', $values). Laravel splits that string on commas, so the
 * option "Yes, reach out to me" was checked as the two values "Yes" and
 * " reach out to me": picking it verbatim — the only thing the form can send —
 * was refused with "The selected … is invalid", and a crafted "Yes" was
 * ACCEPTED as if it were one of the options. Confirmed on production on
 * 2026-09-21 with MEC's Fall Festival feedback survey, whose own options carry
 * commas. The admin builder and ValidFormSchema accept such values, so a form
 * could be saved with a choice nobody can pick.
 *
 * checkboxGroup was never affected: memberRules() already used Rule::in.
 */
class FormChoiceCommaValueTest extends TestCase
{
    use RefreshDatabase;

    private const RADIO_COMMA = 'Yes, reach out to me';
    private const SELECT_COMMA = 'Adult, 18 or older';

    private Masjid $masjid;
    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masjid = Masjid::create([
            'name' => 'Choice Test ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->form = Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'choices-' . uniqid(),
            'name' => 'Choices with commas',
            'schema' => [
                'sections' => [[
                    'id' => 'about',
                    'title' => 'About you',
                    'fields' => [
                        ['name' => 'involved', 'label' => 'Would you like to get involved?', 'type' => 'radio', 'required' => true,
                         'options' => [
                             ['label' => 'Already involved', 'value' => 'Already involved'],
                             ['label' => self::RADIO_COMMA, 'value' => self::RADIO_COMMA],
                             ['label' => 'No', 'value' => 'No'],
                         ]],
                        ['name' => 'ageGroup', 'label' => 'Age group', 'type' => 'select', 'required' => true,
                         'options' => [
                             ['label' => self::SELECT_COMMA, 'value' => self::SELECT_COMMA],
                             ['label' => 'Child', 'value' => 'Child'],
                         ]],
                    ],
                ]],
            ],
            'is_active' => true,
        ]);
    }

    private function submit(array $data)
    {
        return $this->postJson(
            "/api/v1/forms/{$this->form->id}/responses",
            ['data' => $data],
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    #[Test]
    public function a_radio_option_whose_value_contains_a_comma_is_accepted_verbatim(): void
    {
        $this->submit(['involved' => self::RADIO_COMMA, 'ageGroup' => 'Child'])->assertOk();

        $stored = FormResponse::where('form_id', $this->form->id)->sole();
        $this->assertSame(self::RADIO_COMMA, $stored->data['involved'], 'the answer is stored exactly as chosen');
    }

    #[Test]
    public function a_select_option_whose_value_contains_a_comma_is_accepted_verbatim(): void
    {
        $this->submit(['involved' => 'No', 'ageGroup' => self::SELECT_COMMA])->assertOk();

        $stored = FormResponse::where('form_id', $this->form->id)->sole();
        $this->assertSame(self::SELECT_COMMA, $stored->data['ageGroup'], 'the answer is stored exactly as chosen');
    }

    #[Test]
    public function a_value_that_is_not_one_of_the_options_is_still_refused(): void
    {
        $this->submit(['involved' => 'Maybe later', 'ageGroup' => 'Grandparent'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['involved', 'ageGroup']]);

        $this->assertSame(0, FormResponse::where('form_id', $this->form->id)->count(), 'nothing was recorded');
    }

    #[Test]
    public function a_fragment_of_a_comma_option_is_not_accepted_as_an_option(): void
    {
        // The other face of the same bug: the comma split turned "Yes" and
        // " reach out to me" into options the admin never offered.
        foreach (['Yes', ' reach out to me', 'reach out to me'] as $fragment) {
            $this->submit(['involved' => $fragment, 'ageGroup' => 'Child'])
                ->assertStatus(422)
                ->assertJsonStructure(['data' => ['involved']]);
        }
        foreach (['Adult', ' 18 or older'] as $fragment) {
            $this->submit(['involved' => 'No', 'ageGroup' => $fragment])
                ->assertStatus(422)
                ->assertJsonStructure(['data' => ['ageGroup']]);
        }

        $this->assertSame(0, FormResponse::where('form_id', $this->form->id)->count(), 'no fragment was recorded as an answer');
    }
}
