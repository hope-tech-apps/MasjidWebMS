<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\CartItem;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Services\Cart\CartLineAdder;
use App\Services\Cart\Sources\DonationLineSource;
use App\Services\Cart\Sources\FormLineSource;
use App\Services\Cart\Sources\MealLineSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * POST /api/v1/cart/items (brief 5, section 1): each type validated as its own door validates
 * it, priced through the basket's own pricer, and refused with its reason when it would come
 * back `gone`; the idempotency key, the 25-line cap and the honeypot.
 */
class CartAddItemTest extends TestCase
{
    use BuildsBaskets;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-01 12:00 UTC: a catalogue pickup on the 6th is well inside its 48 hour lead.
        $this->t0 = Carbon::parse('2026-10-01 12:00:00', 'UTC');
        Carbon::setTestNow($this->t0);

        $this->armWebhooks();
        $this->turnCartOn();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function lineCount(): int
    {
        return CartItem::withoutMasjidScope()->count();
    }

    private function fileForm(Masjid $org, bool $fileRequired = false): Form
    {
        return $this->ticketForm($org, [
            'name' => 'Volunteer Application',
            'schema' => ['sections' => [[
                'id' => 'main', 'title' => 'Main', 'repeatable' => false,
                'fields' => [
                    ['name' => 'fullName', 'type' => 'text', 'label' => 'Name', 'required' => true],
                    ['name' => 'resume', 'type' => 'file', 'label' => 'Resume', 'required' => $fileRequired],
                ],
            ]]],
            'settings' => [
                'fee' => ['amount' => 20, 'currency' => 'USD'],
                'payment' => ['online' => true, 'officePayment' => false],
            ],
        ]);
    }

    // ---------------------------------------------------------------------- forms

    #[Test]
    public function a_form_line_is_priced_by_the_form_and_stored_as_the_validated_answers(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org);
        $token = $this->startBasket($org);

        $response = $this->addLine($org, $token, $this->twoTicketsBody($form->id, 'press-form-01'))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_minor', 3000)
            ->assertJsonPath('data.lines.0.type', 'form')
            ->assertJsonPath('data.lines.0.label', 'Festival Tickets')
            ->assertJsonPath('data.lines.0.status', 'available')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.unit_minor', 1500);

        $line = CartItem::withoutMasjidScope()->sole();

