<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Order;
use App\Services\Cart\CartCheckoutRefused;
use App\Services\Cart\CartLineOutcome;
use App\Services\Cart\CartPricer;
use App\Services\Cart\Sources\DonationLineSource;
use App\Services\Cart\Sources\FormLineSource;
use App\Services\Cart\Sources\MealLineSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * The gates the three live doors ask and the cart services did not (brief 5, section 2).
 *
 * They live in the LINE SOURCES, not in the endpoint, because the endpoint only decides
 * whether a line may be ADDED: a basket sits for days, and checkout (through CartPricer)
 * re-asks every source. Each test below therefore has a control (the same line on an
 * organisation where the gate is open is on sale) and the closed case, so it fails if the
 * gate is deleted; and each is followed through to checkout, which must open no page.
 *
 * The doors themselves are not touched by this slice: the sentences are pinned to what the
 * door controllers say, and their own suites pass unchanged.
 */
class CartLineSourceGatesTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    /** What the basket would say about ONE line right now. */
    private function outcome(Cart $cart, CartItem $item): CartLineOutcome
    {
        foreach ((new CartPricer)->price($cart)->lines as ['item' => $line, 'outcome' => $outcome]) {
            if ($line->id === $item->id) {
                return $outcome;
            }
        }

        $this->fail('the line was not priced at all');
    }

    private function fileForm(Masjid $org): Form
    {
        return $this->ticketForm($org, [
            'name' => 'Volunteer Application',
            'schema' => ['sections' => [[
                'id' => 'main', 'title' => 'Main', 'repeatable' => false,
                'fields' => [
                    ['name' => 'fullName', 'type' => 'text', 'label' => 'Name', 'required' => true],
                    ['name' => 'resume', 'type' => 'file', 'label' => 'Resume', 'required' => false],
                ],
            ]]],
            'settings' => [
                'fee' => ['amount' => 20, 'currency' => 'USD'],
                'payment' => ['online' => true, 'officePayment' => false],
            ],
        ]);
    }

    /** @return list<array{label: string, status: string, reason: string}> what checkout refuses with */
    private function checkoutNotices(Cart $cart): array
    {
        try {
            $this->checkoutService()->checkout($cart, self::RETURN_BASE, 'buyer@example.org');
        } catch (CartCheckoutRefused $refused) {
            $this->assertNotNull($refused->seen(), 'a basket that changed is refused with what the shopper was shown');

            return $refused->notices();
        }

        $this->fail('a payment page was opened for a basket with a line that cannot be sold');
    }

    // ------------------------------------------------------------------- giving

    #[Test]
    public function a_gift_to_an_organisation_with_giving_switched_off_is_gone_and_checkout_says_so(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $gift = $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        // Control: giving is on for a masjid unless someone switched it off.
        $this->assertSame('available', $this->outcome($cart, $gift)->status);

        $org->forceFill(['capability_overrides' => ['giving' => false]])->save();

        $off = $this->outcome($cart, $gift);
        $this->assertSame('gone', $off->status);
        $this->assertSame(DonationLineSource::GIVING_OFF, $off->reason);

        $this->assertSame(
            [['label' => 'Zakat-ul-Fitr', 'status' => 'gone', 'reason' => DonationLineSource::GIVING_OFF]],
            $this->checkoutNotices($cart)
        );
        $this->assertSame(0, Order::withoutMasjidScope()->count(), 'checkout wrote nothing');
    }

    #[Test]
    public function the_giving_gate_cannot_be_skipped_by_calling_the_source_directly(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);

        $this->assertSame('available', (new DonationLineSource)->reprice($fund, 5000)->status);

        $org->forceFill(['capability_overrides' => ['giving' => false]])->save();

        $this->assertSame('gone', (new DonationLineSource)->reprice($fund->fresh(), 5000)->status);
    }

    #[Test]
    public function an_organisation_that_cannot_take_a_card_is_already_refused_by_the_payee_rule(): void
    {
        // The door's other gate, canAcceptDonations(), is deliberately NOT repeated in the
        // source: CartPricer::ownAccount() asks it when it picks the payee, and a line with no
        // payee is refused. This pins that, so the gate is not lost if the pricer changes.
        $org = $this->org(['stripe_charges_enabled' => false]);
        $this->assertFalse($org->canAcceptDonations(), 'premise: Stripe says this organisation cannot charge');

        $cart = $this->cart($org);
        $gift = $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        $outcome = $this->outcome($cart, $gift);
        $this->assertSame('gone', $outcome->status);
        $this->assertSame('Online payment is not available for this right now.', $outcome->reason);
    }

    // ------------------------------------------------------------- Friday lunch

    #[Test]
    public function a_dish_at_an_organisation_without_the_lunch_capability_is_gone_and_checkout_says_so(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $dish = $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 2);

        $this->assertSame('available', $this->outcome($cart, $dish)->status, 'control: a masjid has the capability by default');

        $org->forceFill(['capability_overrides' => ['jummah_lunch' => false]])->save();

        $off = $this->outcome($cart, $dish);
        $this->assertSame('gone', $off->status);
        $this->assertSame(MealLineSource::ORDERING_OFF, $off->reason);

        $this->assertSame(
            [['label' => 'Baked Lamb', 'status' => 'gone', 'reason' => MealLineSource::ORDERING_OFF]],
            $this->checkoutNotices($cart)
        );
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_lunch_gate_cannot_be_skipped_by_calling_the_source_directly(): void
    {
        $org = $this->org();
        $dish = $this->dish($org);

        $this->assertSame('available', (new MealLineSource)->reprice($dish, 1, 1200)->status);

        $org->forceFill(['capability_overrides' => ['jummah_lunch' => false]])->save();

        $this->assertSame('gone', (new MealLineSource)->reprice($dish->fresh(), 1, 1200)->status);
    }

    #[Test]
    public function a_school_has_no_lunch_or_giving_until_someone_grants_it(): void
    {
        // The defaults the doors apply, applied the same way: capabilities.php gives a school
        // neither, so its basket takes neither until a SuperAdmin grants them.
        $school = $this->org(['org_type' => 'school']);
        $cart = $this->cart($school);
        $gift = $this->add($cart, CartItem::TYPE_DONATION, $this->fund($school)->id, 5000);
        $dish = $this->add($cart, CartItem::TYPE_MEAL, $this->dish($school)->id, 1200);

        $this->assertSame('gone', $this->outcome($cart, $gift)->status);
        $this->assertSame('gone', $this->outcome($cart, $dish)->status);

        $school->forceFill(['capability_overrides' => ['giving' => true, 'jummah_lunch' => true]])->save();

        $this->assertSame('available', $this->outcome($cart, $gift)->status);
        $this->assertSame('available', $this->outcome($cart, $dish)->status);
    }

    // ------------------------------------------------------------------- files

    #[Test]
    public function a_form_that_asks_for_a_file_is_gone_and_checkout_says_so(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $tickets = $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 2, $this->twoTickets());
        $upload = $this->add($cart, CartItem::TYPE_FORM, $this->fileForm($org)->id, 2000, 1, ['fullName' => 'A. Applicant']);

        $this->assertSame('available', $this->outcome($cart, $tickets)->status, 'control: a form with no file question is on sale');

        $gone = $this->outcome($cart, $upload);
        $this->assertSame('gone', $gone->status);
        $this->assertSame(FormLineSource::FILE_FORM, $gone->reason);

        $this->assertSame(
            [['label' => 'Volunteer Application', 'status' => 'gone', 'reason' => FormLineSource::FILE_FORM]],
            $this->checkoutNotices($cart)
        );
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_form_edited_to_ask_for_a_file_after_it_was_added_is_dropped_at_checkout(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org, [
            'schema' => ['sections' => [[
                'id' => 'main', 'title' => 'Main', 'repeatable' => false,
                'fields' => [['name' => 'fullName', 'type' => 'text', 'label' => 'Name', 'required' => true]],
            ]]],
            'settings' => [
                'fee' => ['amount' => 20, 'currency' => 'USD'],
                'payment' => ['online' => true, 'officePayment' => false],
            ],
        ]);
        $cart = $this->cart($org);
        $line = $this->add($cart, CartItem::TYPE_FORM, $form->id, 2000, 1, ['fullName' => 'A. Applicant']);

        $this->assertSame('available', $this->outcome($cart, $line)->status);

        $schema = $form->schema;
        $schema['sections'][0]['fields'][] = ['name' => 'resume', 'type' => 'file', 'label' => 'Resume', 'required' => false];
        $form->forceFill(['schema' => $schema])->save();

        $this->assertSame('gone', $this->outcome($cart, $line)->status);
    }

    // --------------------------------------------------------- the doors' words

    #[Test]
    public function the_sentences_are_the_doors_own(): void
    {
        $donations = file_get_contents(app_path('Http/Controllers/Mobile/DonationsController.php'));
        $lunch = file_get_contents(app_path('Http/Controllers/Api/V1/JummahLunchOrdersController.php'));
        $kitchen = file_get_contents(app_path('Http/Controllers/Api/V1/KitchenOrdersController.php'));

        $this->assertStringContainsString(DonationLineSource::GIVING_OFF, $donations);
        $this->assertStringContainsString(MealLineSource::ORDERING_OFF, $lunch);
        $this->assertStringContainsString(MealLineSource::ORDERING_OFF, $kitchen);
    }
}
