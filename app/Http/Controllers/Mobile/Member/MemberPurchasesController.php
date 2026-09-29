<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Member\MemberPurchaseProjector;
use App\Services\Member\MemberPurchases;
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
 * Nothing here writes. The portal does not offer to delete an order or a gift; erasing a
 * record the office keeps is an office act (MemberAccountDeletion).
 *
 * Pinned by tests/Feature/Member/MemberOrdersTest.php.
 */
class MemberPurchasesController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private MemberPurchases $purchases,
        private MemberPurchaseProjector $projector,
    ) {
    }

    /**
     * GET me/orders — every purchase of this member, newest first, one page at a time.
     *
     * Paginated the way the admin lists are: `data` is the paginator, `data.data` the rows.
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

        return $this->ok($page->setCollection($rows));
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
