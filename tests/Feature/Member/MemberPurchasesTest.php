<?php

namespace Tests\Feature\Member;

use App\Models\Contact;
use App\Models\HistoricalOrder;
use App\Models\MealOrder;
use App\Models\OrderItem;
use App\Models\Masjid;
use App\Services\Member\MemberPurchases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The linking rule of a member's orders, gifts and receipts, asked of the service directly
 * (slice 6): WHICH rows are theirs. What they are shown of each row, and the door they
 * come through, are the endpoint suites' business (MemberOrdersTest,
 * MemberGiftsAndReceiptsTest).
 *
 * The rule: a purchase is a member's when it was confirmed to their VERIFIED address
 * (`login_email` while `verified_at` is set), or, for the sources that carry one, when
 * `contact_id` is theirs, always inside their own organisation. Every case below is one
 * clause of it, written so that removing the clause is what fails: a test that passes
 * with the clause gone is decoration.
 */
class MemberPurchasesTest extends TestCase
{
    use BuildsMemberPortal;
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;

    private MemberPurchases $purchases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->org();
        $this->b = $this->org();

        $this->purchases = app(MemberPurchases::class);
    }

    /** The key a query yields, in any order. */
    private function keys($query, string $column = 'id'): array
    {
        return array_map('intval', $query->pluck($column)->all());
    }

    // ================================================================ cart orders

    #[Test]
    public function a_paid_cart_order_is_the_members_by_contact_or_by_their_verified_address(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $other = $this->member($this->a, 'zaid@example.test');

        $byContact = $this->cartOrder($this->a, ['contact_id' => $me->id, 'buyer_email' => null]);
        $byAddress = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $this->cartOrder($this->a, ['buyer_email' => 'zaid@example.test']);
        $this->cartOrder($this->a, ['contact_id' => $other->id, 'buyer_email' => 'someone@example.test']);
        $this->cartOrder($this->a, ['buyer_email' => null]);

        $this->assertEqualsCanonicalizing(
            [$byContact->id, $byAddress->id],
            $this->keys($this->purchases->cartOrders($me))
        );
    }

    #[Test]
    public function only_a_paid_cart_order_is_listed(): void
    {
        $me = $this->member($this->a, 'amina@example.test');

        $paid = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'status' => 'pending', 'paid_at' => null]);
        $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'status' => 'expired', 'paid_at' => null]);

        $this->assertSame([$paid->id], $this->keys($this->purchases->cartOrders($me)));
    }

    #[Test]
    public function the_address_match_ignores_case_and_the_space_around_it(): void
    {
        $form = $this->form($this->a);
        $me = $this->member($this->a, 'amina.yusuf@example.test');

        $order = $this->cartOrder($this->a, ['buyer_email' => '  Amina.Yusuf@Example.TEST  ']);
        $response = $this->formResponse($this->a, $form, 'AMINA.YUSUF@example.test ');
        $meal = $this->mealOrder($this->a, ' amina.yusuf@EXAMPLE.test');

        $this->assertSame([$order->id], $this->keys($this->purchases->cartOrders($me)));
        $this->assertSame([$response->id], $this->keys($this->purchases->formPurchases($me)));
        $this->assertSame([$meal->id], $this->keys($this->purchases->mealPurchases($me)));

        // And the other way round: the member's own stored address may carry capitals.
        $me->forceFill(['login_email' => 'Amina.YUSUF@example.TEST'])->save();

        $this->assertSame([$order->id], $this->keys($this->purchases->cartOrders($me->refresh())));
        $this->assertSame([$response->id], $this->keys($this->purchases->formPurchases($me)));
        $this->assertSame([$meal->id], $this->keys($this->purchases->mealPurchases($me)));
    }

    #[Test]
    public function an_address_only_matches_whole_not_as_a_part_of_a_longer_one(): void
    {
        $me = $this->member($this->a, 'amina@example.test');

        $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test.evil.test']);
        $this->cartOrder($this->a, ['buyer_email' => 'xamina@example.test']);
        $this->cartOrder($this->a, ['buyer_email' => 'amina@example.tes']);

        $this->assertSame([], $this->keys($this->purchases->cartOrders($me)));
    }

    // ================================================================ Wix history

    #[Test]
    public function wix_history_is_linked_by_contact_and_every_status_is_listed(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $other = $this->member($this->a, 'zaid@example.test');

        $paid = $this->wixOrder($this->a, $me);
        $canceled = $this->wixOrder($this->a, $me, ['status' => HistoricalOrder::STATUS_CANCELED]);
        $declined = $this->wixOrder($this->a, $me, ['status' => HistoricalOrder::STATUS_DECLINED]);
        $this->wixOrder($this->a, $other);
        $this->wixOrder($this->a, null);

        $this->assertEqualsCanonicalizing(
            [$paid->id, $canceled->id, $declined->id],
            $this->keys($this->purchases->historicalOrders($me))
        );
    }

    // ============================================================ door purchases

    #[Test]
    public function a_form_response_is_listed_only_with_a_paid_money_leg_at_the_address(): void
    {
        $form = $this->form($this->a);
        $me = $this->member($this->a, 'amina@example.test');

        $online = $this->formResponse($this->a, $form, 'amina@example.test');
        $cash = $this->formResponse($this->a, $form, 'amina@example.test', ['payment_method' => 'cash']);
        $this->formResponse($this->a, $form, 'amina@example.test', ['payment_status' => 'unpaid', 'paid_at' => null]);
        // Every row before the festival, and a form that charges nothing: no money leg at all.
        $this->formResponse($this->a, $form, 'amina@example.test', ['payment_method' => null, 'payment_status' => null]);
        $this->formResponse($this->a, $form, 'zaid@example.test');
        $this->formResponse($this->a, $form, null);

        $this->assertEqualsCanonicalizing(
            [$online->id, $cash->id],
            $this->keys($this->purchases->formPurchases($me))
        );
    }

    #[Test]
    public function a_paid_status_without_a_payment_method_is_not_a_money_leg(): void
    {
        $form = $this->form($this->a);
        $me = $this->member($this->a, 'amina@example.test');

        $listed = $this->formResponse($this->a, $form, 'amina@example.test');
        // `payment_status = paid` and `payment_method` NULL: a half-written money leg, or an
        // admin edit. Only the method clause excludes it: the status alone says paid.
        $this->formResponse($this->a, $form, 'amina@example.test', ['payment_method' => null]);

        $this->assertSame([$listed->id], $this->keys($this->purchases->formPurchases($me)));
    }

    #[Test]
    public function a_meal_order_is_listed_only_when_paid_and_by_address_or_contact(): void
    {
        $me = $this->member($this->a, 'amina@example.test');

        $byAddress = $this->mealOrder($this->a, 'amina@example.test');
        $byContact = $this->mealOrder($this->a, null, ['contact_id' => $me->id]);
        $this->mealOrder($this->a, 'amina@example.test', ['payment_status' => MealOrder::PAYMENT_UNPAID]);
        $this->mealOrder($this->a, 'amina@example.test', ['payment_status' => MealOrder::PAYMENT_REFUNDED]);
        $this->mealOrder($this->a, 'zaid@example.test');
        $this->mealOrder($this->a, null);

        $this->assertEqualsCanonicalizing(
            [$byAddress->id, $byContact->id],
            $this->keys($this->purchases->mealPurchases($me))
        );
    }

    #[Test]
    public function a_door_record_a_cart_order_already_lists_is_not_listed_a_second_time(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $form = $this->form($this->a);

        $ownedForm = $this->formResponse($this->a, $form, 'amina@example.test');
        $freeForm = $this->formResponse($this->a, $form, 'amina@example.test');
        $ownedMeal = $this->mealOrder($this->a, 'amina@example.test');
        $freeMeal = $this->mealOrder($this->a, 'amina@example.test');

        $order = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $this->orderRecords($order, OrderItem::RECORD_FORM_RESPONSE, $ownedForm->id);
        $this->orderRecords($order, OrderItem::RECORD_MEAL_ORDER, $ownedMeal->id);

        $this->assertSame([$freeForm->id], $this->keys($this->purchases->formPurchases($me)));
        $this->assertSame([$freeMeal->id], $this->keys($this->purchases->mealPurchases($me)));

        // One purchase, one row: the order, the form the door took alone, the meal likewise.
        $page = $this->purchases->orderPage($me, 15);
        $rows = collect($page->items())->map(fn ($r) => $r->portal_source . ':' . (int) $r->portal_id)->all();

        $this->assertEqualsCanonicalizing([
            'manara:' . $order->id,
            'form:' . $freeForm->id,
            'meal:' . $freeMeal->id,
        ], $rows);
        $this->assertSame(3, $page->total());
    }

    #[Test]
    public function an_order_line_excludes_only_the_kind_of_record_it_names(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $form = $this->form($this->a);

        // A decoy first, so the form response and the meal order below do NOT share a number.
        $this->formResponse($this->a, $form, 'decoy@example.test');
        $response = $this->formResponse($this->a, $form, 'amina@example.test');
        $meal = $this->mealOrder($this->a, 'amina@example.test');
        $this->assertNotSame($response->id, $meal->id, 'premise: the two rows have different numbers');

        // A line that recorded a MEAL order carrying this form response's number, one that
        // recorded a FORM response carrying this meal order's number, and two gifts. Row
        // numbers are per table, so a rule that looked at record_id alone would hide both.
        $order = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $this->orderRecords($order, OrderItem::RECORD_MEAL_ORDER, $response->id);
        $this->orderRecords($order, OrderItem::RECORD_FORM_RESPONSE, $meal->id);
        $this->orderRecords($order, OrderItem::RECORD_DONATION, $response->id);
        $this->orderRecords($order, OrderItem::RECORD_DONATION, $meal->id);

        $this->assertSame([$response->id], $this->keys($this->purchases->formPurchases($me)));
        $this->assertSame([$meal->id], $this->keys($this->purchases->mealPurchases($me)));
    }

    #[Test]
    public function an_order_line_of_another_organisation_hides_none_of_this_ones_rows(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $form = $this->form($this->a);

        $response = $this->formResponse($this->a, $form, 'amina@example.test');
        $meal = $this->mealOrder($this->a, 'amina@example.test');

        // The control: a line of THIS organisation's own order that records a row hides it, so
        // the fixture reaches the exclusion at all.
        $ownedResponse = $this->formResponse($this->a, $form, 'amina@example.test');
        $ownedMeal = $this->mealOrder($this->a, 'amina@example.test');
        $own = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $this->orderRecords($own, OrderItem::RECORD_FORM_RESPONSE, $ownedResponse->id);
        $this->orderRecords($own, OrderItem::RECORD_MEAL_ORDER, $ownedMeal->id);

        // An order of ORGANISATION B whose lines record rows carrying these very numbers.
        // The exclusion is about one organisation's orders: without its own `masjid_id`
        // clause the other organisation's line would hide this member's rows.
        $foreign = $this->cartOrder($this->b, ['buyer_email' => 'someone@example.test']);
        $this->orderRecords($foreign, OrderItem::RECORD_FORM_RESPONSE, $response->id);
        $this->orderRecords($foreign, OrderItem::RECORD_MEAL_ORDER, $meal->id);

        $this->assertSame([$response->id], $this->keys($this->purchases->formPurchases($me)));
        $this->assertSame([$meal->id], $this->keys($this->purchases->mealPurchases($me)));
    }

    // ============================================================== isolation

    #[Test]
    public function another_member_another_organisation_and_the_same_address_elsewhere_see_none_of_it(): void
    {
        $address = 'amina@example.test';

        $meA = $this->member($this->a, $address);
        // The same address at another organisation is a different person and a different account.
        $meB = $this->member($this->b, $address);
        $neighbour = $this->member($this->a, 'zaid@example.test');

        $formA = $this->form($this->a);
        $formB = $this->form($this->b);
        $fundA = $this->fund($this->a);
        $fundB = $this->fund($this->b);

        $mineA = [
            'cart' => $this->cartOrder($this->a, ['buyer_email' => $address])->id,
            'wix' => $this->wixOrder($this->a, $meA)->id,
            'form' => $this->formResponse($this->a, $formA, $address)->id,
            'meal' => $this->mealOrder($this->a, $address)->id,
            'gift' => $this->gift($this->a, $fundA, $meA)->id,
        ];
        $mineB = [
            'cart' => $this->cartOrder($this->b, ['buyer_email' => $address])->id,
            'wix' => $this->wixOrder($this->b, $meB)->id,
            'form' => $this->formResponse($this->b, $formB, $address)->id,
            'meal' => $this->mealOrder($this->b, $address)->id,
            'gift' => $this->gift($this->b, $fundB, $meB)->id,
        ];

        foreach ([[$meA, $mineA], [$meB, $mineB]] as [$member, $own]) {
            $this->assertSame([$own['cart']], $this->keys($this->purchases->cartOrders($member)));
            $this->assertSame([$own['wix']], $this->keys($this->purchases->historicalOrders($member)));
            $this->assertSame([$own['form']], $this->keys($this->purchases->formPurchases($member)));
            $this->assertSame([$own['meal']], $this->keys($this->purchases->mealPurchases($member)));
            $this->assertSame([$own['gift']], $this->keys($this->purchases->gifts($member)));
            $this->assertSame(4, $this->purchases->orderPage($member, 15)->total());
        }

        foreach ([
            'cartOrders', 'historicalOrders', 'formPurchases', 'mealPurchases', 'gifts',
        ] as $source) {
            $this->assertSame(0, $this->purchases->{$source}($neighbour)->count(), "a neighbour saw {$source}");
        }

        $this->assertSame(0, $this->purchases->orderPage($neighbour, 15)->total());
    }

    #[Test]
    public function a_member_never_reads_another_organisations_rows_that_carry_their_contact_id_or_address(): void
    {
        // Contact ids are global, so a contact-keyed row in another organisation naming this
        // member's id would be found by `contact_id` alone. The organisation is asked as well.
        $me = $this->member($this->a, 'amina@example.test');

        $this->cartOrder($this->b, ['contact_id' => $me->id, 'buyer_email' => 'amina@example.test']);
        $this->wixOrder($this->b, $me);
        $this->mealOrder($this->b, 'amina@example.test', ['contact_id' => $me->id]);
        $this->gift($this->b, $this->fund($this->b), $me);
        $this->formResponse($this->b, $this->form($this->b), 'amina@example.test');

        $this->assertSame(0, $this->purchases->orderPage($me, 15)->total());
        $this->assertSame(0, $this->purchases->gifts($me)->count());
    }

    // =========================================== no verified address, no lists

    #[Test]
    public function without_a_verified_address_every_list_is_empty_even_where_a_contact_link_exists(): void
    {
        $form = $this->form($this->a);
        $fund = $this->fund($this->a);

        // The control: a member with a verified address sees one row in every list, so an
        // empty answer below is the rule at work and not an empty fixture.
        $verified = $this->member($this->a, 'control@example.test');
        $this->rowsFor($verified, $form, $fund);
        $this->assertSame(1, $this->purchases->cartOrders($verified)->count());
        $this->assertSame(1, $this->purchases->historicalOrders($verified)->count());
        $this->assertSame(1, $this->purchases->formPurchases($verified)->count());
        $this->assertSame(1, $this->purchases->mealPurchases($verified)->count());
        $this->assertSame(1, $this->purchases->gifts($verified)->count());
        $this->assertSame(4, $this->purchases->orderPage($verified, 15)->total());

        $noAddress = $this->member($this->a, 'noaddress@example.test');
        $this->rowsFor($noAddress, $form, $fund);
        $noAddress->forceFill(['login_email' => null])->save();

        $unverified = $this->member($this->a, 'unverified@example.test', verified: false);
        $this->rowsFor($unverified, $form, $fund);

        $revoked = $this->member($this->a, 'revoked@example.test');
        $this->rowsFor($revoked, $form, $fund);
        $revoked->forceFill(['login_revoked_at' => now()])->save();

        $blank = $this->member($this->a, '   ');
        $this->rowsFor($blank, $form, $fund);

        foreach ([$noAddress, $unverified, $revoked, $blank] as $contact) {
            $contact = $contact->refresh();

            $this->assertNull($this->purchases->verifiedAddress($contact));

            foreach (['cartOrders', 'historicalOrders', 'formPurchases', 'mealPurchases', 'gifts'] as $source) {
                $this->assertSame(
                    0,
                    $this->purchases->{$source}($contact)->count(),
                    "{$source} was not empty for {$contact->login_email}"
                );
            }

            $this->assertSame(0, $this->purchases->orderPage($contact, 15)->total());
        }
    }

    #[Test]
    public function a_soft_deleted_member_gets_empty_lists_though_the_row_still_holds_a_verified_address(): void
    {
        $form = $this->form($this->a);
        $fund = $this->fund($this->a);

        $gone = $this->member($this->a, 'gone@example.test');
        $this->rowsFor($gone, $form, $fund);

        // The control: alive, they have one row in every list.
        $this->assertSame(4, $this->purchases->orderPage($gone, 15)->total());
        $this->assertSame(1, $this->purchases->gifts($gone)->count());

        $gone->delete();
        $gone = Contact::withoutMasjidScope()->withTrashed()->findOrFail($gone->id);

        // The premise: the deleted row still carries the address and its verification, so
        // only the liveness check keeps the lists empty.
        $this->assertTrue($gone->trashed());
        $this->assertNotNull($gone->verified_at);
        $this->assertSame('gone@example.test', $gone->login_email);

        $this->assertNull($this->purchases->verifiedAddress($gone));

        foreach (['cartOrders', 'historicalOrders', 'formPurchases', 'mealPurchases', 'gifts'] as $source) {
            $this->assertSame(0, $this->purchases->{$source}($gone)->count(), "{$source} was not empty for a deleted member");
        }

        $this->assertSame(0, $this->purchases->orderPage($gone, 15)->total());
    }

    /** One row in every source that would be this contact's: by contact where that is a key, by address otherwise. */
    private function rowsFor(Contact $contact, $form, $fund): void
    {
        $address = $contact->login_email;

        $this->cartOrder($this->a, ['contact_id' => $contact->id, 'buyer_email' => $address]);
        $this->wixOrder($this->a, $contact);
        $this->formResponse($this->a, $form, $address);
        $this->mealOrder($this->a, $address, ['contact_id' => $contact->id]);
        $this->gift($this->a, $fund, $contact);
    }

    // ============================================================ the list itself

    #[Test]
    public function the_list_is_every_source_newest_first_and_paginated_with_a_total_order(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $form = $this->form($this->a);

        $manara = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => '2026-09-05 12:00:00']);
        $formRow = $this->formResponse($this->a, $form, 'amina@example.test', ['paid_at' => '2026-09-04 12:00:00']);
        $meal = $this->mealOrder($this->a, 'amina@example.test', ['paid_at' => '2026-09-03 12:00:00']);
        $wix = $this->wixOrder($this->a, $me, ['ordered_at' => '2025-01-01 09:00:00']);

        $first = $this->purchases->orderPage($me, 3);

        $this->assertSame(4, $first->total());
        $this->assertSame(2, $first->lastPage());
        $this->assertSame(
            ['manara:' . $manara->id, 'form:' . $formRow->id, 'meal:' . $meal->id],
            collect($first->items())->map(fn ($r) => $r->portal_source . ':' . (int) $r->portal_id)->all()
        );

        request()->query->set('page', 2);
        $second = $this->purchases->orderPage($me, 3);

        $this->assertSame(
            ['wix:' . $wix->id],
            collect($second->items())->map(fn ($r) => $r->portal_source . ':' . (int) $r->portal_id)->all()
        );
    }

    #[Test]
    public function rows_at_the_same_moment_keep_one_order_so_a_page_never_repeats_or_drops_one(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $at = '2024-06-01 08:00:00';

        $wix = $this->wixOrder($this->a, $me, ['ordered_at' => $at]);
        $olderManara = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => $at]);
        $newerManara = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => $at]);

        // Time, then source name ascending, then the key descending.
        $this->assertSame(
            ['manara:' . $newerManara->id, 'manara:' . $olderManara->id, 'wix:' . $wix->id],
            collect($this->purchases->orderPage($me, 15)->items())
                ->map(fn ($r) => $r->portal_source . ':' . (int) $r->portal_id)->all()
        );
    }

    #[Test]
    public function load_reads_each_row_through_its_ownership_query_again(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $other = $this->member($this->a, 'zaid@example.test');

        $kept = $this->wixOrder($this->a, $me);
        $moved = $this->wixOrder($this->a, $me);
        $order = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);

        $page = $this->purchases->orderPage($me, 15);

        // Between the page and the read, an office merge moves one order to someone else.
        $moved->forceFill(['contact_id' => $other->id])->save();

        $models = $this->purchases->load($me, $page->items());

        $this->assertSame([$kept->id], $models['wix']->keys()->all());
        $this->assertSame([$order->id], $models['manara']->keys()->all());
        $this->assertTrue($models['manara']->first()->relationLoaded('items'));
    }

    // ================================================================== find()

    #[Test]
    public function find_answers_null_for_every_way_of_not_having_it(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $other = $this->member($this->a, 'zaid@example.test');
        $form = $this->form($this->a);

        $mine = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $theirs = $this->cartOrder($this->a, ['buyer_email' => 'zaid@example.test']);
        $foreign = $this->cartOrder($this->b, ['buyer_email' => 'amina@example.test']);
        $wixMine = $this->wixOrder($this->a, $me);
        $wixTheirs = $this->wixOrder($this->a, $other);
        $ownedForm = $this->formResponse($this->a, $form, 'amina@example.test');
        $this->orderRecords($mine, OrderItem::RECORD_FORM_RESPONSE, $ownedForm->id);

        // The ones that are theirs come back.
        $this->assertSame($mine->id, $this->purchases->find($me, 'manara', $mine->uuid)?->id);
        $this->assertSame($mine->id, $this->purchases->find($me, 'manara', strtoupper($mine->uuid))?->id);
        $this->assertSame($wixMine->id, $this->purchases->find($me, 'wix', (string) $wixMine->id)?->id);

        // The ones that are not, for whatever reason, are all the same answer.
        $this->assertNull($this->purchases->find($me, 'manara', $theirs->uuid), 'a neighbour\'s order');
        $this->assertNull($this->purchases->find($me, 'manara', $foreign->uuid), 'another organisation\'s order');
        $this->assertNull($this->purchases->find($me, 'manara', (string) Str::uuid()), 'no such order');
        $this->assertNull($this->purchases->find($me, 'manara', 'not-a-uuid'), 'junk handle');
        $this->assertNull($this->purchases->find($me, 'manara', (string) $mine->id), 'a number where a uuid belongs');
        $this->assertNull($this->purchases->find($me, 'wix', (string) $wixTheirs->id), 'a neighbour\'s Wix order');
        $this->assertNull($this->purchases->find($me, 'wix', $mine->uuid), 'a uuid where a number belongs');
        $this->assertNull($this->purchases->find($me, 'wix', '0'), 'zero');
        $this->assertNull($this->purchases->find($me, 'wix', '007'), 'a leading zero');
        $this->assertNull($this->purchases->find($me, 'wix', '1e3'), 'a float');
        $this->assertNull($this->purchases->find($me, 'wix', '99999999999999999999'), 'past a bigint');
        $this->assertNull($this->purchases->find($me, 'form', (string) $ownedForm->id), 'a form response the cart order lists');
        $this->assertNull($this->purchases->find($me, 'nonsense', (string) $wixMine->id), 'an unknown source');
    }

    #[Test]
    public function a_gift_is_found_by_uuid_only_for_its_donor(): void
    {
        $me = $this->member($this->a, 'amina@example.test');
        $other = $this->member($this->a, 'zaid@example.test');
        $fund = $this->fund($this->a);

        $mine = $this->gift($this->a, $fund, $me);
        $theirs = $this->gift($this->a, $fund, $other);
        $pending = $this->gift($this->a, $fund, $me, 1000, ['status' => 'pending']);

        $this->assertSame($mine->id, $this->purchases->findGift($me, $mine->uuid)?->id);
        $this->assertNull($this->purchases->findGift($me, $theirs->uuid));
        $this->assertNull($this->purchases->findGift($me, $pending->uuid), 'a gift that never succeeded');
        $this->assertNull($this->purchases->findGift($me, 'junk'));
        $this->assertNull($this->purchases->findGift($me, (string) $mine->id));
    }

    // =============================================================== timezone

    #[Test]
    public function the_organisations_timezone_is_used_when_it_is_a_real_one_and_utc_otherwise(): void
    {
        $bad = $this->org(['timezone' => 'Not/AZone']);

        $this->assertSame('America/New_York', $this->purchases->timezoneFor($this->member($this->a)));
        $this->assertSame('UTC', $this->purchases->timezoneFor($this->member($bad)));
    }
}
