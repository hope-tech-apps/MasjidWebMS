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

    // ------------------------------------------------------------------ M-F6
    // A CUT-OFF IS A DATE, NOT A STRING THAT LOOKS LIKE ONE.
    //
    // `resolveTier()` compared `$today <= (string) $until` and called it "safe
    // and portable: both sides are ISO yyyy-mm-dd". The left side always is —
    // `toDateString()` pads. The right side is whatever a committee typed into a
    // JSON file, and the importer accepted `2026-8-14` (exit 0) where the admin
    // API answers 422 "must match the format Y-m-d". Under string comparison
    // `'2026-09-10' <= '2026-8-14'` is TRUE — `'0' < '8'` at index 5 — so the
    // early-bird tier never expired. Measured on the imported form:
    //
    //     2026-08-14 -> 'Early bird' 100.0
    //     2026-08-16 -> 'Early bird' 100.0   <- should be Standard 120
    //     2026-09-10 -> 'Early bird' 100.0   <- camp is over; should be 140
    //     2027-01-05 -> 'Day of camp' 140.0  <- clears only at the year rollover
    //
    // $40 per attendee under-charged, for four months. The write path now
    // refuses an unpadded cut-off (ImportFormCommandTest), and the read path
    // stops depending on a precondition nothing enforced for the rows that were
    // already written.

    private function unpaddedForm(): Form
    {
        $form = $this->campForm();
        $settings = $form->settings;
        $settings['fee']['tiers'][0]['until'] = '2026-8-14';
        $settings['fee']['tiers'][1]['until'] = '2026-9-3';
        $form->settings = $settings;

        return $form;
    }

    private function tierOn(Form $form, string $date): ?array
    {
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00'));

        $fee = $form->feeRule();

        Carbon::setTestNow();

        return $fee['currentTier'] ?? null;
    }

    #[Test]
    public function an_unpadded_cutoff_still_expires_on_the_day_it_names(): void
    {
        $form = $this->unpaddedForm();

        $this->assertSame('Early bird', $this->tierOn($form, '2026-08-14')['label']);
        $this->assertSame('Standard', $this->tierOn($form, '2026-08-16')['label'], 'August 16 is past an August 14 cut-off.');
        $this->assertSame(120.0, (float) $this->tierOn($form, '2026-08-16')['amount']);
    }

    /** The four-month tail: camp is over and the form still quoted early bird. */
    #[Test]
    public function an_unpadded_cutoff_does_not_outlive_the_whole_schedule(): void
    {
        $form = $this->unpaddedForm();

        $this->assertSame('Day of camp', $this->tierOn($form, '2026-09-10')['label']);
        $this->assertSame(140.0, (float) $this->tierOn($form, '2026-09-10')['amount']);
        $this->assertSame('Day of camp', $this->tierOn($form, '2027-01-05')['label']);
    }

    /**
     * A cut-off nothing can read is NOT in force. Skipping it steps UP to the
     * next tier, which is visible and complainable; treating it as unexpired is
     * the silent under-charge above, wearing a different hat.
     */
    #[Test]
    public function an_unreadable_cutoff_does_not_hold_a_tier_open(): void
    {
        $form = $this->campForm();
        $settings = $form->settings;
        $settings['fee']['tiers'][0]['until'] = 'when we say so';
        $settings['fee']['tiers'][1]['until'] = '2026-13-45';
        $form->settings = $settings;

        // Both dated tiers are unreadable, so the open-ended final price stands.
        $this->assertSame('Day of camp', $this->tierOn($form, '2026-07-01')['label']);
        $this->assertSame(140.0, (float) $this->tierOn($form, '2026-07-01')['amount']);
    }

    // ------------------------------------------------------------------ F-M3
    // A BLANK CUT-OFF IS THE THIRD SPELLING OF "NO CUT-OFF", AND THE TWO BLANKS
    // USED TO MEAN OPPOSITE THINGS.
    //
    // Round six hardened the FORMAT of `until` and left the blank shapes alone,
    // because `nullable` makes Laravel skip every non-implicit rule on a value
    // that is present but blank — so `''` and `'   '` reached the column
    // untouched through every write door. Measured here, one schedule, three
    // days:
    //
    //     until ''      2026-08-01 Early bird/100   2026-08-20 Early bird/100
    //                   2026-09-20 Early bird/100   <- never steps up, ever
    //     until '   '   2026-08-01 Standard/120     <- SKIPPED, steps up at once
    //                   2026-09-20 Day of camp/140
    //
    // Which of those a form got depended on which door wrote it, and not
    // visibly: `TrimStrings` + `ConvertEmptyStringsToNull` are global middleware,
    // so the admin API turns both blanks into NULL before validation and has
    // always meant "no cut-off" by them, while a JSON file passes through no
    // middleware and `form:import` stored the blank verbatim.
    //
    // One meaning now, on every door and on the read: absent, null and blank are
    // one statement. `App\Rules\TierCutoff::normalise()` is the write half.
    // These are the read half, for the rows that were written before it existed.

    private function blankUntilForm(mixed $until, int $tier): Form
    {
        $form = $this->campForm();
        $settings = $form->settings;
        $settings['fee']['tiers'][$tier]['until'] = $until;
        $form->settings = $settings;

        return $form;
    }

    /**
     * Blanked on the MIDDLE tier, which is where the two readings visibly part:
     * "no cut-off" makes Standard the price from August 15 onwards, while
     * "skip it" hands every one of those days to Day of camp — $20 an attendee,
     * decided by whether somebody typed a space.
     */
    #[Test]
    public function every_blank_spelling_of_a_cutoff_means_the_open_ended_final_price(): void
    {
        foreach ([null, '', '   ', "\t"] as $blank) {
            $form = $this->blankUntilForm($blank, 1);
            $label = var_export($blank, true);

            $this->assertSame('Early bird', $this->tierOn($form, '2026-08-01')['label'], "until {$label}");
            $this->assertSame('Standard', $this->tierOn($form, '2026-08-20')['label'], "until {$label}");
            $this->assertSame(120.0, (float) $this->tierOn($form, '2026-09-20')['amount'], "until {$label}");
            $this->assertSame('Standard', $this->tierOn($form, '2027-01-05')['label'], "until {$label}");
        }
    }

    /** …and on the LAST tier, where it is simply the ordinary open-ended price. */
    #[Test]
    public function a_blank_cutoff_on_the_last_tier_is_the_ordinary_open_ended_price(): void
    {
        foreach ([null, '', '   '] as $blank) {
            $form = $this->blankUntilForm($blank, 2);
            $label = var_export($blank, true);

            $this->assertSame('Early bird', $this->tierOn($form, '2026-08-01')['label'], "until {$label}");
            $this->assertSame('Standard', $this->tierOn($form, '2026-08-20')['label'], "until {$label}");
            $this->assertSame('Day of camp', $this->tierOn($form, '2026-09-20')['label'], "until {$label}");
            $this->assertSame(140.0, (float) $this->tierOn($form, '2027-01-05')['amount'], "until {$label}");
        }
    }

    /**
     * The cell that actually cost money: a blank on the FIRST tier used to make
     * the cheapest price permanent through one spelling and skip it through the
     * other. Both now do the same thing, and the schedule below is what the page
     * publishes, so "early bird has no end date" is visible rather than silent.
     */
    #[Test]
    public function a_blank_cutoff_on_the_first_tier_reads_the_same_way_whichever_blank_it_is(): void
    {
        $prices = [];

        foreach ([null, '', '   '] as $blank) {
            $form = $this->campForm();
            $settings = $form->settings;
            $settings['fee']['tiers'][0]['until'] = $blank;
            $form->settings = $settings;

            $prices[] = collect(['2026-07-01', '2026-08-20', '2026-09-20'])
                ->map(fn ($day) => $this->tierOn($form, $day)['label'])
                ->all();
        }

        $this->assertSame($prices[0], $prices[1], "'' must read exactly as null does");
        $this->assertSame($prices[0], $prices[2], "'   ' must read exactly as null does");
        $this->assertSame(['Early bird', 'Early bird', 'Early bird'], $prices[0]);
    }

    /**
     * …and a blank is NOT the "unreadable steps up" rule. A date somebody got
     * wrong still steps up; a date somebody did not give is the open-ended price.
     * Keeping these two apart is the whole of this fix.
     */
    #[Test]
    public function an_unreadable_cutoff_still_steps_up_while_a_blank_does_not(): void
    {
        $unreadable = $this->campForm();
        $settings = $unreadable->settings;
        $settings['fee']['tiers'][0]['until'] = 'when we say so';
        $unreadable->settings = $settings;

        $blank = $this->campForm();
        $settings = $blank->settings;
        $settings['fee']['tiers'][0]['until'] = '';
        $blank->settings = $settings;

        $this->assertSame('Standard', $this->tierOn($unreadable, '2026-07-01')['label']);
        $this->assertSame('Early bird', $this->tierOn($blank, '2026-07-01')['label']);
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