        $this->assertSame((int) $line->id, $response->json('data.line_id'));
        $this->assertSame(CartItem::TYPE_FORM, $line->buyable_type);
        $this->assertSame((int) $form->id, (int) $line->buyable_id);
        $this->assertSame(CartItem::RECORDED_AS_REGISTRATION, $line->recorded_as);
        $this->assertSame('Festival Tickets', $line->label);
        $this->assertSame(2, (int) $line->quantity, 'the two places the answers ask for');
        $this->assertSame(1500, (int) $line->unit_amount_shown_minor, 'what the form charges now');
        $this->assertSame('usd', $line->currency);
        $this->assertSame((int) $org->id, (int) $line->masjid_id, 'stamped by hand: nothing binds a tenant here');
        $this->assertSame($this->twoTickets(), $line->payload);
        $this->assertSame('press-form-01', $line->client_line_key);
        $this->assertNotNull($line->client_line_hash);
    }

    #[Test]
    public function only_what_the_schema_declares_is_kept(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $form->id,
            'answers' => [
                'tickets' => [
                    ['attendeeName' => 'A', 'phone' => '555-0100', '<img src=x>' => 'x'],
                    ['attendeeName' => 'B'],
                ],
                'injected' => '<script>alert(1)</script>',
            ],
        ])->assertOk();

        $this->assertSame($this->twoTickets(), CartItem::withoutMasjidScope()->sole()->payload, 'FormSchema::only(), exactly as the form door keeps it');
    }

    #[Test]
    public function answers_that_do_not_validate_are_a_422_field_bag_and_nothing_is_written(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org);
        $token = $this->startBasket($org);

        // The form's own validator names the row and the field, as the form door does.
        $missing = $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $form->id,
            'answers' => ['tickets' => [['attendeeName' => 'A'], ['attendeeName' => '']]],
        ])->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertArrayHasKey('tickets.1.attendeeName', $missing->json('data'));

        $none = $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $form->id,
            'answers' => ['tickets' => []],
        ])->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertArrayHasKey('tickets', $none->json('data'), 'the form asks for at least one ticket');

        $absent = $this->addLine($org, $token, ['type' => 'form', 'form_id' => $form->id])
            ->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertArrayHasKey('answers', $absent->json('data'));
        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function a_form_that_asks_for_a_file_is_refused_in_one_sentence(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);

        $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $this->fileForm($org)->id,
            'answers' => ['fullName' => 'A. Applicant'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', FormLineSource::FILE_FORM);

        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function a_form_whose_file_question_is_required_gets_the_same_one_sentence_and_not_a_field_bag(): void
    {
        // The refusal sits AHEAD of validation. With the file question optional, validation passes
        // and FormLineSource says the same sentence at pricing, so that test cannot tell the two
        // apart. With it required, the shopper can never satisfy the validator (a basket carries
        // no files): were the add-level refusal not first, the answer would be a 422 `failed` bag
        // naming `resume`, a question the shopper is unable to answer.
        $org = $this->org();
        $token = $this->startBasket($org);

        $response = $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $this->fileForm($org, fileRequired: true)->id,
            'answers' => ['fullName' => 'A. Applicant'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', FormLineSource::FILE_FORM);

        $this->assertArrayNotHasKey('resume', (array) $response->json('data'), 'not a field bag');
        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function a_form_that_cannot_be_bought_is_refused_with_its_own_reason(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);

        $refusals = [
            'This form is not currently accepting responses.' => $this->ticketForm($org, ['is_active' => false]),
            'This one is not paid for online.' => $this->ticketForm($org, ['settings' => [
                'fee' => ['amount' => 0, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
                'payment' => ['online' => true, 'officePayment' => false],
            ]]),
            'This one has to be paid on its own page.' => $this->ticketForm($org, ['settings' => [
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
                'payment' => ['online' => true, 'officePayment' => false, 'requireFeeCoverage' => true],
            ]]),
        ];

        foreach ($refusals as $reason => $form) {
            $this->addLine($org, $token, $this->twoTicketsBody($form->id))
                ->assertStatus(422)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('message', $reason);
        }

        $this->assertSame(0, $this->lineCount(), 'a line that would come back gone is never kept');
    }

    #[Test]
    public function a_form_line_that_reserves_a_date_is_refused_and_the_date_is_left_to_the_forms_own_page(): void
    {
        // The form door claims a reserved date under the form's lock; a basket settles with no hold,
        // so two shoppers could pay for the same evening. The add endpoint refuses the line because
        // it prices as gone, and says where to book the date.
        $org = $this->org();
        $form = $this->iftarForm($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $form->id,
            'answers' => $this->quarterIftar('2027-02-10'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', FormLineSource::RESERVES_A_DATE);

        $this->assertSame(0, $this->lineCount(), 'a line that would come back gone is never kept');
    }

    #[Test]
    public function a_line_on_the_same_form_that_reserves_no_date_is_still_payable(): void
    {
        // "Individual Iftar" reserves nothing: the door drops a date named beside it, so the
        // line is kept without one and is priced $18 a person like any other.
        $org = $this->org();
        $form = $this->iftarForm($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $form->id,
            'answers' => $this->individualIftar(3) + ['iftar_date' => '2027-02-10'],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_minor', 5400)
            ->assertJsonPath('data.lines.0.status', 'available')
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.lines.0.unit_minor', 1800);

        $line = CartItem::withoutMasjidScope()->sole();

        $this->assertNull($line->payload['iftar_date'] ?? null, 'the unused date is not kept, so nothing on the line reserves one');
        $this->assertSame(1, $this->lineCount());
    }

    #[Test]
    public function a_form_of_another_organisation_is_not_available(): void
    {
        $org = $this->org();
        $other = $this->org();
        $token = $this->startBasket($org);

        $foreign = $this->addLine($org, $token, $this->twoTicketsBody($this->ticketForm($other)->id))
            ->assertStatus(422)->assertJsonPath('status', 'failed');
        $missing = $this->addLine($org, $token, $this->twoTicketsBody(999999))
            ->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertSame(['form_id' => ['This form is not available.']], $foreign->json('data'));
        $this->assertSame($foreign->getContent(), $missing->getContent(), 'another organisation\'s form is a form that does not exist');
        $this->assertSame(0, $this->lineCount());
    }

    // ---------------------------------------------------------------------- meals

    #[Test]
    public function a_catalogue_dish_takes_a_pickup_read_in_the_organisations_timezone(): void
    {
        $org = $this->org();
        $dish = $this->dish($org);
        $token = $this->startBasket($org);

        // "2:00 PM" in America/New_York on 6 October is 18:00 UTC (daylight time).
        $this->addLine($org, $token, $this->dishBody($dish->id, 2, '2026-10-06T14:00'))
            ->assertOk()
            ->assertJsonPath('data.total_minor', 2400)
            ->assertJsonPath('data.lines.0.type', 'meal')
            ->assertJsonPath('data.lines.0.status', 'available');

        $line = CartItem::withoutMasjidScope()->sole();

        $this->assertSame(CartItem::TYPE_MEAL, $line->buyable_type);
        $this->assertSame((int) $dish->id, (int) $line->buyable_id);
        $this->assertSame(CartItem::RECORDED_AS_ORDER_ONLY, $line->recorded_as);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame(1200, (int) $line->unit_amount_shown_minor);
        $this->assertSame(['pickup_at' => '2026-10-06T18:00:00+00:00'], $line->payload, 'an absolute instant: the pricer and settlement read it without a zone');

        // A pickup that carries its own offset is already absolute.
        $this->addLine($org, $token, $this->dishBody($dish->id, 1, '2026-10-06T14:00:00-04:00'))->assertOk();
        $this->assertSame(
            ['pickup_at' => '2026-10-06T18:00:00+00:00'],
            CartItem::withoutMasjidScope()->orderByDesc('id')->first()->payload
        );
    }

    #[Test]
    public function a_catalogue_dish_without_a_readable_pickup_is_a_field_error(): void
    {
        $org = $this->org();
        $dish = $this->dish($org);
        $token = $this->startBasket($org);

        foreach ([null, 'not a date'] as $pickup) {
            $this->addLine($org, $token, $this->dishBody($dish->id, 1, $pickup))
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonPath('data.pickup_at.0', 'Please choose a pickup date and time.');
        }

        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function a_pickup_inside_the_lead_time_is_refused_with_the_kitchens_reason(): void
    {
        $org = $this->org();
        $dish = $this->dish($org);
        $token = $this->startBasket($org);

        // 09:00 in New York on the 2nd is 13:00 UTC: a day and an hour from now, inside the menu's 48 hour lead.
        $this->addLine($org, $token, $this->dishBody($dish->id, 1, '2026-10-02T09:00'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The pickup time you chose is now too soon for the kitchen to prepare this.');

        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function a_dated_menu_takes_no_pickup_and_a_clamped_quantity_is_told_at_once(): void
    {
        $org = $this->org();
        $menu = MealMenu::create([
            'masjid_id' => $org->id, 'title' => 'Friday lunch', 'kind' => MealMenu::KIND_DATED,
            'status' => MealMenu::STATUS_OPEN, 'service_date' => now()->addDays(3)->toDateString(),
            'allow_online_payment' => true,
        ]);
        $plate = MealMenuItem::create([
            'masjid_id' => $org->id, 'meal_menu_id' => $menu->id, 'name' => 'Chicken plate',
            'price_minor' => 1200, 'is_available' => true, 'max_quantity' => 3,
        ]);
        $token = $this->startBasket($org);

        // Five asked for, three allowed (the Friday-lunch door clamps): kept, and never silent.
        $response = $this->addLine($org, $token, $this->dishBody($plate->id, 5, '2026-10-06T14:00'))
            ->assertOk()
            ->assertJsonPath('data.lines.0.status', 'repriced')
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.total_minor', 3600);

        $this->assertStringContainsString('reduced to 3', (string) $response->json('data.lines.0.reason'));
        $this->assertStringContainsString('reduced to 3', (string) $response->json('data.notices.0.reason'), 'and it is in the notices too');

        $line = CartItem::withoutMasjidScope()->sole();
        $this->assertSame([], $line->payload, 'a dated menu has one service date: the pickup sent is not kept');
        $this->assertSame(5, (int) $line->quantity, 'what was asked for; acknowledge is what brings it to the cap');
    }

    #[Test]
    public function a_dishes_quantity_is_bound_to_the_doors_range(): void
    {
        $org = $this->org();
        $dish = $this->dish($org);
        $token = $this->startBasket($org);

        foreach ([0, -1, 100] as $bad) {
            $this->addLine($org, $token, $this->dishBody($dish->id, $bad, '2026-10-06T14:00'))
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed');
        }

        $this->assertSame(0, $this->lineCount());

        $this->addLine($org, $token, $this->dishBody($dish->id, 99, '2026-10-06T14:00'))->assertOk();
        $this->addLine($org, $token, $this->dishBody($dish->id, 1, '2026-10-06T14:00'))->assertOk();
    }

    #[Test]
    public function a_dish_of_another_organisation_is_not_available(): void
    {
        $org = $this->org();
        $other = $this->org();
        $token = $this->startBasket($org);

        $this->addLine($org, $token, $this->dishBody($this->dish($other)->id, 1, '2026-10-06T14:00'))
            ->assertStatus(422)
            ->assertJsonPath('data.item_id.0', 'This item is not available.');

        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function an_organisation_without_the_lunch_capability_cannot_add_a_dish(): void
    {
        $org = $this->org();
        $dish = $this->dish($org);
        $token = $this->startBasket($org);
        $org->forceFill(['capability_overrides' => ['jummah_lunch' => false]])->save();

        $this->addLine($org, $token, $this->dishBody($dish->id, 1, '2026-10-06T14:00'))
            ->assertStatus(422)
            ->assertJsonPath('message', MealLineSource::ORDERING_OFF);

        $this->assertSame(0, $this->lineCount());
    }

    // ------------------------------------------------------------------ donations

    #[Test]
    public function a_gift_is_stored_at_the_amount_the_giver_set_with_a_zakat_answer_only_when_given(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, $this->giftBody($fund->id, 5000))
            ->assertOk()
            ->assertJsonPath('data.total_minor', 5000)
            ->assertJsonPath('data.lines.0.type', 'donation')
            ->assertJsonPath('data.lines.0.quantity', 1)
            ->assertJsonPath('data.lines.0.unit_minor', 5000);

        $this->addLine($org, $token, $this->giftBody($fund->id, 2500) + ['zakat' => true])->assertOk();
        // A form-encoded checkbox arrives as a string (.claude/rules/shipping.md).
        $this->addLine($org, $token, $this->giftBody($fund->id, 1000) + ['zakat' => 'false'])->assertOk();

        [$plain, $zakat, $notZakat] = CartItem::withoutMasjidScope()->orderBy('id')->get()->all();

        $this->assertSame(CartItem::TYPE_DONATION, $plain->buyable_type);
        $this->assertSame(CartItem::RECORDED_AS_DONATION, $plain->recorded_as);
        $this->assertSame(1, (int) $plain->quantity);
        $this->assertSame(5000, (int) $plain->unit_amount_shown_minor);
        $this->assertSame([], $plain->payload, 'no zakat answer: the fund decides (ZakatDesignation)');
        $this->assertSame(['zakat' => true], $zakat->payload);
        $this->assertSame(['zakat' => false], $notZakat->payload, 'declining is an answer');
    }

    #[Test]
    public function a_gift_amount_is_held_to_the_donation_doors_range(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        foreach ([99, 0, -100, 100000000] as $bad) {
            $this->addLine($org, $token, $this->giftBody($fund->id, $bad))->assertStatus(422)->assertJsonPath('status', 'failed');
        }

        $this->addLine($org, $token, ['type' => 'donation', 'fund_id' => $fund->id])->assertStatus(422);
        $this->assertSame(0, $this->lineCount());

        $this->addLine($org, $token, $this->giftBody($fund->id, 100))->assertOk();
        $this->addLine($org, $token, $this->giftBody($fund->id, 99999999))->assertOk();
    }

    #[Test]
    public function a_recurring_gift_is_refused(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        foreach ([true, 'true', '1'] as $recurring) {
            $this->addLine($org, $token, $this->giftBody($fund->id) + ['recurring' => $recurring])
                ->assertStatus(422)
                ->assertJsonPath('message', CartLineAdder::RECURRING);
        }

        $this->assertSame(0, $this->lineCount());

        // Saying it is NOT recurring (a checkbox left unticked) is what every one-time gift sends.
        $this->addLine($org, $token, $this->giftBody($fund->id) + ['recurring' => false])->assertOk();
        $this->addLine($org, $token, $this->giftBody($fund->id) + ['recurring' => 'false'])->assertOk();
        $this->assertSame(2, $this->lineCount());

        $this->addLine($org, $token, $this->giftBody($fund->id) + ['recurring' => 'maybe'])->assertStatus(422)->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function a_fund_of_another_organisation_or_one_that_stopped_collecting_is_refused(): void
    {
        $org = $this->org();
        $other = $this->org();
        $token = $this->startBasket($org);

        $this->addLine($org, $token, $this->giftBody($this->fund($other)->id))
            ->assertStatus(422)
            ->assertJsonPath('data.fund_id.0', 'This fund is not available.');

        $this->addLine($org, $token, $this->giftBody($this->fund($org, false)->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This fund is no longer collecting.');

        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function an_organisation_with_giving_off_or_no_card_account_cannot_take_a_gift(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        $org->forceFill(['capability_overrides' => ['giving' => false]])->save();
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertStatus(422)->assertJsonPath('message', DonationLineSource::GIVING_OFF);

        $org->forceFill(['capability_overrides' => null, 'stripe_charges_enabled' => false])->save();
        $this->addLine($org, $token, $this->giftBody($fund->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Online payment is not available for this right now.');

        $this->assertSame(0, $this->lineCount());
    }

    // ------------------------------------------------------------------- the shape

    #[Test]
    public function a_missing_or_unknown_type_is_a_field_error(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);

        foreach ([[], ['type' => 'registration'], ['type' => ['form']]] as $body) {
            $response = $this->addLine($org, $token, $body)->assertStatus(422)->assertJsonPath('status', 'failed');

            $this->assertArrayHasKey('type', $response->json('data'));
        }

        $this->assertSame(0, $this->lineCount());
    }

    // ------------------------------------------------------------------ the 25-line cap

    #[Test]
    public function a_basket_holds_at_most_twenty_five_lines(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        $this->assertSame(25, (int) config('cart.max_lines'));

        for ($i = 1; $i <= 25; $i++) {
            $last = $this->addLine($org, $token, $this->giftBody($fund->id, 100 * $i))->assertOk();
        }

        $this->assertSame(25, $this->lineCount());
        $this->assertCount(25, $last->json('data.lines'));

        $this->addLine($org, $token, $this->giftBody($fund->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A basket holds at most 25 items. Please remove one to add another.');
        $this->assertSame(25, $this->lineCount(), 'the 26th was not kept');

        // Room again once one goes.
        $this->cartApi('DELETE', '/api/v1/cart/items/' . $last->json('data.line_id'), $org, $token)->assertOk();
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $this->assertSame(25, $this->lineCount());
    }

    #[Test]
    public function the_cap_is_per_basket(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        config(['cart.max_lines' => 2]);
        $one = $this->startBasket($org);
        $two = $this->startBasket($org);

        $this->addLine($org, $one, $this->giftBody($fund->id))->assertOk();
        $this->addLine($org, $one, $this->giftBody($fund->id))->assertOk();
        $this->addLine($org, $one, $this->giftBody($fund->id))->assertStatus(422);

        $this->addLine($org, $two, $this->giftBody($fund->id))->assertOk();
    }

    // ------------------------------------------------------------------- the honeypot

    #[Test]
    public function a_filled_website_field_gets_a_fake_200_and_writes_nothing(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $before = $this->basketOf($token)->expires_at;

        Carbon::setTestNow($this->t0->copy()->addDay());

        $this->addLine($org, $token, $this->giftBody($fund->id) + ['website' => 'https://spam.example'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', null);

        $this->assertSame(0, $this->lineCount());
        $this->assertTrue($this->basketOf($token)->expires_at->equalTo($before), 'and it did not keep the basket alive');

        // An empty one is a person.
        $this->addLine($org, $token, $this->giftBody($fund->id) + ['website' => ''])->assertOk()->assertJsonPath('data.line_id', CartItem::withoutMasjidScope()->sole()->id);
    }

    // ------------------------------------------------------------ client_line_key replay

    #[Test]
    public function the_same_key_and_request_return_the_line_it_made(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $body = $this->giftBody($fund->id, 5000, 'press-gift-01');

        $first = $this->addLine($org, $token, $body)->assertOk();
        $again = $this->addLine($org, $token, $body)->assertOk();

        $this->assertSame($first->json('data.line_id'), $again->json('data.line_id'));
        $this->assertSame(1, $this->lineCount(), 'a double tap is one line');
        $this->assertSame(5000, $again->json('data.total_minor'), 'and it is charged once');
    }

    #[Test]
    public function a_retry_is_recognised_however_its_numbers_were_encoded_and_whatever_has_changed_since(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        $first = $this->addLine($org, $token, $this->giftBody($fund->id, 5000, 'press-gift-02'))->assertOk();

        // The fund stops collecting before the retry arrives: the retry still gets its line
        // (nothing is validated or priced again), and the basket says the line is gone.
        $fund->forceFill(['is_active' => false])->save();

        $again = $this->addLine($org, $token, [
            'type' => 'donation',
            'fund_id' => (string) $fund->id,
            'amount_minor' => '5000',
            'client_line_key' => 'press-gift-02',
        ])->assertOk();

        $this->assertSame($first->json('data.line_id'), $again->json('data.line_id'));
        $this->assertSame('gone', $again->json('data.lines.0.status'));
        $this->assertSame(1, $this->lineCount());
    }

    #[Test]
    public function the_same_key_with_a_different_request_is_a_409(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $form = $this->ticketForm($org);
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->giftBody($fund->id, 5000, 'press-gift-03'))->assertOk();

        $different = [
            'another amount' => $this->giftBody($fund->id, 6000, 'press-gift-03'),
            'another zakat answer' => $this->giftBody($fund->id, 5000, 'press-gift-03') + ['zakat' => true],
            'another type' => $this->twoTicketsBody($form->id, 'press-gift-03'),
        ];

        foreach ($different as $why => $body) {
            $this->addLine($org, $token, $body)
                ->assertStatus(409)
                ->assertJsonPath('status', 'error');
        }

        $line = CartItem::withoutMasjidScope()->sole();
        $this->assertSame(5000, (int) $line->unit_amount_shown_minor, 'the first line is as it was');
    }

    #[Test]
    public function the_same_key_with_other_form_answers_another_quantity_or_another_pickup_is_a_409(): void
    {
        // Each field the replay hash reads is pinned: drop one from the hash and a retry with a
        // changed request returns the OLD line with a 200, and nobody notices.
        $org = $this->org();
        $form = $this->ticketForm($org);
        $dish = $this->dish($org);
        $token = $this->startBasket($org);

        $ticketed = $this->twoTicketsBody($form->id, 'press-form-01');
        $meal = $this->dishBody($dish->id, 2, '2026-10-06T14:00', 'press-meal-01');

        $this->addLine($org, $token, $ticketed)->assertOk();
        $this->addLine($org, $token, $meal)->assertOk();
        $this->assertSame(2, $this->lineCount());

        $different = [
            'other attendee names' => ['tickets' => [['attendeeName' => 'A'], ['attendeeName' => 'C']]],
            'one more attendee' => ['tickets' => [['attendeeName' => 'A'], ['attendeeName' => 'B'], ['attendeeName' => 'C']]],
        ];

        foreach ($different as $why => $answers) {
            $this->addLine($org, $token, ['answers' => $answers] + $ticketed)
                ->assertStatus(409)
                ->assertJsonPath('status', 'error');
        }

        $this->addLine($org, $token, ['quantity' => 3] + $meal)->assertStatus(409)->assertJsonPath('status', 'error');
        $this->addLine($org, $token, ['pickup_at' => '2026-10-07T14:00'] + $meal)->assertStatus(409)->assertJsonPath('status', 'error');

        // Nothing changed, and the same requests are still recognised as themselves.
        $this->assertSame(2, $this->lineCount());
        $this->assertSame($this->twoTickets(), CartItem::withoutMasjidScope()->where('buyable_type', CartItem::TYPE_FORM)->sole()->payload);
        $this->assertSame(2, (int) CartItem::withoutMasjidScope()->where('buyable_type', CartItem::TYPE_MEAL)->sole()->quantity);
        $this->addLine($org, $token, $ticketed)->assertOk();
        $this->addLine($org, $token, $meal)->assertOk();
        $this->assertSame(2, $this->lineCount());
    }

    #[Test]
    public function at_the_cap_a_replay_of_the_last_lines_key_returns_that_line_and_a_new_key_meets_the_cap(): void
    {
        // A replay is answered before the cap is looked at: the shopper who pressed "add" for the
        // 25th line and retried on a bad connection gets their line back, not "holds at most 25
        // items". (CartLineAdder answers it early, and again under the lock for two taps at once;
        // only the first is reachable one request at a time.)
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        for ($i = 1; $i <= 25; $i++) {
            $body = $this->giftBody($fund->id, 100 * $i, sprintf('press-line-%02d', $i));
            $last = $this->addLine($org, $token, $body)->assertOk();
        }

        $this->assertSame(25, $this->lineCount(), 'premise: the basket is full');

        $again = $this->addLine($org, $token, $body)->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame($last->json('data.line_id'), $again->json('data.line_id'), 'the 25th line, not a refusal');
        $this->assertSame(25, $this->lineCount());
        $this->assertCount(25, $again->json('data.lines'));

        // A NEW press at the cap is still refused, and a changed request under the 25th key is a 409, not the cap.
        $this->addLine($org, $token, $this->giftBody($fund->id, 100, 'press-line-26'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A basket holds at most 25 items. Please remove one to add another.');
        $this->addLine($org, $token, $this->giftBody($fund->id, 9900, 'press-line-25'))->assertStatus(409);
        $this->assertSame(25, $this->lineCount());
    }

    #[Test]
    public function the_same_key_in_another_basket_is_another_press(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $one = $this->startBasket($org);
        $two = $this->startBasket($org);
        $body = $this->giftBody($fund->id, 5000, 'press-gift-04');

        $first = $this->addLine($org, $one, $body)->assertOk();
        $second = $this->addLine($org, $two, $body)->assertOk();

        $this->assertNotSame($first->json('data.line_id'), $second->json('data.line_id'));
        $this->assertSame(2, $this->lineCount());
    }

    #[Test]
    public function a_key_that_is_not_the_forms_shape_is_a_field_error(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        foreach (['short', 'has spaces in it', str_repeat('k', 65), 'bad/char/here'] as $key) {
            $response = $this->addLine($org, $token, $this->giftBody($fund->id, 5000, $key))->assertStatus(422);

            $this->assertArrayHasKey('client_line_key', $response->json('data'), $key);
        }

        $this->addLine($org, $token, $this->giftBody($fund->id, 5000, str_repeat('k', 64)))->assertOk();
        $this->assertSame(1, $this->lineCount());
    }
}
