<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Donation;
use App\Services\Member\MemberPurchaseProjector;
use App\Services\Member\MemberPurchases;
use App\Services\Receipts\DonationReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Your orders" — a signed-in member reading what THEY bought, gave and were sent a
 * receipt for. Read-only, and API only: which client shows it is not decided here.
 *
 * ---------------------------------------------------------------------------
 * WHY THE MEMBER REALM, AND WHAT "THEIRS" MEANS
 * ---------------------------------------------------------------------------
 * The family portal admits only a confirmed guardian of a live ward, so the people who
 * bought festival tickets and Wix-store goods cannot enter it. The member realm is the one
 * a plain contact reaches with no office step: an e-mail code adopts `login_email` and sets
 * `verified_at`. So these routes sit beside recurring giving, behind the same five gates
 * (`auth:family`, `member.active`, `member.token`, `family.tenant`, `crm`) and a limiter.
 *
 * What is theirs is decided in ONE place, MemberPurchases: a purchase confirmed to their
 * verified address, or keyed to their contact, inside their organisation. What they see of
 * it is decided in ONE other, MemberPurchaseProjector, which builds every row by hand.
 * Nothing in this file touches a column.
 *
 * ---------------------------------------------------------------------------
 * ONE 404
 * ---------------------------------------------------------------------------
 * A miss, another member's order, another organisation's order, an order the cart already
 * lists under another row, a handle of the wrong shape and a source that does not exist
 * are the same answer, byte for byte, and none of them reaches a table before the shape is
 * checked. A 403 would confirm that the handle names something real that belongs to
 * someone else, which is a disclosure about a named person's spending. The routes carry no
 * `where` constraints for the same reason: a router 404 has a different body from this one.
 *
 * ---------------------------------------------------------------------------
 * GIFTS AND THEIR RECEIPTS
 * ---------------------------------------------------------------------------
 * A gift is theirs by `contact_id` alone, and only a succeeded one. That link was made at
 * settlement from the address the giver typed, which `donations` does not keep, so a gift
 * is not held to the verified address the way an order is (ASSUMPTIONS #61, an owner
 * question). Its receipt document has no owner column of its own: ownership is receipt,
 * then donation, then `donations.contact_id`, which is what MemberPurchases::findGift()
 * asks, so the PDF is reachable for exactly the gifts the list shows and no others. The admin download
 * (DonationsController::receiptPdf) renders the same stored row through the same service;
 * the ownership check and the headers are the family report card's (routes/family.php,
 * ReportCardsController::pdf), with the admin download's `no-store` added because this is
 * a tax document naming a donor. A bearer token cannot ride a link, so a client fetches it
 * as a blob, as the family portal does.
 *
 * Meal, form and cart purchases have no receipt document at all, and an imported Wix gift
 * is never given one (ReceiptService); the payloads say so in one field rather than showing
 * an empty space where a document should be.
 *
 * Nothing here writes. The portal does not offer to delete an order or a gift; erasing a
 * record the office keeps is an office act (MemberAccountDeletion).
 *
 * Pinned by tests/Feature/Member/MemberOrdersTest.php and
 * tests/Feature/Member/MemberGiftsAndReceiptsTest.php.
 */
