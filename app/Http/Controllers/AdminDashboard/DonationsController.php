<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Exceptions\AmbiguousDonorNameException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Donations\StoreOfflineDonationRequest;
use App\Http\Requests\Admin\Donations\UpdateOfflineDonationRequest;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Crm\DonorContactService;
use App\Services\Receipts\DonationReceiptPdfService;
use App\Services\Receipts\ReceiptService;
use App\Support\DonationMetrics;
use App\Support\ZakatDesignation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    public function __construct(
        private DonationReceiptPdfService $receiptPdfs,
        private ReceiptService $receipts,
        private DonorContactService $donors,
    ) {
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
     * row is booked succeeded, source=offline, dated to when it was given, and
     * issues NO receipt — recording a gift and issuing a tax document are two
     * different decisions (see issueReceipt below). Tenant-scoped: fund/contact
     * are validated to belong to this masjid before the write.
     */
    public function store(StoreOfflineDonationRequest $request, $masjid_id)
    {
        // Validate fund + contact belong to THIS masjid (bound tenant scopes these).
        $fund = Fund::findOrFail($request->integer('fund_id'));

        try {
            $contactId = $this->resolveDonor($request, (int) $fund->masjid_id);
        } catch (AmbiguousDonorNameException $e) {
            return $this->ambiguousDonor($e);
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
     * Who this gift belongs to, from whichever of the two donor keys was sent.
     *
     * `contact_id` wins over `donor_name`: an id is a decision the admin made by
     * clicking a real person in the typeahead, a name is only a lookup.
     *
     * Returns null when NEITHER key carries a value, which is a real answer — a
     * donation box or a fundraiser table genuinely has no donor. The bug this
     * replaced could not tell that answer apart from "a name was typed and I
     * threw it away"; now the two are different inputs.
     *
     * A present-but-null `contact_id` also returns null, which is how the edit
     * form puts a gift back to general.
     */
    private function resolveDonor(Request $request, int $masjidId): ?int
    {
        if ($request->filled('contact_id')) {
            return Contact::findOrFail($request->integer('contact_id'))->id;
        }

        if ($request->filled('donor_name')) {
            // Throws ValidationException (422) when the name is ambiguous rather
            // than attributing the money to whichever row sorted first.
            return $this->donors->findOrCreateByName($masjidId, (string) $request->input('donor_name'))?->id;
        }

        return null;
    }

    /**
     * 422 for a donor name two real people share.
     *
     * Rendered here rather than thrown as a ValidationException because the
     * app-wide JSON renderer (bootstrap/app.php) only passes through
     * HttpResponseException and HttpExceptionInterface — a ValidationException
     * raised outside a FormRequest would reach the client as a 500, which tells
     * the admin nothing and looks like the platform broke.
     *
     * Carries BOTH shapes: `message` for the generic error toast, and the
     * `errors.donor_name` key a form field binds to.
     */
    private function ambiguousDonor(AmbiguousDonorNameException $e)
    {
        return response()->json([
            'status' => 'error',
            'message' => $e->publicMessage(),
            'errors' => ['donor_name' => [$e->publicMessage()]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Correct a manually-recorded gift (T-007c).
     *
     * ## Why this route exists at all, next to a ledger that says "read-only"
     *
     * The comment above the routes still holds for STRIPE gifts: those rows are
     * created and advanced by a signature-verified webhook, and a second writer
     * would let the ledger disagree with the money. An OFFLINE gift has the
     * opposite problem — it is a human transcribing a cheque, and until now a
     * typo was permanent. There was no update route, no delete route, and no
     * assistant tool, so a gift recorded against the wrong donor, fund or amount
     * stayed wrong forever and quietly mis-stated a year-end statement.
     *
     * ## The two refusals
     *
     * 1. NOT A STRIPE GIFT. `source !== 'offline'` is refused outright. The
     *    webhook owns those rows; this form must never be the thing that makes
     *    the ledger differ from Stripe.
     *
     * 2. NOT A RECEIPTED GIFT, for the four facts the receipt states. Once a
     *    serial has been issued, a tax document naming a donor, a fund, an amount
     *    and a date is in that donor's hands and in the masjid's gap-free
     *    sequence. Editing any of those four silently would leave the platform
     *    disagreeing with a document the CRA/IRS may see, so they are frozen and
     *    the refusal NAMES the serial. Everything a receipt does not assert —
     *    the note, how the money arrived, the cheque number — stays editable,
     *    because those are the fields a treasurer actually reconciles later.
     *    A receipted gift that is genuinely wrong is a void-and-reissue, which is
     *    a deliberate act and not this form's job.
     *
     * Fields absent from the request are LEFT ALONE (see UpdateOfflineDonationRequest),
     * so an edit that only fixes the donor cannot blank the note.
     */
    public function update(UpdateOfflineDonationRequest $request, $masjid_id, $donation_id)
    {
        $donation = Donation::with('receipt')->findOrFail($donation_id);

        if ($donation->source !== 'offline') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only manually-recorded gifts can be edited. This one came from Stripe, '.
                    'where the payment record is the source of truth.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fund = $request->has('fund_id')
            ? Fund::findOrFail($request->integer('fund_id'))
            : Fund::findOrFail($donation->fund_id);

        // Only re-resolve the donor when the request actually carries a donor key;
        // otherwise an edit of the note would clear the donor.
        try {
            $contactId = ($request->has('contact_id') || $request->has('donor_name'))
                ? $this->resolveDonor($request, (int) $fund->masjid_id)
                : ($donation->contact_id !== null ? (int) $donation->contact_id : null);
        } catch (AmbiguousDonorNameException $e) {
            return $this->ambiguousDonor($e);
        }

        $cents = $request->has('amount')
            ? (int) round(((float) $request->validated('amount')) * 100)
            : (int) $donation->charged_amount;

        $donatedAt = $request->has('donated_at')
            ? Carbon::parse($request->validated('donated_at'))->toDateString()
            : ($donation->donated_at ? Carbon::parse($donation->donated_at)->toDateString() : null);

        // ---- the receipt freeze -------------------------------------------
        $receipt = $donation->receipt;

        if ($receipt) {
            $current = [
                'donor' => $donation->contact_id !== null ? (int) $donation->contact_id : null,
                'fund' => (int) $donation->fund_id,
                'amount' => (int) $donation->charged_amount,
                'date' => $donation->donated_at ? Carbon::parse($donation->donated_at)->toDateString() : null,
            ];

            $wanted = [
                'donor' => $contactId,
                'fund' => (int) $fund->id,
                'amount' => $cents,
                'date' => $donatedAt,
            ];

            $frozen = array_keys(array_filter(
                $wanted,
                static fn ($value, $key) => $current[$key] !== $value,
                ARRAY_FILTER_USE_BOTH
            ));

            if ($frozen !== []) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This gift has already been receipted (serial '.$receipt->serial_number.
                        '), so its '.implode(', ', $frozen).' can no longer be changed — the donor is '.
                        'holding a tax document that states them. The note, payment method and cheque '.
                        'number can still be corrected.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // ---- the write -----------------------------------------------------
        $attributes = [
            'contact_id' => $contactId,
            'fund_id' => $fund->id,
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'donated_at' => $donatedAt,
        ];

        if ($request->has('payment_method')) {
            $attributes['payment_method'] = $request->validated('payment_method');
        }

        // The cheque number belongs to a cheque. Switching the method away from
        // `check` drops it rather than leaving a stale number on a cash gift.
        $method = $attributes['payment_method'] ?? $donation->payment_method;

        if ($method !== 'check') {
            $attributes['check_number'] = null;
        } elseif ($request->has('check_number')) {
            $attributes['check_number'] = $request->input('check_number');
        }

        if ($request->has('note')) {
            $attributes['note'] = $request->input('note');
        }

        // Zakat is a statement about what the GIVER restricted, so an explicit
        // one is never overwritten by a fund change. Re-resolve only when the
        // admin says so, or when the stored answer was itself only the old
        // fund's default and that fund is being changed out from under it.
        $inferred = in_array($donation->zakat_source, [null, ZakatDesignation::SOURCE_FUND_DEFAULT], true);

        if ($request->has('zakat')) {
            $attributes += ZakatDesignation::resolve(
                $request->boolean('zakat'), $fund, ZakatDesignation::SOURCE_ADMIN
            );
        } elseif ((int) $fund->id !== (int) $donation->fund_id && $inferred) {
            $attributes += ZakatDesignation::resolve(null, $fund, ZakatDesignation::SOURCE_ADMIN);
        }

        $donation->fill($attributes)->save();

        return response()->json([
            'status' => 'success',
            'data' => $donation->fresh()->load(['fund', 'contact', 'receipt']),
        ], Response::HTTP_OK);
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
     * Issue the official tax receipt for an OFFLINE gift (T-007b).
     *
     * WHY THIS IS AN ADMIN ACTION AND NOT AUTOMATIC ON store().
     * A Stripe gift is receipted automatically because a signature-verified
     * webhook proves the money settled — the machine has evidence. An offline
     * gift has none: a human typed "$5,000, cheque". Auto-issuing on that
     * keystroke would mint a serialled, gap-free tax document — a number the
     * masjid can never reuse and a receipt the donor may already have filed —
     * from a value that is still being typed, mis-keyed, pasted from the wrong
     * row of a spreadsheet, or entered before the cheque has cleared. The two
     * decisions genuinely differ in time as well: gifts get recorded the evening
     * of a fundraiser, and receipted once the deposit clears. So recording is the
     * bookkeeping act and issuing is the treasurer's, taken per gift, once.
     * Everything downstream is unchanged by the wait — offline gifts already
     * count toward annual statements through AnnualStatementService's
     * charged_amount fallback whether or not a receipt row exists.
     *
     * Issuance goes through the SAME ReceiptService as the webhook, so the serial
     * is the next one in the masjid's single gap-free sequence (a cash gift and a
     * card gift interleave in one run) and a double-click is idempotent: the
     * second call returns the SAME receipt at 200 instead of 201, never a second
     * serial. Stripe gifts are refused here — their receipt is the webhook's job,
     * and that path must stay the only one that touches it.
     *
     * `manage donations` like the offline store route: this writes a financial
     * record and consumes a serial. Tenant-checked the same way as receiptPdf —
     * a foreign masjid in the route is a 403, a foreign donation id a 404.
     */
    public function issueReceipt($masjid_id, $donation_id)
    {
        $donation = Donation::findOrFail($donation_id);

        if ($donation->source !== 'offline') {
            return response()->json([
                'status' => 'error',
                'message' => 'Receipts for Stripe donations are issued automatically when the payment is confirmed.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $donation->receipt()->first();

        $receipt = $this->receipts->issueFor($donation);

        // issueFor declines rather than throws. Say which rule declined it, so an
        // admin staring at a missing receipt is not left guessing.
        if (! $receipt) {
            return response()->json([
                'status' => 'error',
                'message' => $donation->isSucceeded()
                    ? 'This gift is designated to a fund that does not issue tax receipts.'
                    : 'Only a succeeded donation can be receipted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'data' => $receipt,
        ], $existing ? Response::HTTP_OK : Response::HTTP_CREATED);
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
