<?php

namespace Tests\Feature\Cart;

use App\Models\Form;
use App\Models\Masjid;
use App\Services\Cart\CartLineOutcome;
use App\Services\Cart\Sources\FormLineSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Re-checking a basket line at checkout (universal cart, DECISIONS 2026-09-26).
 *
 * A basket reserves nothing, and a shopper can fill one on Thursday and pay on
 * Saturday. By then MEC's festival tickets may have closed (23:59 on 16 October),
 * the form may have filled, or an admin may have changed the price. These pin that
 * the line is asked again at checkout and that the answer is the FORM's own — this
 * class must never become a second, laxer copy of the submit endpoint's rules.
 */
class FormLineSourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => 'acct_test_cart',
            'stripe_charges_enabled' => true,
            'timezone' => 'America/New_York',
        ]);
    }

    private function ticketForm(array $overrides = []): Form
    {
        return Form::factory()->create(array_merge([
            'masjid_id' => $this->makeOrg()->id,
            'name' => 'MEC Fall Festival 2026 – Tickets',
            'is_active' => true,
            'opens_at' => null,
            'closes_at' => null,
            'capacity' => null,
            'schema' => ['sections' => [[
                'id' => 'tickets',
                'title' => 'Ticket',
                'repeatable' => true,
                'minEntries' => 1,
                'maxEntries' => 20,
                'fields' => [['name' => 'attendeeName', 'type' => 'text', 'label' => 'Attendee name', 'required' => true]],
            ]]],
            'settings' => [
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
                'payment' => ['online' => true, 'officePayment' => false],
            ],
        ], $overrides));
    }

    /** Two ticket rows at $15 each. */
    private function twoTickets(): array
    {
        return ['tickets' => [['attendeeName' => 'A'], ['attendeeName' => 'B']]];
    }

    /**
     * Iftar sponsorship levels: "Individual Iftar" is $18 a person and reserves nothing;
     * "Quarter Iftar" is $450 and reserves one of the form's listed dates.
     */
    private function iftarForm(): Form
    {
        return Form::factory()->create([
            'masjid_id' => $this->makeOrg()->id,
            'name' => 'Iftar Sponsorship',
            'is_active' => true,
            'opens_at' => null,
            'closes_at' => null,
            'capacity' => null,
            'schema' => ['sections' => [['id' => 'sponsor', 'title' => 'Sponsor', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'sponsorship', 'label' => 'Sponsorship', 'type' => 'radio', 'required' => true, 'options' => [
                    ['value' => 'individual', 'label' => 'Individual Iftar'],
                    ['value' => 'quarter', 'label' => 'Quarter Iftar'],
                ]],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'min' => 1, 'max' => 50],
                ['name' => 'iftar_date', 'label' => 'Date', 'type' => 'select', 'optionsSource' => 'reservable_dates'],
            ]]]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'fee' => [
                    'currency' => 'USD',
                    'perQuantityOf' => 'people',
                    'byChoice' => ['field' => 'sponsorship', 'prices' => [
                        ['value' => 'individual', 'amount' => 18, 'perQuantity' => true],
                        ['value' => 'quarter', 'amount' => 450, 'reservesDate' => true],
                    ]],
                ],
                'reservation' => ['field' => 'iftar_date', 'dates' => ['2027-02-10', '2027-02-11']],
                'payment' => ['online' => true, 'officePayment' => false],
            ],
        ]);
    }

    #[Test]
    public function an_open_form_at_the_expected_price_is_payable(): void
    {
        $outcome = (new FormLineSource)->reprice($this->ticketForm(), $this->twoTickets(), 1500);

        $this->assertSame('available', $outcome->status);
        $this->assertSame(1500, $outcome->unitAmountMinor);
        $this->assertSame(2, $outcome->quantity);
        $this->assertSame(3000, $outcome->totalMinor());
        $this->assertTrue($outcome->isPayable());
    }

    #[Test]
    public function a_form_that_closed_while_it_sat_in_the_basket_is_dropped_and_explained(): void
    {
        $form = $this->ticketForm(['closes_at' => now()->subMinute()]);

        $outcome = (new FormLineSource)->reprice($form, $this->twoTickets(), 1500);

        $this->assertSame('gone', $outcome->status);
        $this->assertFalse($outcome->isPayable());
        $this->assertSame(0, $outcome->totalMinor(), 'a closed line must contribute nothing to the total');
        $this->assertNotEmpty($outcome->reason, 'the shopper has to be told which line went and why');
    }

    #[Test]
    public function a_form_that_filled_up_while_it_sat_in_the_basket_is_dropped(): void
    {
        $form = $this->ticketForm(['capacity' => 10]);
        $form->forceFill(['response_count' => 10])->save();

        $outcome = (new FormLineSource)->reprice($form->fresh(), $this->twoTickets(), 1500);

        $this->assertSame('gone', $outcome->status);
        $this->assertSame(0, $outcome->totalMinor());
    }

    #[Test]
    public function a_deactivated_form_is_dropped(): void
    {
        $outcome = (new FormLineSource)->reprice($this->ticketForm(['is_active' => false]), $this->twoTickets(), 1500);

        $this->assertSame('gone', $outcome->status);
    }

    #[Test]
    public function a_price_change_is_charged_at_the_new_price_and_flagged(): void
    {
        $form = $this->ticketForm();
        $form->settings = array_merge($form->settings, [
            'fee' => ['amount' => 20, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
        ]);
        $form->save();

        $outcome = (new FormLineSource)->reprice($form->fresh(), $this->twoTickets(), 1500);

        $this->assertSame('repriced', $outcome->status);
        $this->assertTrue($outcome->isPayable(), 'a repriced line is still buyable, at the new price');
        $this->assertSame(2000, $outcome->unitAmountMinor, 'the CURRENT price is charged, never the stale one');
        $this->assertSame(4000, $outcome->totalMinor());
        $this->assertNotEmpty($outcome->reason);
    }

    #[Test]
    public function the_quantity_is_recounted_from_the_answers_not_taken_from_the_basket(): void
    {
        // The basket said one ticket; the payload carries three rows. The form's own
        // per-entry pricing decides, so nothing the browser stored can undercharge.
        $payload = ['tickets' => [['attendeeName' => 'A'], ['attendeeName' => 'B'], ['attendeeName' => 'C']]];

        $outcome = (new FormLineSource)->reprice($this->ticketForm(), $payload, 1500);

        $this->assertSame(3, $outcome->quantity);
        $this->assertSame(4500, $outcome->totalMinor());
    }

    #[Test]
    public function a_line_that_reserves_a_date_is_dropped_and_sent_to_the_forms_own_page(): void
    {
        // The form door claims the date under the form's lock; a basket settles with no hold, so
        // two shoppers could pay for one evening. Refused whoever asks first, and at checkout too.
        $answers = ['fullName' => 'Amal Sponsor', 'email' => 'amal@example.test', 'sponsorship' => 'quarter', 'iftar_date' => '2027-02-10'];

        $outcome = (new FormLineSource)->reprice($this->iftarForm(), $answers, 45000);

        $this->assertSame('gone', $outcome->status);
        $this->assertFalse($outcome->isPayable());
        $this->assertSame(0, $outcome->totalMinor());
        $this->assertSame(FormLineSource::RESERVES_A_DATE, $outcome->reason);
        $this->assertStringContainsString('own page', (string) $outcome->reason);
    }

    #[Test]
    public function a_line_on_a_date_form_that_reserves_nothing_is_still_payable(): void
    {
        $answers = ['fullName' => 'Amal Sponsor', 'email' => 'amal@example.test', 'sponsorship' => 'individual', 'people' => '3'];

        $outcome = (new FormLineSource)->reprice($this->iftarForm(), $answers, 1800, null, 3);

        $this->assertSame('available', $outcome->status);
        $this->assertSame(1800, $outcome->unitAmountMinor);
        $this->assertSame(3, $outcome->quantity);
        $this->assertSame(5400, $outcome->totalMinor());
        $this->assertTrue($outcome->isPayable());
    }

    #[Test]
    public function a_form_that_can_no_longer_be_priced_is_refused_rather_than_guessed(): void
    {
        $form = $this->ticketForm();
        $form->settings = ['payment' => ['online' => true]];   // fee rule removed
        $form->save();

        $outcome = (new FormLineSource)->reprice($form->fresh(), $this->twoTickets(), 1500);

        $this->assertSame('gone', $outcome->status);
        $this->assertSame(0, $outcome->totalMinor());
    }

    #[Test]
    public function a_gone_outcome_carries_no_money(): void
    {
        $outcome = CartLineOutcome::gone('Anything', 'because');

        $this->assertSame(0, $outcome->unitAmountMinor);
        $this->assertSame(0, $outcome->quantity);
        $this->assertSame(0, $outcome->totalMinor());
    }
}
