<?php

namespace Tests\Feature\Member;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Member\MemberPurchaseProjector;
use App\Services\Receipts\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET me/gifts and GET me/receipts/{id}/pdf — a member's own giving and the tax receipts
 * for it (slice 6). Which gifts are theirs is `contact_id` alone, and only a succeeded one;
 * a receipt has no owner column, so it is theirs when its DONATION is.
 *
 * What is pinned here:
 *  - the stack, the limiter buckets, and the door (unverified, no verified address, another
 *    organisation's path);
 *  - isolation: a neighbour's gift, another organisation's gift under the same address,
 *    and a gift that never succeeded are not listed;
 *  - the projection: exact keys, canary values seeded into every hidden field of a donation,
 *    and what each field means (the charged amount, the organisation's calendar day);
 *  - the receipt object and its note, the imported Wix gift that never has a receipt, and the
 *    voided receipt that is neither advertised nor served;
 *  - the PDF: the report-card headers plus no-store, and ONE 404 for every way of not being
 *    entitled to one;
 *  - the house paginator.
 */
class MemberGiftsAndReceiptsTest extends TestCase
{
    use BuildsMemberPortal;
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;

    private Contact $me;
    private Contact $neighbour;

    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->turnMemberPortalOn();

        $this->a = $this->org();
        $this->b = $this->org();
        $this->me = $this->member($this->a, 'amina@example.test');
        $this->neighbour = $this->member($this->a, 'zaid@example.test');
        $this->fund = $this->fund($this->a, 'Zakat');
    }

    // ---------------------------------------------------------------- helpers

    private function gifts(?Contact $as = null, string $query = '', ?Masjid $at = null): TestResponse
    {
        return $this->asMember($as ?? $this->me)
            ->getJson($this->portalUrl($at ?? $this->a, 'gifts') . $query);
    }

    private function pdf(string $id, ?Contact $as = null, ?Masjid $at = null): TestResponse
    {
        return $this->asMember($as ?? $this->me)
            ->getJson($this->portalUrl($at ?? $this->a, "receipts/{$id}/pdf"));
    }

    /** The receipt ReceiptService issues for a gift, exactly as a Stripe gift gets one. */
    private function issue(Donation $gift): DonationReceipt
    {
        $this->unbound();

        $receipt = app(ReceiptService::class)->issueFor($gift);
        $this->assertNotNull($receipt, 'the fixture gift did not issue a receipt');

        return $receipt;
    }

    /** A receipt row written by hand, for the gifts ReceiptService would never issue one for. */
    private function strayReceipt(Donation $gift, int $serial): DonationReceipt
    {
        $this->unbound();

        return DonationReceipt::create([
            'masjid_id' => $gift->masjid_id,
            'donation_id' => $gift->id,
            'serial_number' => $serial,
            'issue_date' => '2026-03-05',
            'gross_amount' => (int) $gift->charged_amount,
            'advantage_amount' => 0,
            'eligible_amount' => (int) $gift->charged_amount,
            'currency' => $gift->currency,
            'jurisdiction' => 'US',
            'status' => 'issued',
        ]);
    }

    /** The office withdraws a receipt (`donation_receipts.status` allows `void`; no code path writes it yet). */
    private function voidReceiptOf(Donation $gift): void
    {
        $this->unbound();

        DonationReceipt::withoutMasjidScope()
            ->where('donation_id', $gift->id)
            ->update(['status' => DonationReceipt::STATUS_VOID]);
    }

    /** @return list<string> */
    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data.data'), 'id');
    }

    private function assertKeys(array $expected, array $actual, string $where): void
    {
        sort($expected);
        $keys = array_keys($actual);
        sort($keys);

        $this->assertSame($expected, $keys, "{$where}: the keys are an allowlist, not a suggestion");
    }

    // ================================================================== the door

    #[Test]
    public function the_routes_carry_the_member_realms_full_stack_and_their_own_buckets(): void
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            foreach (['me/gifts', 'me/receipts/{id}/pdf'] as $suffix) {
                if (str_ends_with($route->uri(), '/' . $suffix)) {
                    $found[$suffix] = $route;
                }
            }
        }

        $this->assertCount(2, $found);

        $limiters = ['me/gifts' => 'throttle:30,1,member-portal', 'me/receipts/{id}/pdf' => 'throttle:20,1,member-receipt'];

        foreach ($found as $suffix => $route) {
            $middleware = $route->gatherMiddleware();

            foreach (['throttle:mobile', 'auth:family', 'member.active', 'member.token', 'family.tenant', 'crm'] as $gate) {
                $this->assertContains($gate, $middleware, "{$suffix} lacks {$gate}");
            }

            $this->assertContains($limiters[$suffix], $middleware, "{$suffix} lacks its own limiter");
            $this->assertSame(['GET', 'HEAD'], $route->methods(), 'nothing here writes');
        }
    }

    #[Test]
    public function the_routes_are_named_under_the_prefix_the_error_envelope_matches_and_their_refusals_carry_data(): void
    {
        $expected = [
            'mobile.member.me.gifts.index' => '/me/gifts',
            'mobile.member.me.receipts.pdf' => '/me/receipts/{id}/pdf',
        ];

        foreach ($expected as $name => $suffix) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} does not exist");
            $this->assertStringEndsWith($suffix, '/' . $route->uri());
        }

        // The refusals of both doors, without a token: the app cannot decode a body with no `data`.
        $gift = $this->gift($this->a, $this->fund, $this->me);
        $this->issue($gift);

        $list = $this->getJson($this->portalUrl($this->a, 'gifts'))->assertStatus(401);
        $this->assertSame('{"status":"error","message":"Unauthenticated.","data":{}}', $list->getContent());

        Auth::forgetGuards();
        $pdf = $this->getJson($this->portalUrl($this->a, "receipts/{$gift->uuid}/pdf"))->assertStatus(401);
        $this->assertSame('{"status":"error","message":"Unauthenticated.","data":{}}', $pdf->getContent());
    }

    #[Test]
    public function the_door_refuses_no_token_an_unverified_member_and_a_path_naming_another_organisation(): void
    {
        $gift = $this->gift($this->a, $this->fund, $this->me);
        $this->issue($gift);

        $this->getJson($this->portalUrl($this->a, 'gifts'))->assertStatus(401);
        $this->getJson($this->portalUrl($this->a, "receipts/{$gift->uuid}/pdf"))->assertStatus(401);

        $unverified = $this->member($this->a, 'unverified@example.test', verified: false);
        $theirs = $this->gift($this->a, $this->fund, $unverified);
        $this->issue($theirs);

        $this->gifts($unverified)->assertStatus(401);
        $this->pdf($theirs->uuid, $unverified)->assertStatus(401);

        $this->gifts($this->me, '', $this->b)->assertStatus(403);
        $this->pdf($gift->uuid, $this->me, $this->b)->assertStatus(403);
    }

    #[Test]
    public function a_verified_member_with_no_login_email_gets_an_empty_page_and_no_receipt(): void
    {
        $noAddress = $this->member($this->a, 'gone@example.test');
        $gift = $this->gift($this->a, $this->fund, $noAddress);
        $this->issue($gift);
        $noAddress->forceFill(['login_email' => null])->save();

        $response = $this->gifts($noAddress)->assertOk();

        $this->assertSame([], $response->json('data.data'));
        $this->assertSame(0, $response->json('data.total'));
        $this->pdf($gift->uuid, $noAddress)->assertNotFound();
    }

    // ============================================================== isolation

    #[Test]
    public function a_member_sees_only_their_own_succeeded_gifts(): void
    {
        $sameAddressElsewhere = $this->member($this->b, 'amina@example.test');
        $fundB = $this->fund($this->b);

        $mine = $this->gift($this->a, $this->fund, $this->me, 5000, ['donated_at' => '2026-03-04']);
        $this->gift($this->a, $this->fund, $this->me, 5000, ['status' => 'pending']);
        $this->gift($this->a, $this->fund, $this->me, 5000, ['status' => 'failed']);
        $this->gift($this->a, $this->fund, $this->me, 5000, ['status' => 'refunded']);
        $theirs = $this->gift($this->a, $this->fund, $this->neighbour);
        $this->gift($this->a, $this->fund, null);
        $elsewhere = $this->gift($this->b, $fundB, $sameAddressElsewhere);

        $this->assertSame([$mine->uuid], $this->ids($this->gifts($this->me)));
        $this->assertSame([$theirs->uuid], $this->ids($this->gifts($this->neighbour)));
        $this->assertSame([$elsewhere->uuid], $this->ids($this->gifts($sameAddressElsewhere, '', $this->b)));
    }

    // ================================================================ projection

    #[Test]
    public function a_gift_carries_exactly_the_allowed_keys_and_nothing_seeded_as_secret(): void
    {
        $gift = $this->gift($this->a, $this->fund, $this->me, 5000, [
            'application_fee_amount' => 3133731,
            'stripe_fee_amount' => 7654321,
            'net_amount' => 4444444,
            'note' => 'CANARY-NOTE',
            'check_number' => 'CANARY-CHECK',
            'stripe_subscription_id' => 'sub_CANARY',
            'stripe_invoice_id' => 'in_CANARY',
            'stripe_checkout_session_id' => 'cs_CANARY',
            'stripe_balance_transaction_id' => 'txn_CANARY',
        ]);
        $this->issue($gift);

        $response = $this->gifts()->assertOk();
        $row = $response->json('data.data.0');

        $this->assertKeys(
            ['amount_minor', 'currency', 'date', 'fund_name', 'id', 'is_zakat', 'receipt', 'receipt_note'],
            $row,
            'a gift'
        );
        $this->assertKeys(['id', 'serial'], $row['receipt'], 'a gift\'s receipt');

        $body = $response->getContent();

        $this->assertStringNotContainsString('CANARY', $body);
        $this->assertStringNotContainsString('stripe', $body);

        foreach (['3133731', '7654321', '4444444', (string) $gift->idempotency_key] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    #[Test]
    public function a_gift_says_what_was_charged_which_fund_and_the_day_it_was_at_the_organisation(): void
    {
        // A card gift that covered its fees: 4,800 to the fund, 5,000 off the card. The receipt's
        // gross is the 5,000, so that is the amount.
        $dated = $this->gift($this->a, $this->fund, $this->me, 5000, [
            'intended_amount' => 4800,
            'donor_covers_fees' => true,
            'is_zakat' => true,
            'donated_at' => '2026-03-04',
        ]);
        // No date of its own: the day it was entered, at 03:30 UTC on the 6th, which is 23:30 on
        // the 5th in New York.
        $entered = $this->gift($this->a, $this->fund, $this->me, 2500, [
            'donated_at' => null,
            'created_at' => '2026-09-06 03:30:00',
        ]);

        $rows = $this->gifts()->assertOk()->json('data.data');

        $this->assertSame([
            'id' => $entered->uuid,
            'date' => '2026-09-05',
            'fund_name' => 'Zakat',
            'amount_minor' => 2500,
            'currency' => 'usd',
            'is_zakat' => false,
            'receipt' => null,
            'receipt_note' => MemberPurchaseProjector::RECEIPT_NOTE_GIFT_NONE,
        ], $rows[0]);

        $this->assertSame([
            'id' => $dated->uuid,
            'date' => '2026-03-04',
            'fund_name' => 'Zakat',
            'amount_minor' => 5000,
            'currency' => 'usd',
            'is_zakat' => true,
            'receipt' => null,
            'receipt_note' => MemberPurchaseProjector::RECEIPT_NOTE_GIFT_NONE,
        ], $rows[1]);
    }

    #[Test]
    public function a_gift_with_an_issued_receipt_carries_its_id_and_serial_and_no_note(): void
    {
        // The neighbour's receipt takes serial 1, so the serial below is this member's own.
        $this->issue($this->gift($this->a, $this->fund, $this->neighbour));
        $mine = $this->gift($this->a, $this->fund, $this->me);
        $this->issue($mine);

        $row = $this->gifts()->assertOk()->json('data.data.0');

        $this->assertSame(['id' => $mine->uuid, 'serial' => 2], $row['receipt']);
        $this->assertNull($row['receipt_note']);
    }

    #[Test]
    public function a_voided_receipt_is_neither_advertised_on_the_gift_nor_served_as_a_pdf(): void
    {
        $gift = $this->gift($this->a, $this->fund, $this->me);
        $this->issue($gift);

        // The control: while the receipt is issued the row carries it and the PDF is served, so
        // what changes below is the status and nothing else.
        $this->assertNotNull($this->gifts()->assertOk()->json('data.data.0.receipt'));
        $this->pdf($gift->uuid)->assertOk();

        $this->voidReceiptOf($gift);

        $row = $this->gifts()->assertOk()->json('data.data.0');

        $this->assertNull($row['receipt']);
        $this->assertSame(MemberPurchaseProjector::RECEIPT_NOTE_GIFT_VOID, $row['receipt_note']);
        $this->assertStringContainsString('voided', $row['receipt_note']);

        $response = $this->pdf($gift->uuid)->assertNotFound();
        $this->assertNotSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function an_imported_wix_gift_has_no_receipt_and_says_why_even_if_a_stray_receipt_row_exists(): void
    {
        $wix = $this->gift($this->a, $this->fund, $this->me, 3000, ['source' => Donation::SOURCE_HISTORICAL]);
        $this->strayReceipt($wix, 700);

        $row = $this->gifts()->assertOk()->json('data.data.0');

        $this->assertNull($row['receipt']);
        $this->assertSame(MemberPurchaseProjector::RECEIPT_NOTE_GIFT_WIX, $row['receipt_note']);
        $this->assertStringContainsString('Wix', $row['receipt_note']);

        // ...and the document is not reachable by its handle either.
        $this->pdf($wix->uuid)->assertNotFound();
    }

    #[Test]
    public function the_list_is_newest_first_and_the_house_paginator(): void
    {
        $days = ['2026-03-04', '2026-03-03', '2026-03-02'];
        $uuids = [];

        foreach ($days as $day) {
            $uuids[] = $this->gift($this->a, $this->fund, $this->me, 1000, ['donated_at' => $day])->uuid;
        }

        $first = $this->gifts(null, '?per_page=2')->assertOk();

        $first->assertJsonStructure([
            'status',
            'data' => [
                'current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url',
                'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total',
            ],
        ]);
        $this->assertSame(array_slice($uuids, 0, 2), $this->ids($first));
        $this->assertSame(2, $first->json('data.per_page'));
        $this->assertSame(3, $first->json('data.total'));
        $this->assertSame(2, $first->json('data.last_page'));
        $this->assertNotNull($first->json('data.next_page_url'));

        $second = $this->gifts(null, '?per_page=2&page=2')->assertOk();

        $this->assertSame([$uuids[2]], $this->ids($second));
        $this->assertNull($second->json('data.next_page_url'));

        $this->assertSame(50, $this->gifts(null, '?per_page=1000')->json('data.per_page'));
        $this->assertSame(15, $this->gifts(null, '?per_page=abc')->json('data.per_page'));
    }

    #[Test]
    public function the_page_links_keep_the_page_size_that_was_asked_for(): void
    {
        $uuids = [];

        foreach (['2026-03-05', '2026-03-04', '2026-03-03', '2026-03-02', '2026-03-01'] as $day) {
            $uuids[] = $this->gift($this->a, $this->fund, $this->me, 1000, ['donated_at' => $day])->uuid;
        }

        $first = $this->gifts(null, '?per_page=2')->assertOk();

        foreach (['first_page_url', 'last_page_url', 'next_page_url'] as $key) {
            $this->assertStringContainsString('per_page=2', (string) $first->json("data.{$key}"), $key);
        }

        foreach ($first->json('data.links') as $link) {
            if ($link['url'] !== null) {
                $this->assertStringContainsString('per_page=2', $link['url'], 'the link ' . $link['label']);
            }
        }

        // Followed as a client follows it: page 2 of the SAME size, so no gift is shown twice.
        $second = $this->asMember($this->me)->getJson($first->json('data.next_page_url'))->assertOk();

        $this->assertSame(2, $second->json('data.current_page'));
        $this->assertSame(2, $second->json('data.per_page'));
        $this->assertSame(array_slice($uuids, 2, 2), $this->ids($second));
        $this->assertStringContainsString('per_page=2', (string) $second->json('data.next_page_url'));
        $this->assertStringContainsString('per_page=2', (string) $second->json('data.prev_page_url'));
    }

    #[Test]
    public function a_gifts_that_share_a_day_keep_one_order_across_pages(): void
    {
        $uuids = [];

        for ($i = 0; $i < 4; $i++) {
            $uuids[] = $this->gift($this->a, $this->fund, $this->me, 1000, ['donated_at' => '2026-03-04'])->uuid;
        }

        $pageOne = $this->ids($this->gifts(null, '?per_page=2'));
        $pageTwo = $this->ids($this->gifts(null, '?per_page=2&page=2'));

        // Same date on all four: the id breaks the tie, newest entered first, and no gift is on
        // two pages or on none.
        $this->assertSame(array_reverse($uuids), array_merge($pageOne, $pageTwo));
    }

    // ==================================================================== the PDF

    #[Test]
    public function a_member_downloads_their_own_receipt_with_the_report_card_headers_and_no_store(): void
    {
        // The neighbour's receipt is serial 1; a document rendered from the wrong row would be
        // named for it.
        $this->issue($this->gift($this->a, $this->fund, $this->neighbour));
        $mine = $this->gift($this->a, $this->fund, $this->me);
        $this->issue($mine);

        $response = $this->pdf($mine->uuid)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('donation-receipt-2.pdf', $response->headers->get('Content-Disposition'));
        // A tax document naming a donor: no proxy copy, no disk copy.
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertStringStartsWith('%PDF-', $response->content());
    }

    #[Test]
    public function every_way_of_not_being_entitled_to_a_receipt_is_one_and_the_same_404(): void
    {
        $sameAddressElsewhere = $this->member($this->b, 'amina@example.test');
        $fundB = $this->fund($this->b);

        $mine = $this->gift($this->a, $this->fund, $this->me);
        $this->issue($mine);

        $theirs = $this->gift($this->a, $this->fund, $this->neighbour);
        $this->issue($theirs);

        $elsewhere = $this->gift($this->b, $fundB, $sameAddressElsewhere);
        $this->issue($elsewhere);

        $pending = $this->gift($this->a, $this->fund, $this->me, 1000, ['status' => 'pending']);
        $this->strayReceipt($pending, 800);

        $wix = $this->gift($this->a, $this->fund, $this->me, 1000, ['source' => Donation::SOURCE_HISTORICAL]);
        $this->strayReceipt($wix, 801);

        $noReceipt = $this->gift($this->a, $this->fund, $this->me, 1000);

        $voided = $this->gift($this->a, $this->fund, $this->me, 1000);
        $this->issue($voided);
        $this->voidReceiptOf($voided);

        // The control: the same route hands the member their own.
        $this->pdf($mine->uuid)->assertOk();

        $cases = [
            'a neighbour\'s receipt' => $theirs->uuid,
            'another organisation\'s, at this address' => $elsewhere->uuid,
            'no such gift' => (string) Str::uuid(),
            'a junk handle' => 'junk',
            'the row number of my own gift' => (string) $mine->id,
            'my own gift that never succeeded' => $pending->uuid,
            'my own imported Wix gift' => $wix->uuid,
            'my own gift with no receipt' => $noReceipt->uuid,
            'my own gift whose receipt was voided' => $voided->uuid,
        ];

        $bodies = [];

        foreach ($cases as $label => $id) {
            $response = $this->pdf($id);

            $response->assertStatus(404);
            $this->assertNotSame('application/pdf', $response->headers->get('Content-Type'), $label);
            $bodies[$label] = $response->getContent();
        }

        $this->assertCount(
            1,
            array_unique($bodies),
            'the refusals differ, and the difference is a disclosure: ' . json_encode($bodies)
        );

        $decoded = json_decode(reset($bodies), true);
        $this->assertSame('error', $decoded['status']);
        $this->assertSame([], $decoded['data']);
        $this->assertStringNotContainsString($theirs->uuid, reset($bodies));
    }

    #[Test]
    public function a_receipt_is_the_members_by_their_donation_and_never_by_an_address(): void
    {
        // A gift with no contact at all (a walk-in, an unmatched import): nobody can fetch it,
        // even the member whose address is on the receipt e-mail.
        $anonymous = $this->gift($this->a, $this->fund, null, 5000, ['donated_at' => '2026-03-04']);
        $this->issue($anonymous);

        $this->pdf($anonymous->uuid)->assertNotFound();
        $this->assertSame([], $this->gifts()->assertOk()->json('data.data'));
    }
}
