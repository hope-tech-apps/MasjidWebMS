<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a paying form publishes on its page (SectionContentBinder::bindForm()),
 * read through the public page endpoint the Nuxt site calls.
 *
 * The payment block is an allowlist of switches the renderer draws with. Two
 * things are never in the page, however the form is set up:
 *
 *  - the WhatsApp group link or its label. The link is handed out only once a
 *    registration is settled; in the page source it is a group anyone could
 *    join without registering, let alone paying.
 *  - anything about staff codes beyond "draw the staff-entry link": no code,
 *    digest, hint, holder or count.
 *
 * And a form that takes no payment publishes exactly the keys it always did.
 */
class FormPaymentPayloadTest extends TestCase
{
    use RefreshDatabase;

    /** The invite id, searched for WITHOUT slashes: the JSON body escapes them. */
    private const INVITE_ID = 'FestivalGroup2026Xyz';

    private const WHATSAPP_LABEL = 'Join the festival WhatsApp group';

    private const NOTIFY = 'festival-office@mec.test';

    /** Every key bindForm() published under form.settings before the festival. */
    private const LEGACY_SETTINGS_KEYS = ['fee', 'intro', 'submitButtonLabel', 'successBody', 'successNextSteps', 'successTitle'];

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

        config([
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
        ]);

