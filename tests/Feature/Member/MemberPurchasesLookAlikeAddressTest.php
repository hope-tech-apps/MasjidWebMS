<?php

namespace Tests\Feature\Member;

use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Member\MemberPurchases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * A purchase confirmed to a LOOK-ALIKE of the member's address is not the member's
 * (slice 6, fix round 1, item 1).
 *
 * `orders.buyer_email`, `form_responses.respondent_email` and `meal_orders.customer_email`
 * are `utf8mb4_unicode_ci` on production, where `victim@gmail.com` = `victim@gmaíl.com`. A
 * member who proved the look-alike (they own its domain, so the code reached them) would
 * otherwise be shown the victim's purchases. The suite runs on SQLite, which compares bytes,
 * so the premise is built with Tests\Support\FoldsAccentsLikeUnicodeCi: the SQL `LOWER()`
 * folds accents, the look-alike rows are found by the query, and only the exact check in
 * PHP (ContactIdentity::keepExactMatches, applied inside MemberPurchases before anything
 * is counted, paginated or projected) can tell them from the real address.
 *
 * Each source has its own test, and each asserts the same four things: the SQL really
 * cannot tell the rows apart (the premise, so a green result is not an empty fixture), the
 * ownership query holds only the exact rows, the page count is the count of the exact rows
 * (not the shortlist's), and a look-alike's detail is the one 404 while a real one is a 200.
 */
class MemberPurchasesLookAlikeAddressTest extends TestCase
{
    use BuildsMemberPortal;
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the member proved. */
    private const PROVED = 'amina@gmail.com';

    /** The victim's IDN look-alike: an accent in the domain. */
    private const DOMAIN_LOOK_ALIKE = 'amina@gmaíl.com';

    /** An accent in the local part. */
    private const LOCAL_LOOK_ALIKE = 'amína@gmail.com';

    private Masjid $a;

    private Contact $me;

    private MemberPurchases $purchases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->turnMemberPortalOn();

        $this->foldAccentsLikeUnicodeCi();

        $this->a = $this->org();
        $this->me = $this->member($this->a, self::PROVED);
        $this->purchases = app(MemberPurchases::class);
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<int> */
    private function keys($query): array
    {
        $keys = array_map('intval', $query->pluck('id')->all());
        sort($keys);

        return $keys;
    }

    /**
     * The premise: the database, asked the way the portal asks it, calls every row in
     * `$table` a match, look-alikes included.
     */
    private function assertTheSqlCannotTellThemApart(string $table, string $column, int $rows): void
    {
        $this->assertSame(
            $rows,
            DB::table($table)->whereRaw("LOWER(TRIM({$column})) = ?", [self::PROVED])->count(),
            "premise: LOWER({$table}.{$column}) should fold the look-alikes onto the proved address"
        );
    }

    private function listPage(int $page, int $perPage): TestResponse
    {
        return $this->asMember($this->me)
            ->getJson($this->portalUrl($this->a, "orders?per_page={$perPage}&page={$page}"));
    }

    private function detail(string $source, string $id): TestResponse
    {
        return $this->asMember($this->me)->getJson($this->portalUrl($this->a, "orders/{$source}/{$id}"));
    }

    /**
     * Walk the pages of the HTTP list at one row a page and return the ids in order, after
     * asserting the count and the last page are the exact rows' and not the shortlist's.
     *
     * @return list<string>
     */
    private function walk(int $exactRows): array
    {
        $ids = [];

        for ($page = 1; $page <= $exactRows; $page++) {
            $response = $this->listPage($page, 1)->assertOk();

            $this->assertSame($exactRows, $response->json('data.total'), "page {$page}: the total");
            $this->assertSame($exactRows, $response->json('data.last_page'), "page {$page}: the last page");

            array_push($ids, ...array_column($response->json('data.data'), 'id'));
        }

        // Nothing is left behind the last page.
        $this->assertSame([], $this->listPage($exactRows + 1, 1)->assertOk()->json('data.data'));

        return $ids;
    }

    // ================================================================ cart orders

    #[Test]
    public function a_basket_confirmed_to_a_look_alike_address_is_not_the_members_and_the_page_count_is_the_exact_rows(): void
    {
        // Newest first by paid_at, so a leaked look-alike would head the list.
        $domainLookAlike = $this->cartOrder($this->a, ['buyer_email' => self::DOMAIN_LOOK_ALIKE, 'paid_at' => '2026-09-07 12:00:00']);
        $localLookAlike = $this->cartOrder($this->a, ['buyer_email' => self::LOCAL_LOOK_ALIKE, 'paid_at' => '2026-09-06 12:00:00']);
        $typedWithCapitals = $this->cartOrder($this->a, ['buyer_email' => '  Amina@GMAIL.com ', 'paid_at' => '2026-09-05 12:00:00']);
        $plain = $this->cartOrder($this->a, ['buyer_email' => self::PROVED, 'paid_at' => '2026-09-04 12:00:00']);
        // Theirs by contact whatever address was typed: the exact check is on the address arm only.
        $byContact = $this->cartOrder($this->a, ['contact_id' => $this->me->id, 'buyer_email' => self::DOMAIN_LOOK_ALIKE, 'paid_at' => '2026-09-03 12:00:00']);

        $this->assertTheSqlCannotTellThemApart('orders', 'buyer_email', 5);

        $expected = [$typedWithCapitals->id, $plain->id, $byContact->id];
        sort($expected);

        $this->assertSame($expected, $this->keys($this->purchases->cartOrders($this->me)));
        $this->assertSame(3, $this->purchases->orderPage($this->me, 1)->total());
        $this->assertSame(3, $this->purchases->orderPage($this->me, 1)->lastPage());

        $this->assertNull($this->purchases->find($this->me, 'manara', $domainLookAlike->uuid));
        $this->assertNull($this->purchases->find($this->me, 'manara', $localLookAlike->uuid));
        $this->assertNotNull($this->purchases->find($this->me, 'manara', $plain->uuid));

        $this->assertSame([$typedWithCapitals->uuid, $plain->uuid, $byContact->uuid], $this->walk(3));

        $this->detail('manara', $domainLookAlike->uuid)->assertNotFound();
        $this->detail('manara', $localLookAlike->uuid)->assertNotFound();
        $this->detail('manara', $plain->uuid)->assertOk();
        $this->detail('manara', $byContact->uuid)->assertOk();
    }

    // ============================================================== form responses

    #[Test]
    public function a_festival_ticket_confirmed_to_a_look_alike_address_is_not_the_members_and_the_page_count_is_the_exact_rows(): void
    {
        $form = $this->form($this->a);

        $domainLookAlike = $this->formResponse($this->a, $form, self::DOMAIN_LOOK_ALIKE, ['paid_at' => '2026-09-07 12:00:00']);
        $localLookAlike = $this->formResponse($this->a, $form, self::LOCAL_LOOK_ALIKE, ['paid_at' => '2026-09-06 12:00:00']);
        $typedWithCapitals = $this->formResponse($this->a, $form, 'AMINA@gmail.COM ', ['paid_at' => '2026-09-05 12:00:00']);
        $plain = $this->formResponse($this->a, $form, self::PROVED, ['paid_at' => '2026-09-04 12:00:00']);

        $this->assertTheSqlCannotTellThemApart('form_responses', 'respondent_email', 4);

        $expected = [$typedWithCapitals->id, $plain->id];
        sort($expected);

        $this->assertSame($expected, $this->keys($this->purchases->formPurchases($this->me)));
        $this->assertSame(2, $this->purchases->orderPage($this->me, 1)->total());
        $this->assertSame(2, $this->purchases->orderPage($this->me, 1)->lastPage());

        $this->assertNull($this->purchases->find($this->me, 'form', (string) $domainLookAlike->id));
        $this->assertNull($this->purchases->find($this->me, 'form', (string) $localLookAlike->id));
        $this->assertNotNull($this->purchases->find($this->me, 'form', (string) $plain->id));

        $this->assertSame([(string) $typedWithCapitals->id, (string) $plain->id], $this->walk(2));

        $this->detail('form', (string) $domainLookAlike->id)->assertNotFound();
        $this->detail('form', (string) $localLookAlike->id)->assertNotFound();
        $this->detail('form', (string) $plain->id)->assertOk();
    }

    // ================================================================ meal orders

    #[Test]
    public function a_lunch_confirmed_to_a_look_alike_address_is_not_the_members_and_the_page_count_is_the_exact_rows(): void
    {
        $domainLookAlike = $this->mealOrder($this->a, self::DOMAIN_LOOK_ALIKE, ['paid_at' => '2026-09-07 12:00:00']);
        $localLookAlike = $this->mealOrder($this->a, self::LOCAL_LOOK_ALIKE, ['paid_at' => '2026-09-06 12:00:00']);
        $typedWithCapitals = $this->mealOrder($this->a, ' Amina@Gmail.com', ['paid_at' => '2026-09-05 12:00:00']);
        // Theirs by contact whatever address was typed: the exact check is on the address arm only.
        $byContact = $this->mealOrder($this->a, self::DOMAIN_LOOK_ALIKE, ['contact_id' => $this->me->id, 'paid_at' => '2026-09-04 12:00:00']);

        $this->assertTheSqlCannotTellThemApart('meal_orders', 'customer_email', 4);

        $expected = [$typedWithCapitals->id, $byContact->id];
        sort($expected);

        $this->assertSame($expected, $this->keys($this->purchases->mealPurchases($this->me)));
        $this->assertSame(2, $this->purchases->orderPage($this->me, 1)->total());
        $this->assertSame(2, $this->purchases->orderPage($this->me, 1)->lastPage());

        $this->assertNull($this->purchases->find($this->me, 'meal', (string) $domainLookAlike->id));
        $this->assertNull($this->purchases->find($this->me, 'meal', (string) $localLookAlike->id));
        $this->assertNotNull($this->purchases->find($this->me, 'meal', (string) $typedWithCapitals->id));

        $this->assertSame([(string) $typedWithCapitals->id, (string) $byContact->id], $this->walk(2));

        $this->detail('meal', (string) $domainLookAlike->id)->assertNotFound();
        $this->detail('meal', (string) $localLookAlike->id)->assertNotFound();
        $this->detail('meal', (string) $typedWithCapitals->id)->assertOk();
        $this->detail('meal', (string) $byContact->id)->assertOk();
    }

    // ==================================================================== together

    #[Test]
    public function every_source_at_once_counts_only_the_exact_rows(): void
    {
        $form = $this->form($this->a);

        $this->cartOrder($this->a, ['buyer_email' => self::DOMAIN_LOOK_ALIKE]);
        $this->formResponse($this->a, $form, self::DOMAIN_LOOK_ALIKE);
        $this->mealOrder($this->a, self::DOMAIN_LOOK_ALIKE);

        $this->assertSame(0, $this->purchases->orderPage($this->me, 15)->total(), 'look-alikes alone are an empty list');

        $this->cartOrder($this->a, ['buyer_email' => self::PROVED]);
        $this->formResponse($this->a, $form, self::PROVED);
        $this->mealOrder($this->a, self::PROVED);

        $this->assertSame(3, $this->purchases->orderPage($this->me, 15)->total());
    }
}
