<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Donations\StoreOfflineDonationRequest;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Receipts\DonationReceiptPdfService;
use App\Support\DonationMetrics;
use App\Support\ZakatDesignation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: donations list (read-only for the Phase-0 spike).
 *
 * Tenant-scoped by the `tenant` middleware + BelongsToMasjid — no hand-filtering
 * by $masjid_id. See .claude/rules/tenant-scoping.md.
 */
class DonationsController extends Controller
{
    public function __construct(private DonationReceiptPdfService $receiptPdfs)
    {
    }

    public function index(Request $request, $masjid_id)
    {
        $donations = self::filteredQuery($request)
            ->with(['fund', 'receipt', 'contact'])
            // Newest gift first — by the real gift date for offline history, else
            // entry. donated_at is a DATE, so an imported batch shares one sort key;
            // id breaks the tie because MySQL's order among equal keys is otherwise
            // arbitrary per execution, and under LIMIT/OFFSET that lets a gift show
            // up on two pages or on none.
            ->orderByRaw('COALESCE(donated_at, created_at) DESC, id DESC')
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $donations,
        ], Response::HTTP_OK);
    }

    /**
     * The single filter contract behind BOTH the ledger page and the CSV export
     * (DonationExportController), so what the accountant downloads can never
     * disagree with what the admin is looking at.
     *
     * Validated here rather than in a FormRequest because a rejected filter has to
     * be loud: a mistyped `from` that degraded into "no filter" would hand an
     * accountant a report covering years they never asked for. Failures throw the
     * app's standard 422 envelope (same shape as BaseFormRequest).
     *
     * $masjid is needed only for its timezone (see the date window below) and is
     * accepted so a caller that already loaded the row does not fetch it twice;
     * left out, it is read from the route.
     */
    public static function filteredQuery(Request $request, ?Masjid $masjid = null): Builder
    {
        $query = $request->query();

        $rules = [
            'status' => ['nullable', 'in:pending,succeeded,failed,refunded'],
            'fund_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'in:stripe,offline'],
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Zakat-only (or zakat-excluded) view of the ledger, and therefore
            // of the CSV the treasurer reconciles the restricted pot against.
            'zakat' => ['nullable', 'boolean'],
        ];

        // Compare the two ends only when `from` actually carries a date. Laravel
        // resolves the reference by value: an ABSENT `from` reads as null and the
        // comparison then passes trivially, but a PRESENT-but-empty one (?from=&to=…,
        // a cleared date input) is parsed by Carbon as *now*, which 422s any
        // open-ended "everything up to 2024" export. The empty string is the case
        // that has to be excluded here — `!== null` would let it through.
        $from = $query['from'] ?? null;

        if (is_string($from) && trim($from) !== '') {
            $rules['to'][] = 'after_or_equal:from';
        }

        $validator = Validator::make($query, $rules);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $filters = $validator->validated();

        $donations = Donation::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['fund_id'] ?? null, fn ($q, $fundId) => $q->where('fund_id', $fundId))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            // Optional donor search (name or email).
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->whereHas('contact', function ($c) use ($search) {
                    $c->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        // Full name, so "Ahmad Fais" (first + last together) matches.
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                        ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"]);
                });
            });

        // Date window: borrowed from DonationMetrics, never restated. from/to are
        // calendar days at the MASJID (masjids.timezone), but created_at is stored
        // in the app timezone — config/app.php pins that to UTC while every live
        // tenant is US-Eastern, so a day boundary is a different literal for each
        // of the two date columns. DonationMetrics already resolves that split for
        // the stats header; the ledger and the CSV go through the same predicate so
        // the header can never report a different set of gifts than the rows and
        // the export beneath it. Only loaded when there is a window to apply.
        if (($filters['from'] ?? null) !== null || ($filters['to'] ?? null) !== null) {
            [$windowSql, $windowBindings] = DonationMetrics::forMasjid(
                $masjid ?? Masjid::find($request->route('masjid_id'))
            )->windowSqlForFilters($filters);

            $donations->whereRaw($windowSql, $windowBindings);
        }

        // Applied only when the caller actually sent a value. `when()` cannot be
        // used: it treats a legitimate `zakat=0` ("show me the NON-zakat gifts")
        // as absent and returns the unfiltered ledger. The empty string gets the
        // same treatment `from` does above — a cleared filter input means no
        // filter, not "false".
        $zakat = $query['zakat'] ?? null;

        if ($zakat !== null && (! is_string($zakat) || trim($zakat) !== '')) {
            $donations->where('is_zakat', filter_var($zakat, FILTER_VALIDATE_BOOLEAN));
        }

        return $donations;
    }

    /**
     * Record a manual OFFLINE donation (cash/check/Zelle/…). Stripe donations are
     * still webhook-only; this path exists for gifts that never touch Stripe. The
     * row is booked succeeded, source=offline, dated to when it was given, with no
     * receipt (an offline gift isn't a Stripe-verified event). Tenant-scoped:
     * fund/contact are validated to belong to this masjid before the write.
     */
    public function store(StoreOfflineDonationRequest $request, $masjid_id)
    {
        // Validate fund + contact belong to THIS masjid (bound tenant scopes these).
        $fund = Fund::findOrFail($request->integer('fund_id'));
        $contactId = null;
        if ($request->filled('contact_id')) {
            $contactId = Contact::findOrFail($request->integer('contact_id'))->id;
        }

        $cents = (int) round(((float) $request->validated('amount')) * 100);

        // SOURCE_ADMIN: an administrator recorded the designation on the giver's
        // behalf, which is a weaker provenance than the giver typing it into the
        // donation form themselves. The distinction is stored rather than
        // flattened — a treasurer auditing the restricted pot should be able to
        // see which zakat gifts rest on a staff member's note.
        $zakat = ZakatDesignation::resolve(
            $request->has('zakat') ? $request->boolean('zakat') : null,
            $fund,
            ZakatDesignation::SOURCE_ADMIN
        );

        $donation = Donation::create([
            'contact_id' => $contactId,
            'fund_id' => $fund->id,
            'type' => 'one_time',
            'is_zakat' => $zakat['is_zakat'],
            'zakat_source' => $zakat['zakat_source'],
            'source' => 'offline',
            'payment_method' => $request->validated('payment_method'),
            'check_number' => $request->validated('payment_method') === 'check' ? $request->input('check_number') : null,
            'donated_at' => $request->validated('donated_at'),
            'note' => $request->input('note'),
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => strtolower((string) config('services.stripe.currency', 'usd')),
            'donor_covers_fees' => false,
            'status' => 'succeeded',
            'idempotency_key' => 'offline_' . Str::uuid(),
        ]);   // masjid_id stamped by BelongsToMasjid

        return response()->json([
            'status' => 'success',
            'data' => $donation->load(['fund', 'contact']),
        ], Response::HTTP_CREATED);
    }

    /**
     * Show a single donation with its fund and issued receipt eager-loaded.
     * Read-only: donations are created and advanced ONLY by Stripe webhooks,
     * never through the admin API. findOrFail is tenant-scoped, so another
     * masjid's id resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $donation_id)
    {
        $donation = Donation::with(['fund', 'receipt', 'contact'])->findOrFail($donation_id);

        return response()->json([
            'status' => 'success',
            'data' => $donation,
        ], Response::HTTP_OK);
    }

    /**
     * Download the printable PDF of the receipt this donation already issued —
     * the same document the donor received by email, for the admin who has to
     * re-hand it to a donor who lost it.
     *
     * Resolution is a chain, and every link is tenant-checked: the route masjid
     * is bound by ResolveMasjidTenant (naming someone else's masjid is a 403),
     * the tenant-scoped findOrFail 404s on a donation belonging to another
     * masjid, and the receipt is read through the donation's own relation — so a
     * foreign id at any position resolves to nothing rather than leaking a
     * document. Nothing is issued or recomputed here: the PDF is rendered from
     * the stored receipt row.
     */
    public function receiptPdf($masjid_id, $donation_id)
    {
        $donation = Donation::findOrFail($donation_id);
        $receipt = $donation->receipt()->first();

        if (! $receipt) {
            return response()->json([
                'status' => 'error',
                'message' => 'No receipt has been issued for this donation.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response($this->receiptPdfs->pdfFor($receipt), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->receiptPdfs->filename($receipt) . '"',
            // A tax document naming a donor: never cached by a proxy, never
            // written to disk by the browser.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
