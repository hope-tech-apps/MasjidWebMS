<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Support\FormSchema;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Date-stepped registration pricing: early bird → standard → day-of.
 *
 * The price is decided by the day someone registers, and `amount_due` is computed and
 * STORED at submission time — so a family who registered during early bird still owes
 * $100 after the price steps up. That is the property these tests pin; getting it wrong
 * would silently re-bill people who already committed.
 *
 * Boundaries are the risk: "until" is INCLUSIVE, so August 14 is still early bird.
 */
class TieredFeeTest extends TestCase
{
    private function campForm(): Form
    {
        $form = new Form();

        $form->schema = ['sections' => [[
            'id' => 'attendees',
            'title' => 'Attendees',
            'repeatable' => true,
            'minEntries' => 1,
            'fields' => [['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
        ]]];

        $form->settings = ['fee' => [
            'currency' => 'USD',
            'perEntryOfSection' => 'attendees',
            'amount' => 140,
            'tiers' => [
                ['label' => 'Early bird', 'amount' => 100, 'until' => '2026-08-14'],
                ['label' => 'Standard', 'amount' => 120, 'until' => '2026-09-03'],
                ['label' => 'Day of camp', 'amount' => 140],
            ],
        ]];

        return $form;
    }

    private function amountOn(string $date, int $attendees = 1): float
    {
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00'));

        $data = ['attendees' => array_fill(0, $attendees, ['fullName' => 'X'])];
        $amount = FormSchema::for($this->campForm())->amountDue($data);

        Carbon::setTestNow();

        return $amount;
    }

    #[Test]
    public function early_bird_applies_before_the_cutoff(): void
    {
        $this->assertSame(100.0, $this->amountOn('2026-07-28'));
        $this->assertSame(100.0, $this->amountOn('2026-08-01'));
    }

    /** The exact boundary the committee will be asked about. */
    #[Test]
    public function august_14_is_still_early_bird(): void
    {
        $this->assertSame(100.0, $this->amountOn('2026-08-14'));
    }

    #[Test]
    public function the_price_steps_up_the_day_after_the_cutoff(): void
    {
        $this->assertSame(120.0, $this->amountOn('2026-08-15'));
    }

    #[Test]
    public function standard_holds_until_the_day_before_camp(): void
    {
        $this->assertSame(120.0, $this->amountOn('2026-09-03'));
    }

    #[Test]
    public function day_of_registration_is_the_open_ended_final_price(): void
    {
        $this->assertSame(140.0, $this->amountOn('2026-09-04'));
        // Still 140 afterwards — the last tier has no end date.
        $this->assertSame(140.0, $this->amountOn('2026-09-20'));
    }

    #[Test]
    public function the_price_multiplies_by_the_number_of_attendees(): void
    {
        $this->assertSame(300.0, $this->amountOn('2026-08-01', 3));
        $this->assertSame(480.0, $this->amountOn('2026-08-20', 4));
    }

    #[Test]
    public function the_tier_in_force_is_reported_for_the_page_to_display(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00'));

        $fee = $this->campForm()->feeRule();

        $this->assertSame(120.0, $fee['amount']);
        $this->assertSame('Standard', $fee['currentTier']['label']);
        $this->assertCount(3, $fee['tiers'], 'The whole schedule ships so the page can show what changes and when.');

        Carbon::setTestNow();
    }

    /** A form with a plain amount and no tiers must behave exactly as before. */
    #[Test]
    public function an_untiered_form_still_uses_its_flat_amount(): void
    {
        $form = $this->campForm();
        $settings = $form->settings;
        unset($settings['fee']['tiers']);
        $settings['fee']['amount'] = 75;
        $form->settings = $settings;

        $data = ['attendees' => [['fullName' => 'X'], ['fullName' => 'Y']]];

        $this->assertSame(150.0, FormSchema::for($form)->amountDue($data));
    }
}
