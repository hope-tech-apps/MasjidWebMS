<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prices by answer (settings.fee.byChoice) set and changed through the office's own door, the
 * form builder, with no import (2026-10-08). Until then only `form:import` wrote them and the
 * builder sent them back untouched, so nothing proved that door takes a NEW or a CHANGED set.
 *
 * These are the documents the builder sends (the whole form, the price rows in the order of the
 * question's choices), and the keys of the refusals it shows beside the question and beside
 * each price: `settings.fee.byChoice.field`, `settings.fee.byChoice.prices` and
 * `settings.fee.byChoice.prices.{i}.amount`. A refusal renamed here would no longer show
 * beside its box (it would still be listed above the Save button).
 *
 * The documents are written out here by hand. That the builder sends these shapes is proved
 * on its side, by resources/vue-app/tests/form-choice-pricing.test.ts.
 */
class FormChoicePricesByBuilderTest extends TestCase
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
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    #[Test]
    public function the_builder_creates_a_form_priced_by_the_answer_to_one_question(): void
    {
        $this->postJson($this->url(), $this->doc())->assertStatus(201);

        $form = $this->stored();

        $this->assertEquals(150, $form->priceFor(['paymentChoice' => 'oneChildMonth'])['unit']);
        $this->assertEquals(425, $form->priceFor(['paymentChoice' => 'oneChildSemester'])['unit']);
        $this->assertEquals(800, $form->priceFor(['paymentChoice' => 'twoChildrenSemester'])['unit']);
        $this->assertNull($form->priceFor([]), 'no answer, no price');
    }

    #[Test]
    public function a_changed_price_a_reworded_choice_a_new_choice_and_a_removed_one_are_what_the_next_registrant_meets(): void
    {
        $this->postJson($this->url(), $this->doc())->assertStatus(201);
        $form = $this->stored();

        $doc = $this->doc();
        // Reworded, its stored value kept; a new choice at the end; the first choice removed.
        $doc['schema']['sections'][0]['fields'][0]['options'] = [
            ['value' => 'oneChildSemester', 'label' => '1 child: One Semester (3 months)'],
            ['value' => 'twoChildrenSemester', 'label' => '2 children: One Semester (3 months)'],
            ['value' => 'threeChildrenSemester', 'label' => '3 children: One Semester (3 months)'],
        ];
        $doc['settings']['fee']['byChoice']['prices'] = [
            ['value' => 'oneChildSemester', 'amount' => 450],
            ['value' => 'twoChildrenSemester', 'amount' => 800],
            ['value' => 'threeChildrenSemester', 'amount' => 1175],
        ];

        $this->putJson("{$this->url()}/{$form->id}", $doc)->assertOk();

        $form = $form->fresh();
        $price = $form->priceFor(['paymentChoice' => 'oneChildSemester']);

        $this->assertEquals(450, $price['unit']);
        $this->assertSame('1 child: One Semester (3 months)', $price['label']);
        $this->assertEquals(1175, $form->priceFor(['paymentChoice' => 'threeChildrenSemester'])['unit']);
        $this->assertNull($form->priceFor(['paymentChoice' => 'oneChildMonth']), 'a removed choice no longer has a price');
    }

    #[Test]
    public function a_form_priced_another_way_is_switched_to_prices_by_answer(): void
    {
        $flat = $this->doc();
        $flat['settings']['fee'] = ['currency' => 'USD', 'amount' => 80];
        $this->postJson($this->url(), $flat)->assertStatus(201);
        $form = $this->stored();

        $this->putJson("{$this->url()}/{$form->id}", $this->doc())->assertOk();

        $this->assertEquals(425, $form->fresh()->priceFor(['paymentChoice' => 'oneChildSemester'])['unit']);
    }

    #[Test]
    public function a_choice_with_no_price_row_is_refused_under_the_key_the_builder_shows_under_the_table(): void
    {
        $this->postJson($this->url(), $this->doc())->assertStatus(201);
        $form = $this->stored();

        $doc = $this->doc();
        array_pop($doc['settings']['fee']['byChoice']['prices']);

        $refused = $this->putJson("{$this->url()}/{$form->id}", $doc)->assertStatus(422)->json('data');

        $this->assertArrayHasKey('settings.fee.byChoice.prices', $refused);
        $this->assertEquals(800, $form->fresh()->priceFor(['paymentChoice' => 'twoChildrenSemester'])['unit'], 'a refused save changes nothing');
    }

    #[Test]
    public function an_empty_price_box_is_refused_beside_that_price(): void
    {
        $this->postJson($this->url(), $this->doc())->assertStatus(201);
        $form = $this->stored();

        $doc = $this->doc();
        $doc['settings']['fee']['byChoice']['prices'][1]['amount'] = null;

        $refused = $this->putJson("{$this->url()}/{$form->id}", $doc)->assertStatus(422)->json('data');

        $this->assertArrayHasKey('settings.fee.byChoice.prices.1.amount', $refused);
        $this->assertEquals(425, $form->fresh()->priceFor(['paymentChoice' => 'oneChildSemester'])['unit']);
    }

    #[Test]
    public function prices_sent_with_no_question_chosen_are_refused_and_never_save_the_form_as_free(): void
    {
        $doc = $this->doc();
        // What the builder sends when the pricing is chosen and nothing else is: see buildFee().
        $doc['settings']['fee']['byChoice'] = ['field' => '', 'prices' => []];

        $refused = $this->postJson($this->url(), $doc)->assertStatus(422)->json('data');

        $this->assertNotSame([], array_filter(array_keys($refused), fn ($key) => str_starts_with($key, 'settings.fee.byChoice')), 'named under the keys the price block shows');
        $this->assertNull($this->stored());
    }

    #[Test]
    public function a_question_that_is_not_a_dropdown_or_choose_one_cannot_set_the_price(): void
    {
        $doc = $this->doc();
        $doc['schema']['sections'][0]['fields'][0]['type'] = 'text';
        unset($doc['schema']['sections'][0]['fields'][0]['options']);

        $refused = $this->postJson($this->url(), $doc)->assertStatus(422)->json('data');

        $this->assertArrayHasKey('settings.fee.byChoice.field', $refused);
    }

    #[Test]
    public function a_price_over_a_million_and_a_twenty_first_price_are_refused_under_the_keys_of_the_price_block(): void
    {
        $doc = $this->doc();
        $doc['settings']['fee']['byChoice']['prices'][0]['amount'] = 1000001;

        $refused = $this->postJson($this->url(), $doc)->assertStatus(422)->json('data');
        $this->assertArrayHasKey('settings.fee.byChoice.prices.0.amount', $refused);

        $doc = $this->doc();
        $doc['schema']['sections'][0]['fields'][0]['options'] = array_map(fn ($i) => ['value' => "v{$i}", 'label' => "Choice {$i}"], range(1, 21));
        $doc['settings']['fee']['byChoice']['prices'] = array_map(fn ($i) => ['value' => "v{$i}", 'amount' => 10], range(1, 21));

        $refused = $this->postJson($this->url(), $doc)->assertStatus(422)->json('data');
        $this->assertArrayHasKey('settings.fee.byChoice.prices', $refused);
    }

    #[Test]
    public function staff_codes_are_refused_beside_their_switch_on_a_form_priced_by_answer(): void
    {
        $doc = $this->doc();
        $doc['settings']['payment'] = ['staffCodes' => true];

        $refused = $this->postJson($this->url(), $doc)->assertStatus(422)->json('data');

        $this->assertArrayHasKey('settings.payment.staffCodes', $refused);
    }

    #[Test]
    public function a_question_that_may_be_left_blank_cannot_set_the_price(): void
    {
        $doc = $this->doc();
        $doc['schema']['sections'][0]['fields'][0]['required'] = false;

        $refused = $this->postJson($this->url(), $doc)->assertStatus(422)->json('data');

        $this->assertArrayHasKey('settings.fee.byChoice.field', $refused);
    }

    private function url(): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/forms";
    }

    private function stored(): ?Form
    {
        return Form::where('masjid_id', $this->masjid->id)->where('slug', 'afterschool-registration')->first();
    }

    /** A registration priced by one required dropdown, as the builder sends it. */
    private function doc(): array
    {
        return [
            'name' => 'Afterschool registration',
            'slug' => 'afterschool-registration',
            'is_active' => true,
            'schema' => ['sections' => [[
                'id' => 'payment',
                'title' => 'Payment',
                'fields' => [[
                    'name' => 'paymentChoice',
                    'label' => 'Children and payment',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => 'oneChildMonth', 'label' => '1 child: first month'],
                        ['value' => 'oneChildSemester', 'label' => '1 child: One Semester'],
                        ['value' => 'twoChildrenSemester', 'label' => '2 children: One Semester'],
                    ],
                ]],
            ]]],
            'settings' => [
                'fee' => [
                    'currency' => 'USD',
                    'byChoice' => ['field' => 'paymentChoice', 'prices' => [
                        ['value' => 'oneChildMonth', 'amount' => 150],
                        ['value' => 'oneChildSemester', 'amount' => 425],
                        ['value' => 'twoChildrenSemester', 'amount' => 800],
                    ]],
                ],
            ],
        ];
    }
}