        $this->masjid = $this->makeMasjid();
    }

    #[Test]
    public function a_paying_form_publishes_its_switches_and_never_the_group_link_or_a_code(): void
    {
        $form = $this->payingForm();

        [$codeA, $plainA] = FormStaffCode::issue($form, 'Najd Holderperson', now()->addDays(30));
        [$codeB, $plainB] = FormStaffCode::issue($form, 'Yusuf Cashkeeper', now()->addDays(30));

        $response = $this->page($form);
        $settings = $response->json('data.sections.0.content.form.settings');

        $this->assertEqualsCanonicalizing(
            [...self::LEGACY_SETTINGS_KEYS, 'payment'],
            array_keys($settings),
            'form.settings is an allowlist: one new key, and only that'
        );

        $this->assertSame([
            'online' => true,
            'available' => true,
            'allowFeeCoverage' => true,
            'staffEntry' => true,
            'unitMinor' => 2500,
            'currency' => 'usd',
            'stripeFeePercentage' => 0.029,
            'stripeFeeFixedMinor' => 30,
            // BISS (2026-09-13): off on a form that uses neither.
            'requireFeeCoverage' => false,
            'officePayment' => false,
            'officeInstructions' => null,
            'countTiers' => null,
        ], $settings['payment']);

        // A unit-priced fee is published exactly as feeRule() always was: these keys, in
        // this order, and these values (equals, not same: a whole float may come back
        // from JSON as an int).
        $this->assertSame(['amount', 'currency', 'perEntryOfSection', 'tiers', 'currentTier'], array_keys($settings['fee']));
        $this->assertEquals([
            'amount' => 25,
            'currency' => 'USD',
            'perEntryOfSection' => 'attendees',
            'tiers' => [],
            'currentTier' => null,
        ], $settings['fee']);

        $body = $response->getContent();

        // The group link, whichever way it could be spelled in the body.
        $this->assertStringNotContainsStringIgnoringCase('whatsapp', $body);
        $this->assertStringNotContainsString(self::INVITE_ID, $body);
        $this->assertStringNotContainsString(self::WHATSAPP_LABEL, $body);

        // The codes: plaintext as issued and as typed, the stored digest, the holders.
        foreach ([[$codeA, $plainA], [$codeB, $plainB]] as [$code, $plain]) {
            $this->assertStringNotContainsString($plain, $body);
            $this->assertStringNotContainsString(FormStaffCode::normalise($plain), $body);
            $this->assertStringNotContainsString($code->getRawOriginal('code_hash'), $body);
            $this->assertStringNotContainsString($code->holder_name, $body);
        }

        // …and the operational settings bindForm() never published.
        $this->assertStringNotContainsString(self::NOTIFY, $body);

        // No key anywhere in the published form so much as names these things.
        foreach ($this->keysOf($response->json('data.sections.0.content.form')) as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/code|hash|holder|whatsapp|notify|identity/i',
                $key,
                "the public form carries a key named \"{$key}\""
            );
        }
    }

    #[Test]
    public function the_page_says_when_card_payment_is_on_but_cannot_be_taken_yet(): void
    {
        // Stripe Connect not live: the renderer explains instead of 422ing.
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        $payment = $this->page($this->payingForm())->json('data.sections.0.content.form.settings.payment');

        $this->assertTrue($payment['online']);
        $this->assertFalse($payment['available']);
        $this->assertTrue($payment['staffEntry'], 'cash by code needs no Stripe at all');
    }

    #[Test]
    public function the_staff_entry_link_ignores_the_window_but_not_an_inactive_or_full_form(): void
    {
        // Online registration has closed; walk-ups at the gate have not.
        $closed = $this->payingForm(['closes_at' => now()->subDay()]);
        $content = $this->page($closed)->json('data.sections.0.content.form');

        $this->assertFalse($content['accepting']);
        $this->assertTrue($content['settings']['payment']['staffEntry']);

        $inactive = $this->payingForm(['is_active' => false]);
        $this->assertFalse($this->page($inactive)->json('data.sections.0.content.form.settings.payment.staffEntry'));

        $full = $this->payingForm(['capacity' => 1]);
        Form::whereKey($full->id)->update(['response_count' => 1]);
        $this->assertFalse($this->page($full)->json('data.sections.0.content.form.settings.payment.staffEntry'));

        $cardOnly = $this->payingForm([], ['online' => true, 'staffCodes' => false, 'allowFeeCoverage' => false]);
        $payment = $this->page($cardOnly)->json('data.sections.0.content.form.settings.payment');
        $this->assertFalse($payment['staffEntry']);
        $this->assertFalse($payment['allowFeeCoverage']);
    }

    #[Test]
    public function a_form_that_takes_no_payment_publishes_exactly_what_it_always_did(): void
    {
        // A camp-style form with a price but no payment switches — every form that
        // existed before the festival — even with a group link configured.
        $legacy = $this->payingForm([], null);
        $response = $this->page($legacy);

        $this->assertEqualsCanonicalizing(
            self::LEGACY_SETTINGS_KEYS,
            array_keys($response->json('data.sections.0.content.form.settings'))
        );
        $this->assertStringNotContainsStringIgnoringCase('whatsapp', $response->getContent());

        // Switches present but off.
        $off = $this->payingForm([], ['online' => false, 'staffCodes' => false, 'allowFeeCoverage' => true]);
        $this->assertArrayNotHasKey(
            'payment',
            $this->page($off)->json('data.sections.0.content.form.settings')
        );

        // A switch with no price behind it moves no money, so it publishes nothing.
        $noPrice = $this->payingForm(['settings' => [
            'payment' => ['online' => true, 'staffCodes' => true],
            'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE_ID,
        ]]);
        $this->assertArrayNotHasKey(
            'payment',
            $this->page($noPrice)->json('data.sections.0.content.form.settings')
        );
    }

    // ---------------------------------------------- BISS: family prices and the office

    private const FAMILY_TIERS = [
        ['min' => 1, 'amount' => 100, 'label' => '1 child'],
        ['min' => 2, 'amount' => 170, 'label' => '2 children'],
        ['min' => 3, 'amount' => 250, 'label' => '3 children'],
        ['min' => 4, 'amount' => 300, 'label' => '4 children'],
        ['min' => 5, 'amount' => 350, 'label' => '5 or more children'],
    ];

    /**
     * THE CONTRACT the renderer is built against (BISS critique must_fix 1 and
     * should_fix 1). The fee carries no `tiers` and NO `amount` key at all, so a renderer
     * that predates count pricing computes no "live total" — not $100 × 3 = $300 while the
     * server charges $250, and not $0.00 × 3 from Number(null). It keeps
     * `perEntryOfSection`, which the renderer counts rows from. The payment block carries
     * no unit price, and allowFeeCoverage stays
     * off although the fee is required, so no optional box is drawn for a fee the server
     * adds anyway.
     */
    #[Test]
    public function a_family_priced_form_publishes_its_tiers_and_never_a_unit_price_or_an_optional_fee_box(): void
    {
        // Stored out of order: published ascending.
        $form = $this->payingForm(['settings' => array_replace($this->payingForm()->settings, [
            'fee' => ['currency' => 'USD', 'perEntryOfSection' => 'attendees', 'countTiers' => [
                self::FAMILY_TIERS[0], self::FAMILY_TIERS[4], self::FAMILY_TIERS[2], self::FAMILY_TIERS[1], self::FAMILY_TIERS[3],
            ]],
            'payment' => [
                'online' => true,
                'requireFeeCoverage' => true,
                'officePayment' => true,
                'officeInstructions' => '  Zelle office@biss.example, or cash on Sunday.  ',
            ],
        ])]);

        $settings = $this->page($form)->json('data.sections.0.content.form.settings');

        $this->assertSame(['pricing', 'currency', 'perEntryOfSection', 'countTiers'], array_keys($settings['fee']));
        $this->assertSame(['min', 'amount', 'label'], array_keys($settings['fee']['countTiers'][0]));
        $this->assertEquals([
            'pricing' => 'count',
            'currency' => 'USD',
            'perEntryOfSection' => 'attendees',
            'countTiers' => self::FAMILY_TIERS,
        ], $settings['fee']);
        $this->assertFalse(array_key_exists('amount', $settings['fee']), 'no amount key, not even a null one');
        $this->assertFalse(array_key_exists('tiers', $settings['fee']));
        $this->assertStringNotContainsString('"amount":null', $this->page($form)->getContent());

        $this->assertSame([
            'online' => true,
            'available' => true,
            'allowFeeCoverage' => false,
            'staffEntry' => false,
            'unitMinor' => null,
            'currency' => 'usd',
            'stripeFeePercentage' => 0.029,
            'stripeFeeFixedMinor' => 30,
            'requireFeeCoverage' => true,
            'officePayment' => true,
            'officeInstructions' => 'Zelle office@biss.example, or cash on Sunday.',
            'countTiers' => [
                ['min' => 1, 'amountMinor' => 10000, 'label' => '1 child'],
                ['min' => 2, 'amountMinor' => 17000, 'label' => '2 children'],
                ['min' => 3, 'amountMinor' => 25000, 'label' => '3 children'],
                ['min' => 4, 'amountMinor' => 30000, 'label' => '4 children'],
                ['min' => 5, 'amountMinor' => 35000, 'label' => '5 or more children'],
            ],
        ], $settings['payment']);
    }

    #[Test]
    public function the_office_is_published_when_card_and_codes_are_off_and_its_instructions_only_while_it_is_on(): void
    {
        $officeOnly = $this->payingForm([], ['officePayment' => true, 'officeInstructions' => 'Pay the office on Sunday.']);
        $payment = $this->page($officeOnly)->json('data.sections.0.content.form.settings.payment');

        $this->assertNotNull($payment, 'the office is a payment the page must offer');
        $this->assertFalse($payment['online']);
        $this->assertFalse($payment['available']);
        $this->assertFalse($payment['staffEntry']);
        $this->assertTrue($payment['officePayment']);
        $this->assertSame('Pay the office on Sunday.', $payment['officeInstructions']);
        $this->assertSame(2500, $payment['unitMinor']);

        $cardOnly = $this->payingForm([], ['online' => true, 'officeInstructions' => 'A draft nobody switched on.']);
        $payment = $this->page($cardOnly)->json('data.sections.0.content.form.settings.payment');

        $this->assertFalse($payment['officePayment']);
        $this->assertNull($payment['officeInstructions']);
        $this->assertStringNotContainsString('A draft nobody switched on', $this->page($cardOnly)->getContent());
    }

    // -------------------------------------------------------------- helpers

    /**
     * A festival form: $25 per attendee, card + codes + fee cover by default, a
     * group link, and the operational settings that must stay private.
     *
     * @param  array<string,mixed>  $overrides  Form attributes
     * @param  array<string,mixed>|null  $payment  settings.payment, or null for none
     */
    private function payingForm(array $overrides = [], ?array $payment = ['online' => true, 'staffCodes' => true, 'allowFeeCoverage' => true]): Form
    {
        $settings = [
            'identity' => ['name' => 'fullName', 'email' => 'email'],
            'notifyEmails' => [self::NOTIFY],
            'paymentNote' => 'Cash at the gate or card online.',
            'fee' => ['currency' => 'USD', 'amount' => 25, 'perEntryOfSection' => 'attendees'],
            'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE_ID,
            'whatsappLabel' => self::WHATSAPP_LABEL,
        ];

        if ($payment !== null) {
            $settings['payment'] = $payment;
        }

        return Form::create(array_merge([
            'masjid_id' => $this->masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival 2026',
            'schema' => ['sections' => [
                [
                    'id' => 'main',
                    'title' => 'You',
                    'fields' => [
                        ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                    ],
                ],
                [
                    'id' => 'attendees',
                    'title' => 'Attendees',
                    'repeatable' => true,
                    'minEntries' => 1,
                    'fields' => [['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
                ],
            ]],
            'settings' => $settings,
            'is_active' => true,
        ], $overrides));
    }

    /** The public page carrying one `form` section for $form, as the site reads it. */
    private function page(Form $form): TestResponse
    {
        $page = Page::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'register-' . uniqid(),
            'title' => 'Register',
            'is_active' => true,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $this->masjid->id,
            'section_type' => 'form',
            'title' => 'Registration',
            'content' => ['form_id' => $form->id],
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $this->withHeader('masjid-id', (string) $this->masjid->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertOk();
    }

    /** @return array<int,string> every string key, at any depth */
    private function keysOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            array_push($keys, ...$this->keysOf($item));
        }

        return $keys;
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ]);
    }
}
