<?php

namespace Tests\Feature\Member;

use App\Models\Contact;
use App\Models\HistoricalOrder;
use App\Models\Masjid;
use App\Models\MealOrder;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Member\MemberPurchaseProjector;
use App\Support\MobileErrorEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET me/orders and GET me/orders/{source}/{id} — the endpoints of a member's orders
 * (slice 6). Which rows are theirs is MemberPurchasesTest's business; this file holds the
 * door, what crosses it, and the shape it arrives in:
 *
 *  - the routes carry the member realm's full stack and a limiter of their own;
 *  - another member, another organisation and the same address at another organisation see
 *    nothing of each other's, and every way of not having an order is one 404;
 *  - a member with no verified address gets an empty page, never an error;
 *  - every row is built by hand: the keys are asserted EXACTLY, and every field that must
 *    not leave the server is seeded with a canary that the raw body is searched for;
 *  - a Wix order that was canceled or declined is never shown as paid, and a basket's refund
 *    or dispute shows as its status;
 *  - the dates are the organisation's calendar day and the amounts are minor units;
 *  - the page is the house's paginator, newest first.
 */
class MemberOrdersTest extends TestCase
{
    use BuildsMemberPortal;
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;

    /** The member under test, at organisation A. */
    private Contact $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->org();
        $this->b = $this->org();
        $this->me = $this->member($this->a, 'amina@example.test');
    }

    // ---------------------------------------------------------------- helpers

    private function orders(?Contact $as = null, string $query = '', ?Masjid $at = null): TestResponse
    {
        return $this->asMember($as ?? $this->me)
            ->getJson($this->portalUrl($at ?? $this->a, 'orders') . $query);
    }

    private function detail(array $row, ?Contact $as = null): TestResponse
    {
        return $this->asMember($as ?? $this->me)
            ->getJson($this->portalUrl($this->a, "orders/{$row['source']}/{$row['id']}"));
    }

    /** @return list<string> the `id` of every row on the page */
    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data.data'), 'id');
    }

    /** @param  list<string>  $expected */
    private function assertKeys(array $expected, array $actual, string $where): void
    {
        sort($expected);
        $keys = array_keys($actual);
        sort($keys);

        $this->assertSame($expected, $keys, "{$where}: the keys are an allowlist, not a suggestion");
    }

    /**
     * One purchase in every source, newest first: the basket (6 Sept), a lunch (3 Sept), the
     * festival form (1 Sept) and a Wix order (2024).
     *
     * @return array{manara: \App\Models\Order, meal: MealOrder, form: \App\Models\FormResponse, wix: HistoricalOrder}
     */
    private function portfolio(): array
    {
        $form = $this->form($this->a, 'Fall Festival');

        return [
            'manara' => $this->cartOrder(
                $this->a,
                ['buyer_email' => 'amina@example.test', 'paid_at' => '2026-09-06 03:30:00'],
                [['Zakat-ul-Fitr', 1, 5000], ['Iftar sponsorship', 2, 6800], ['Dates', 1, 300], ['Rugs', 3, 1000]]
            ),
            'meal' => $this->mealOrder(
                $this->a,
                'amina@example.test',
                [
                    'paid_at' => '2026-09-03 12:00:00',
                    'donation_minor' => 500,
                    'fee_covered_minor' => 60,
                    'subtotal_minor' => 1600,
                    'total_minor' => 2160,
                ],
                [['Chicken Biryani', 2, 800]]
            ),
            'form' => $this->formResponse($this->a, $form, 'amina@example.test'),
            'wix' => $this->wixOrder($this->a, $this->me),
        ];
    }

    // ================================================================== the door

    #[Test]
    public function the_routes_carry_the_member_realms_full_stack_and_a_limiter_of_their_own(): void
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            foreach (['me/orders', 'me/orders/{source}/{id}'] as $suffix) {
                if (str_ends_with($route->uri(), '/' . $suffix)) {
                    $found[$suffix] = $route;
                }
            }
        }

        $this->assertCount(2, $found, 'both order routes exist under the mobile member prefix');

        foreach ($found as $suffix => $route) {
            $middleware = $route->gatherMiddleware();

            foreach (['throttle:mobile', 'auth:family', 'member.active', 'member.token', 'family.tenant', 'crm'] as $gate) {
                $this->assertContains($gate, $middleware, "{$suffix} lacks {$gate}");
            }

            $own = array_values(array_filter(
                $middleware,
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && $m !== 'throttle:mobile'
            ));

            $this->assertCount(1, $own, "{$suffix} has one limiter of its own");
            $this->assertStringEndsWith(',member-portal', $own[0], 'with a prefix, so it does not share a bucket with monthly giving');
            $this->assertSame(['GET', 'HEAD'], $route->methods(), 'nothing here writes');
        }
    }

    #[Test]
    public function no_token_a_staff_token_and_a_family_portal_token_are_all_refused(): void
    {
        $url = $this->portalUrl($this->a, 'orders');

        $this->getJson($url)->assertStatus(401);

        // A staff token: `auth:family` resolves it against contacts and finds nobody.
        $staff = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $staff->createToken('staff')->plainTextToken)
            ->getJson($url)
            ->assertStatus(401);

        // The same contact's FAMILY-portal token: `member.active` passes (they are verified),
        // and `member.token` is what says this credential belongs to another door.
        Auth::forgetGuards();
        $this->unbound();
        $this->withHeader('Authorization', 'Bearer ' . $this->me->createFamilyToken()->plainTextToken)
            ->getJson($url)
            ->assertStatus(403);
    }

    #[Test]
    public function an_unverified_member_is_refused_at_the_door_and_never_reaches_a_list(): void
    {
        $unverified = $this->member($this->a, 'unverified@example.test', verified: false);
        $this->cartOrder($this->a, ['contact_id' => $unverified->id, 'buyer_email' => 'unverified@example.test']);

        $this->orders($unverified)->assertStatus(401);
    }

    #[Test]
    public function the_realm_is_dark_when_the_organisation_has_the_crm_off(): void
    {
        $dark = $this->org(['crm_enabled' => false]);
        $member = $this->member($dark, 'amina@example.test');

        $this->orders($member, '', $dark)->assertStatus(403);
    }

    #[Test]
    public function a_token_cannot_be_pointed_at_another_organisation_by_editing_the_path(): void
    {
        $this->cartOrder($this->b, ['buyer_email' => 'amina@example.test']);

        $this->orders($this->me, '', $this->b)->assertStatus(403);
    }

    #[Test]
    public function a_verified_member_with_no_login_email_gets_an_empty_page_not_an_error(): void
    {
        $noAddress = $this->member($this->a, 'gone@example.test');
        $wix = $this->wixOrder($this->a, $noAddress);
        $this->cartOrder($this->a, ['contact_id' => $noAddress->id]);
        $noAddress->forceFill(['login_email' => null])->save();

        $response = $this->orders($noAddress)->assertOk();

        $this->assertSame('success', $response->json('status'));
        $this->assertSame([], $response->json('data.data'));
        $this->assertSame(0, $response->json('data.total'));

        // ...and the detail of an order that is keyed to them by contact is the same 404.
        $this->detail(['source' => 'wix', 'id' => (string) $wix->id], $noAddress)->assertNotFound();
    }

    // ============================================================== isolation

    #[Test]
    public function a_member_sees_only_their_own_orders_and_the_same_address_elsewhere_is_someone_else(): void
    {
        $neighbour = $this->member($this->a, 'zaid@example.test');
        $sameAddressElsewhere = $this->member($this->b, 'amina@example.test');

        $mine = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $theirs = $this->cartOrder($this->a, ['buyer_email' => 'zaid@example.test']);
        $elsewhere = $this->cartOrder($this->b, ['buyer_email' => 'amina@example.test']);

        $this->assertSame([$mine->uuid], $this->ids($this->orders($this->me)));
        $this->assertSame([$theirs->uuid], $this->ids($this->orders($neighbour)));
        $this->assertSame(
            [$elsewhere->uuid],
            $this->ids($this->orders($sameAddressElsewhere, '', $this->b))
        );
    }

    #[Test]
    public function every_way_of_not_having_an_order_is_one_and_the_same_404(): void
    {
        $neighbour = $this->member($this->a, 'zaid@example.test');
        $form = $this->form($this->a);

        $mine = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $theirs = $this->cartOrder($this->a, ['buyer_email' => 'zaid@example.test']);
        $elsewhere = $this->cartOrder($this->b, ['buyer_email' => 'amina@example.test']);
        $wixMine = $this->wixOrder($this->a, $this->me);
        $wixTheirs = $this->wixOrder($this->a, $neighbour);
        $theirMeal = $this->mealOrder($this->a, 'zaid@example.test');
        $ownedForm = $this->formResponse($this->a, $form, 'amina@example.test');
        $this->orderRecords($mine, OrderItem::RECORD_FORM_RESPONSE, $ownedForm->id);

        // The control: the same route answers 200 for what is theirs, so the 404s below are
        // not a broken route.
        $this->asMember($this->me)
            ->getJson($this->portalUrl($this->a, "orders/manara/{$mine->uuid}"))
            ->assertOk();

        $cases = [
            'a neighbour\'s order' => ['manara', $theirs->uuid],
            'another organisation\'s order, at this address' => ['manara', $elsewhere->uuid],
            'no such order' => ['manara', (string) Str::uuid()],
            'a junk handle' => ['manara', 'junk'],
            'a number where a uuid belongs' => ['manara', (string) $mine->id],
            'an unknown source' => ['nonsense', (string) $wixMine->id],
            'a neighbour\'s Wix order' => ['wix', (string) $wixTheirs->id],
            'no such Wix order' => ['wix', '999999'],
            'a form response the basket already lists' => ['form', (string) $ownedForm->id],
            'a neighbour\'s lunch' => ['meal', (string) $theirMeal->id],
        ];

        $bodies = [];

        foreach ($cases as $label => [$source, $id]) {
            $response = $this->asMember($this->me)
                ->getJson($this->portalUrl($this->a, "orders/{$source}/{$id}"));

            $response->assertStatus(404);
            $bodies[$label] = $response->getContent();
        }

        $this->assertCount(
            1,
            array_unique($bodies),
            'the refusals differ, and the difference is a disclosure: ' . json_encode($bodies)
        );
        $this->assertStringNotContainsString($theirs->uuid, reset($bodies));
        $this->assertSame('error', json_decode(reset($bodies), true)['status']);
        $this->assertSame([], json_decode(reset($bodies), true)['data']);
    }

    #[Test]
    public function a_purchase_the_basket_lists_is_listed_once(): void
    {
        $form = $this->form($this->a);
        $order = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test']);
        $owned = $this->formResponse($this->a, $form, 'amina@example.test');
        $this->orderRecords($order, OrderItem::RECORD_FORM_RESPONSE, $owned->id);

        $response = $this->orders()->assertOk();

        $this->assertSame([$order->uuid], $this->ids($response));
        $this->assertSame(1, $response->json('data.total'));
    }

    // ================================================================ projection

    #[Test]
    public function every_row_and_every_detail_carries_exactly_the_allowed_keys_and_nothing_seeded_as_secret(): void
    {
        $portfolio = $this->portfolio();

        $list = $this->orders()->assertOk();
        $rows = $list->json('data.data');

        $this->assertSame(['manara', 'meal', 'form', 'wix'], array_column($rows, 'source'));

        $bodies = [$list->getContent()];

        foreach ($rows as $row) {
            $this->assertKeys(
                ['currency', 'date', 'id', 'number', 'source', 'status', 'summary', 'total_minor'],
                $row,
                "the {$row['source']} row"
            );
            $this->assertKeys(['count', 'labels'], $row['summary'], "the {$row['source']} summary");

            $detail = $this->detail($row)->assertOk();
            $body = $detail->json('data');

            $this->assertKeys(
                ['currency', 'date', 'id', 'lines', 'number', 'receipt_note', 'source', 'status', 'totals'],
                $body,
                "the {$row['source']} detail"
            );
            $this->assertKeys(['discount_minor', 'subtotal_minor', 'total_minor'], $body['totals'], "the {$row['source']} totals");
            $this->assertNotEmpty($body['lines']);

            foreach ($body['lines'] as $line) {
                $this->assertKeys(['label', 'line_minor', 'quantity', 'unit_minor'], $line, "a {$row['source']} line");
            }

            $bodies[] = $detail->getContent();
        }

        foreach ($bodies as $body) {
            // Every hidden field of every row was seeded with CANARY: Stripe ids, the pinned
            // account, the fingerprint, the payload and price snapshot, the buyer's typed name,
            // the form answers, the Wix options and import batch.
            $this->assertStringNotContainsString('CANARY', $body);

            // What no member is shown, by name.
            foreach (['fee_minor', 'stripe', 'charge_account', 'fingerprint', 'idempotency', 'payload', 'batch', 'options'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "{$forbidden} reached a member");
            }

            // The bearer handles of the form response and the lunch order, and both fees.
            $this->assertStringNotContainsString($portfolio['form']->uuid, $body);
            $this->assertStringNotContainsString($portfolio['meal']->uuid, $body);
            $this->assertStringNotContainsString('3133731', $body, 'the basket\'s application fee');
            $this->assertStringNotContainsString('7654321', $body, 'the fee Wix added');
        }
    }

    #[Test]
    public function the_list_says_what_each_purchase_was_in_the_organisations_calendar_and_in_minor_units(): void
    {
        $portfolio = $this->portfolio();

        $rows = $this->orders()->assertOk()->json('data.data');

        // The basket: paid at 03:30 UTC on the 6th, which is 23:30 on the 5th in New York.
        $this->assertSame([
            'source' => 'manara',
            'id' => $portfolio['manara']->uuid,
            'number' => $portfolio['manara']->order_number,
            'date' => '2026-09-05',
            'status' => 'paid',
            'total_minor' => 21900,
            'currency' => 'usd',
            'summary' => ['labels' => ['Zakat-ul-Fitr', 'Iftar sponsorship', 'Dates'], 'count' => 4],
        ], $rows[0]);

        $this->assertSame([
            'source' => 'meal',
            'id' => (string) $portfolio['meal']->id,
            'number' => (string) $portfolio['meal']->order_number,
            'date' => '2026-09-03',
            'status' => 'paid',
            'total_minor' => 2160,
            'currency' => 'usd',
            'summary' => ['labels' => ['Chicken Biryani', 'Optional donation', 'Card fee covered'], 'count' => 3],
        ], $rows[1]);

        $this->assertSame([
            'source' => 'form',
            'id' => (string) $portfolio['form']->id,
            'number' => (string) $portfolio['form']->id,
            'date' => '2026-09-01',
            'status' => 'paid',
            'total_minor' => 3000,
            'currency' => 'usd',
            'summary' => ['labels' => ['Fall Festival (Adult)'], 'count' => 1],
        ], $rows[2]);

        $this->assertSame([
            'source' => 'wix',
            'id' => (string) $portfolio['wix']->id,
            'number' => $portfolio['wix']->order_number,
            'date' => '2024-03-05',
            'status' => 'paid',
            'total_minor' => 3300,
            'currency' => 'usd',
            'summary' => ['labels' => ['Ramadan Date Box'], 'count' => 1],
        ], $rows[3]);
    }

    #[Test]
    public function the_detail_lists_the_lines_then_the_totals_and_says_there_is_no_receipt_for_a_purchase(): void
    {
        $portfolio = $this->portfolio();
        $rows = collect($this->orders()->assertOk()->json('data.data'))->keyBy('source');

        $note = 'A confirmation was emailed; there is no tax receipt for a purchase.';
        $this->assertSame($note, MemberPurchaseProjector::RECEIPT_NOTE_PURCHASE);

        // The basket.
        $manara = $this->detail($rows['manara'])->assertOk()->json('data');
        $this->assertSame([
            ['label' => 'Zakat-ul-Fitr', 'quantity' => 1, 'unit_minor' => 5000, 'line_minor' => 5000],
            ['label' => 'Iftar sponsorship', 'quantity' => 2, 'unit_minor' => 6800, 'line_minor' => 13600],
            ['label' => 'Dates', 'quantity' => 1, 'unit_minor' => 300, 'line_minor' => 300],
            ['label' => 'Rugs', 'quantity' => 3, 'unit_minor' => 1000, 'line_minor' => 3000],
        ], $manara['lines']);
        $this->assertSame(['subtotal_minor' => 21900, 'discount_minor' => 0, 'total_minor' => 21900], $manara['totals']);
        $this->assertSame($note, $manara['receipt_note']);

        // The lunch: the dishes, then the two extras the customer added, so the lines add up.
        $meal = $this->detail($rows['meal'])->assertOk()->json('data');
        $this->assertSame([
            ['label' => 'Chicken Biryani', 'quantity' => 2, 'unit_minor' => 800, 'line_minor' => 1600],
            ['label' => 'Optional donation', 'quantity' => 1, 'unit_minor' => 500, 'line_minor' => 500],
            ['label' => 'Card fee covered', 'quantity' => 1, 'unit_minor' => 60, 'line_minor' => 60],
        ], $meal['lines']);
        $this->assertSame(['subtotal_minor' => 2160, 'discount_minor' => 0, 'total_minor' => 2160], $meal['totals']);
        $this->assertSame($note, $meal['receipt_note']);

        // The festival form: the price the payer was quoted, one line.
        $form = $this->detail($rows['form'])->assertOk()->json('data');
        $this->assertSame([
            ['label' => 'Fall Festival (Adult)', 'quantity' => 2, 'unit_minor' => 1500, 'line_minor' => 3000],
        ], $form['lines']);
        $this->assertSame(['subtotal_minor' => 3000, 'discount_minor' => 0, 'total_minor' => 3000], $form['totals']);
        $this->assertSame($note, $form['receipt_note']);

        // The Wix order. Its total holds a checkout fee (3300 against 3000 of lines) that is
        // deliberately not shown as a fee, so the two figures are both here and need not agree.
        $wix = $this->detail($rows['wix'])->assertOk()->json('data');
        $this->assertSame([
            ['label' => 'Ramadan Date Box', 'quantity' => 2, 'unit_minor' => 1500, 'line_minor' => 3000],
        ], $wix['lines']);
        $this->assertSame(['subtotal_minor' => 3000, 'discount_minor' => 0, 'total_minor' => 3300], $wix['totals']);
        $this->assertSame(MemberPurchaseProjector::RECEIPT_NOTE_WIX_PAID, $wix['receipt_note']);
        $this->assertStringContainsString('Wix', $wix['receipt_note']);
    }

    #[Test]
    public function a_form_the_payer_covered_the_card_fee_on_lists_the_fee_so_the_lines_add_up(): void
    {
        $form = $this->form($this->a, 'Fall Festival');
        $this->formResponse($this->a, $form, 'amina@example.test', ['fee_covered_minor' => 120, 'total_minor' => 3120]);

        $row = $this->orders()->assertOk()->json('data.data.0');
        $detail = $this->detail($row)->assertOk()->json('data');

        $this->assertSame(3120, $row['total_minor']);
        $this->assertSame(
            ['label' => 'Card fee covered', 'quantity' => 1, 'unit_minor' => 120, 'line_minor' => 120],
            $detail['lines'][1]
        );
        $this->assertSame(3120, $detail['totals']['subtotal_minor']);
    }

    #[Test]
    public function a_form_response_with_no_price_breakdown_is_one_line_at_what_was_due_and_a_deleted_form_keeps_its_name(): void
    {
        $form = $this->form($this->a, 'Sisters Retreat');
        $this->formResponse($this->a, $form, 'amina@example.test', [
            'unit_price_minor' => null, 'price_quantity' => null, 'price_label' => null,
        ]);
        $form->delete();

        $row = $this->orders()->assertOk()->json('data.data.0');
        $detail = $this->detail($row)->assertOk()->json('data');

        $this->assertSame(
            [['label' => 'Sisters Retreat', 'quantity' => 1, 'unit_minor' => 3000, 'line_minor' => 3000]],
            $detail['lines']
        );
    }

    // =================================================================== status

    #[Test]
    public function a_canceled_or_declined_wix_order_is_shown_as_such_and_never_as_paid(): void
    {
        $this->wixOrder($this->a, $this->me, ['status' => HistoricalOrder::STATUS_PAID, 'ordered_at' => '2024-03-03 12:00:00']);
        $this->wixOrder($this->a, $this->me, ['status' => HistoricalOrder::STATUS_CANCELED, 'ordered_at' => '2024-03-02 12:00:00']);
        $this->wixOrder($this->a, $this->me, ['status' => HistoricalOrder::STATUS_DECLINED, 'ordered_at' => '2024-03-01 12:00:00']);

        $rows = $this->orders()->assertOk()->json('data.data');

        $this->assertSame(['paid', 'canceled', 'declined'], array_column($rows, 'status'));

        // Their total is what the order would have cost; it never moved, and the detail says so.
        $canceled = $this->detail($rows[1])->assertOk()->json('data');
        $this->assertSame('canceled', $canceled['status']);
        $this->assertSame(MemberPurchaseProjector::RECEIPT_NOTE_WIX_UNPAID, $canceled['receipt_note']);
        $this->assertStringContainsString('no payment was taken', $canceled['receipt_note']);

        $declined = $this->detail($rows[2])->assertOk()->json('data');
        $this->assertSame('declined', $declined['status']);
        $this->assertSame(MemberPurchaseProjector::RECEIPT_NOTE_WIX_UNPAID, $declined['receipt_note']);
    }

    #[Test]
    public function a_status_the_importer_never_wrote_is_not_read_as_paid(): void
    {
        $this->wixOrder($this->a, $this->me, ['status' => 'refunded']);

        $row = $this->orders()->assertOk()->json('data.data.0');
        $detail = $this->detail($row)->assertOk()->json('data');

        $this->assertSame('unknown', $row['status']);
        $this->assertSame(MemberPurchaseProjector::RECEIPT_NOTE_WIX_UNKNOWN, $detail['receipt_note']);
        $this->assertStringContainsString('not shown as paid', $detail['receipt_note']);
    }

    #[Test]
    public function a_baskets_refund_or_dispute_shows_as_its_status(): void
    {
        $refunded = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => '2026-09-05 12:00:00']);
        $partly = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => '2026-09-04 12:00:00']);
        $disputed = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => '2026-09-03 12:00:00']);
        $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => '2026-09-02 12:00:00']);

        $refunded->forceFill(['charge_flag' => 'refunded', 'charge_refunded_minor' => 5000])->save();
        $partly->forceFill(['charge_flag' => 'partially_refunded', 'charge_refunded_minor' => 1234567])->save();
        $disputed->forceFill(['charge_flag' => 'disputed'])->save();

        $response = $this->orders()->assertOk();

        $this->assertSame(['refunded', 'partially_refunded', 'disputed', 'paid'], array_column($response->json('data.data'), 'status'));
        // The amount refunded is a reconciliation figure for staff; a member is told the status only.
        $this->assertStringNotContainsString('1234567', $response->getContent());
    }

    #[Test]
    public function a_door_purchase_is_never_shown_as_paid_where_its_own_record_says_otherwise(): void
    {
        $form = $this->form($this->a);

        $this->formResponse($this->a, $form, 'amina@example.test', ['charge_flag' => 'refunded', 'paid_at' => '2026-09-05 12:00:00']);
        $this->formResponse($this->a, $form, 'amina@example.test', ['charge_flag' => 'disputed', 'paid_at' => '2026-09-04 12:00:00']);
        // A refunded card payer is cancelled AND still reads as paid: the refund is what is said.
        $this->formResponse($this->a, $form, 'amina@example.test', ['charge_flag' => 'refunded', 'paid_at' => '2026-09-03 12:00:00'], ['status' => 'cancelled']);
        $this->formResponse($this->a, $form, 'amina@example.test', ['payment_method' => 'cash', 'paid_at' => '2026-09-02 12:00:00'], ['status' => 'cancelled']);
        $this->mealOrder($this->a, 'amina@example.test', ['status' => MealOrder::STATUS_CANCELLED, 'paid_at' => '2026-09-01 12:00:00']);

        $this->assertSame(
            ['refunded', 'disputed', 'refunded', 'canceled', 'canceled'],
            array_column($this->orders()->assertOk()->json('data.data'), 'status')
        );
    }

    // =============================================================== the page

    #[Test]
    public function the_list_is_newest_first_across_every_source(): void
    {
        $this->portfolio();

        $this->assertSame(
            ['manara', 'meal', 'form', 'wix'],
            array_column($this->orders()->assertOk()->json('data.data'), 'source')
        );
    }

    #[Test]
    public function an_empty_list_is_an_empty_page_with_the_same_shape(): void
    {
        $response = $this->orders()->assertOk();

        $this->assertPageShape($response);
        $this->assertSame([], $response->json('data.data'));
        $this->assertSame(0, $response->json('data.total'));
        $this->assertSame(1, $response->json('data.current_page'));
        $this->assertNull($response->json('data.next_page_url'));
    }

    #[Test]
    public function the_list_is_the_house_paginator_and_the_page_size_is_held_to_a_range(): void
    {
        $times = ['2026-09-05', '2026-09-04', '2026-09-03', '2026-09-02', '2026-09-01'];
        $numbers = [];

        foreach ($times as $day) {
            $numbers[] = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => "{$day} 12:00:00"])->order_number;
        }

        $first = $this->orders(null, '?per_page=2')->assertOk();

        $this->assertPageShape($first);
        $this->assertSame(1, $first->json('data.current_page'));
        $this->assertSame(2, $first->json('data.per_page'));
        $this->assertSame(5, $first->json('data.total'));
        $this->assertSame(3, $first->json('data.last_page'));
        $this->assertSame(1, $first->json('data.from'));
        $this->assertSame(2, $first->json('data.to'));
        $this->assertSame(array_slice($numbers, 0, 2), array_column($first->json('data.data'), 'number'));
        $this->assertNotNull($first->json('data.next_page_url'));
        $this->assertNull($first->json('data.prev_page_url'));

        $last = $this->orders(null, '?per_page=2&page=3')->assertOk();

        $this->assertSame(3, $last->json('data.current_page'));
        $this->assertSame([$numbers[4]], array_column($last->json('data.data'), 'number'));
        $this->assertSame(5, $last->json('data.from'));
        $this->assertSame(5, $last->json('data.to'));
        $this->assertNull($last->json('data.next_page_url'));

        $this->assertSame(50, $this->orders(null, '?per_page=1000')->json('data.per_page'), 'clamped to the ceiling');
        $this->assertSame(15, $this->orders()->json('data.per_page'), 'the default');
        $this->assertSame(15, $this->orders(null, '?per_page=abc')->json('data.per_page'));
        $this->assertSame(15, $this->orders(null, '?per_page=0')->json('data.per_page'));
        $this->assertSame(15, $this->orders(null, '?per_page=-3')->json('data.per_page'));
        $this->assertSame(15, $this->orders(null, '?per_page[]=3')->json('data.per_page'), 'an array is not a number');
    }

    #[Test]
    public function the_page_links_keep_the_page_size_that_was_asked_for(): void
    {
        $numbers = [];

        foreach (['05', '04', '03', '02', '01'] as $day) {
            $numbers[] = $this->cartOrder($this->a, ['buyer_email' => 'amina@example.test', 'paid_at' => "2026-09-{$day} 12:00:00"])->order_number;
        }

        $first = $this->orders(null, '?per_page=2')->assertOk();

        foreach (['first_page_url', 'last_page_url', 'next_page_url'] as $key) {
            $this->assertStringContainsString('per_page=2', (string) $first->json("data.{$key}"), $key);
        }

        foreach ($first->json('data.links') as $link) {
            if ($link['url'] !== null) {
                $this->assertStringContainsString('per_page=2', $link['url'], 'the link ' . $link['label']);
            }
        }

        // Followed as a client follows it: page 2 of the SAME size, so no row is shown twice.
        $second = $this->asMember($this->me)->getJson($first->json('data.next_page_url'))->assertOk();

        $this->assertSame(2, $second->json('data.current_page'));
        $this->assertSame(2, $second->json('data.per_page'));
        $this->assertSame(array_slice($numbers, 2, 2), array_column($second->json('data.data'), 'number'));
        $this->assertStringContainsString('per_page=2', (string) $second->json('data.next_page_url'));
        $this->assertStringContainsString('per_page=2', (string) $second->json('data.prev_page_url'));
    }

    private function assertPageShape(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'status',
            'data' => [
                'current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url',
                'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total',
            ],
        ]);
    }

    // ============================================================ refusals

    #[Test]
    public function the_routes_are_named_under_the_prefix_the_error_envelope_matches(): void
    {
        $expected = [
            'mobile.member.me.orders.index' => '/me/orders',
            'mobile.member.me.orders.show' => '/me/orders/{source}/{id}',
        ];

        foreach ($expected as $name => $suffix) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} does not exist");
            $this->assertStringEndsWith($suffix, '/' . $route->uri());
            $this->assertTrue(Str::is(MobileErrorEnvelope::ROUTES, $name));
        }
    }

    #[Test]
    public function every_refusal_at_the_door_carries_an_empty_data_object(): void
    {
        $url = $this->portalUrl($this->a, 'orders');

        // No token: the guard's 401. The iPhone app decodes every body through a `Response<T>`
        // whose `data` is not optional, so a refusal without the key fails on the device.
        $anonymous = $this->getJson($url)->assertStatus(401);
        $this->assertSame('{"status":"error","message":"Unauthenticated.","data":{}}', $anonymous->getContent());

        // The same contact's FAMILY-portal token: `member.token`'s 403.
        Auth::forgetGuards();
        $this->unbound();
        $familyOnly = $this->withHeader('Authorization', 'Bearer ' . $this->me->createFamilyToken()->plainTextToken)
            ->getJson($url)
            ->assertStatus(403);
        $this->assertStringContainsString('"data":{}', $familyOnly->getContent());

        // A member's token pointed at another organisation's path: `family.tenant`'s 403.
        $foreign = $this->orders($this->me, '', $this->b)->assertStatus(403);
        $this->assertStringContainsString('"data":{}', $foreign->getContent());
    }

    // ================================================================ limiter

    #[Test]
    public function the_portal_has_its_own_allowance_and_does_not_spend_the_monthly_giving_screens(): void
    {
        $this->asMember($this->me);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson($this->portalUrl($this->a, 'orders'))->assertOk();
        }

        $limited = $this->getJson($this->portalUrl($this->a, 'orders'))->assertStatus(429);
        $this->assertStringContainsString('"data":{}', $limited->getContent(), 'a rapid refresh meets a 429 the app can decode');

        // The screen beside it is untouched: no commitments, so nothing is asked of Stripe.
        $this->getJson($this->portalUrl($this->a, 'recurring-giving'))->assertOk();
    }
}