class MemberPurchasesController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private MemberPurchases $purchases,
        private MemberPurchaseProjector $projector,
        private DonationReceiptPdfService $receiptPdfs,
    ) {
    }

    /**
     * GET me/orders — every purchase of this member, newest first, one page at a time.
     *
     * Paginated the way the admin lists are: `data` is the paginator, `data.data` the rows.
     * The page links keep the query string (`per_page` above all): a client that follows
     * `next_page_url` after asking for 50 must get page 2 of 50, not page 2 of the default 15,
     * which would repeat rows.
     */
    public function orders(Request $request): JsonResponse
    {
        $contact = $this->contact($request);
        $timezone = $this->purchases->timezoneFor($contact);

        $page = $this->purchases->orderPage($contact, $this->perPage($request));
        $models = $this->purchases->load($contact, $page->items());

        // A row that stopped being theirs between the page and the read is dropped rather
        // than shown; the page is then one short, which is the honest outcome.
        $rows = collect($page->items())
            ->map(function (object $row) use ($models, $timezone) {
                $model = $models[$row->portal_source][(int) $row->portal_id] ?? null;

                return $model === null
                    ? null
                    : $this->projector->orderRow((string) $row->portal_source, $model, $timezone);
            })
            ->filter()
            ->values();

        return $this->ok($page->setCollection($rows)->withQueryString());
    }

    /**
     * GET me/orders/{source}/{id} — one purchase in full: the lines, then the totals.
     *
     * `source` is manara, wix, form or meal; `id` is what the list gave.
     */
    public function order(Request $request, $masjid_id, string $source, string $id): JsonResponse
    {
        $contact = $this->contact($request);
        $model = $this->purchases->find($contact, $source, $id);

        if ($model === null) {
            return $this->notFound('order');
        }

        return $this->ok($this->projector->orderDetail(
            $source,
            $model,
            $this->purchases->timezoneFor($contact)
        ));
    }

    /**
     * GET me/gifts — this member's succeeded donations, newest first, one page at a time.
     *
     * Every kind of gift is here: Stripe, offline and imported Wix history. The order is the
     * admin ledger's (the gift's own date, else when it was entered), with the id as the
     * tie-break: donated_at is a DATE, so an imported batch shares one sort key, and under
     * LIMIT/OFFSET an unordered tie shows a gift on two pages or on none.
     */
    public function gifts(Request $request): JsonResponse
    {
        $contact = $this->contact($request);
        $timezone = $this->purchases->timezoneFor($contact);

        $page = $this->purchases->gifts($contact)
            ->with(['fund', 'receipt'])
            ->orderByRaw('COALESCE(donations.donated_at, donations.created_at) DESC, donations.id DESC')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Donation $gift) => $this->projector->gift($gift, $timezone));

        return $this->ok($page);
    }

    /**
     * GET me/receipts/{id}/pdf — the donation receipt as a file to keep.
     *
     * `id` is the gift's uuid, which is what `receipt.id` on the list carries. It is resolved
     * through the caller's own succeeded gifts, so someone else's gift, another
     * organisation's, a gift that never succeeded, one with no receipt, one whose receipt was
     * voided, an imported Wix gift and a junk handle are all the same 404. Nothing is issued or recomputed: the PDF is
     * rendered from the stored receipt row, as the admin download renders it.
     */
    public function receiptPdf(Request $request, $masjid_id, string $id): Response
    {
        $gift = $this->purchases->findGift($this->contact($request), $id);

        // The same rule as the gift row's `receipt` object: an imported Wix gift has none,
        // whatever a stray row says, and a voided receipt is not served.
        $receipt = $gift === null ? null : $this->projector->receiptOf($gift);

        if ($receipt === null) {
            return $this->notFound('receipt');
        }

        return response($this->receiptPdfs->pdfFor($receipt), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->receiptPdfs->filename($receipt) . '"',
            // A tax document naming a donor: never cached by a proxy, never written to disk
            // by the browser.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ------------------------------------------------------------------ guts

    private function contact(Request $request): Contact
    {
        /** @var Contact $contact */
        $contact = $request->user();

        return $contact;
    }

    /**
     * The page size asked for, held to a sane range. `?per_page[]=` is not a number and
     * must not be cast: it falls back to the default rather than raising a warning.
     */
    private function perPage(Request $request): int
    {
        $requested = $request->query('per_page');
        $requested = is_scalar($requested) ? (int) $requested : self::DEFAULT_PER_PAGE;

        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /** The mobile realm's legacy envelope: `{status, data}`, no `meta`. */
    private function ok(array|LengthAwarePaginator $data): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $data], Response::HTTP_OK);
    }

    /**
     * The one 404. `data` is an empty object, as the app's `Response<T>` decoder needs on
     * every body it is given.
     */
    private function notFound(string $what): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => "This {$what} was not found.",
            'data' => new \stdClass(),
        ], Response::HTTP_NOT_FOUND);
    }
}
