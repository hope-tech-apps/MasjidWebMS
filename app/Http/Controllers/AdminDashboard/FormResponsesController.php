<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Forms\IndexFormResponsesRequest;
use App\Http\Requests\Admin\Forms\MarkFormResponsePaidRequest;
use App\Http\Requests\Admin\Forms\TakeFormResponseCashRequest;
use App\Http\Requests\Admin\Forms\UpdateFormResponseRequest;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\FormChargeAccount;
use App\Services\Stripe\FormCheckoutRefused;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\Errors;
use App\Support\FormCashTotals;
use App\Support\FormNotifier;
use App\Support\FormRoster;
use App\Support\FormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ExceptionInterface as StripeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Form Responses" screen's API: who filled out a given form.
 *
 * Search, filtering and sorting are all applied in SQL rather than in the client,
 * because every list in this app is server-paginated — sorting a page in the browser
 * would silently sort 25 of 300 rows and look like it worked.
 *
 * These rows are PII. Nothing here is reachable without auth + tenant scoping, and the
 * form is always resolved through `$masjid->forms()` so one masjid cannot read another's
 * registrations by guessing a form id.
 *
 * The same chain is what makes downloadAttachment() safe: a résumé is reached only as
 * this masjid's form's response's attachment, so every link has to belong to the caller
 * before any bytes leave the private disk.
 *
 * ## The door (DECISIONS.md 2026-09-11)
 *
 * A form that takes payment gives its responses a money leg (FormResponse), and the
 * admins at the bracelet table work it from here:
 *
 *   collect / uncollect   bracelets handed out, stamped by the first press. Only for a
 *                         SETTLED registration (paid, or nothing was ever owed), and
 *                         never a triage-cancelled one: a refunded card payer is
 *                         cancelled, and must not walk away with bracelets.
 *   takeCash              cash for an unpaid registration, under the row lock: the open
 *                         card page is closed first, and when Stripe says the payer has
 *                         just paid, the cash is refused (festival brief, blocker 4).
 *   markPaidExternal      a payment made somewhere else: the Wix fallback (blocker 5), or
 *                         the Zelle / Cash App / Venmo / check a family paying the office
 *                         sent, which must say which (`via`; BISS, 2026-09-13).
 *   cashTotals            what each holder owes, over this screen's own query.
 *   update (cancel)       cancelling an unpaid card registration closes its open card
 *                         page under the same row lock, so it is never left payable.
 *
 * A registration with a money leg is never deleted, only cancelled, and re-triaging one
 * is stamped with who and when (blocker 2). A payment recorded here is asserted by a
 * named admin — the MealOrdersController::markPaid carve-out from "webhook only". A card
 * payment is only ever marked paid by the signed webhook.
 *
 * The money leg is shown only on a form set up to take payment
 * (Form::hasPaymentSettings()): the payment columns in both CSVs, and the payment
 * reading on the list and the roster. A fee form that never was, Burlington's camp,
 * exports exactly the columns it always did.
 *
 * Pinned by tests/Feature/FormResponsesMoneyAdminTest.php.
 */
class FormResponsesController extends Controller
{
    /**
     * Read with every response the list, the export and the roster show: whose cash it
     * is and who acted on it — one query per relation per page or export chunk, never one
     * per row. The user relations are withTrashed, so a removed admin's name still shows.
     */
    private const EAGER = ['staffCode', 'collectedBy:id,name', 'markedPaidBy:id,name', 'statusChangedBy:id,name'];

    /*
     * update()'s `card_page`, on every answer to a request saying "cancelled": what that did
     * about the registration's card payment page, in one word. The screen words its answer
     * and offers "close its card page again" from this alone, never from what it remembers
     * of earlier answers.
     *
     *   closed          the page is closed now, or had already expired: nothing more can be paid
     *   paid_on_stripe  the card has paid, recorded or on its way from Stripe: there is no page
     *                   to close, and the admin is told to refund it if it should not stand
     *   unconfirmed     Stripe refused the close or could not be reached: the page may still
     *                   take a payment, and saying "cancelled" again retries the close
     *   none            there is no card page: not a card registration, or no page was opened
     *   unchecked       a page is on the row, but the organisation has no Stripe account on
     *                   record to ask, so nobody knows whether it is still open
     *   unreachable     the page was pinned to another organisation's account, which no longer
     *                   lets the platform check or close it (DECISIONS.md 2026-09-15): retrying
     *                   cannot help, and the page takes no payment once it expires
     */
    private const CARD_PAGE_CLOSED = 'closed';

    private const CARD_PAGE_PAID_ON_STRIPE = 'paid_on_stripe';

    private const CARD_PAGE_UNCONFIRMED = 'unconfirmed';

    private const CARD_PAGE_NONE = 'none';

    private const CARD_PAGE_UNCHECKED = 'unchecked';

    private const PAGE_NOT_CLOSED = 'Cancelled, but its card payment page could not be closed, so it may still take a payment. Cancel it again to retry.';

    /**
     * GET /api/admin/masjids/{masjid_id}/forms/{form_id}/responses
     *
     * Supports ?q= (name/email/phone), ?status=, ?from=&to=, ?sort=&direction=, ?per_page=.
     */
    public function index(IndexFormResponsesRequest $request, $masjid_id, $form_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);

            $responses = $this->query($request, $masjid, $form)
                ->paginate($request->perPage())
                ->withQueryString()
                ->through(fn (FormResponse $response) => $this->serialize($response, $form));

            return response()->json([
                'status' => 'success',
                'data' => $responses,
                'meta' => [
                    'form' => [
                        'id' => $form->id,
                        'name' => $form->name,
                        'response_count' => $form->response_count,
                        'capacity' => $form->capacity,
                    ],
                    // The builder's column definitions, so the table can render a column
                    // per question without the SPA having to re-parse the schema.
                    'columns' => $this->columns($form),
                    'statuses' => FormResponse::STATUSES,
                    'sortable' => IndexFormResponsesRequest::SORTABLE,
                    // The door's filters, and whether this form has a money leg to show.
                    'payment_filters' => IndexFormResponsesRequest::PAYMENT_FILTERS,
                    'collected_filters' => IndexFormResponsesRequest::COLLECTED_FILTERS,
                    'payment' => $this->paymentMeta($form),
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/roster
     *
     * The attendee roster: ONE ROW PER PERSON rather than per submission, so a
     * coordinator can read a check-in list without opening every registration. Honours
     * the same filters as the list.
     *
     * Declared before /{response_id} so "roster" is not captured as an id.
     */
    public function roster(IndexFormResponsesRequest $request, $masjid_id, $form_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);

            $roster = FormRoster::for($form);

            // Flattening happens in PHP (the entries live in a JSON column, and JSON-path
            // sorting is not portable MySQL/SQLite), so the filtered responses are read in
            // full. The bound is one form's registrations — hundreds for a camp.
            $rows = $roster->rows($this->query($request, $masjid, $form)->get());

            $rows = $this->sortRoster($rows, $request);

            $perPage = $request->perPage();
            $page = max(1, (int) $request->input('page', 1));

            $paginator = new LengthAwarePaginator(
                $rows->forPage($page, $perPage)->values(),
                $rows->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json([
                'status' => 'success',
                'data' => $paginator,
                'meta' => [
                    'form' => [
                        'id' => $form->id,
                        'name' => $form->name,
                        'capacity' => $form->capacity,
                    ],
                    'columns' => $roster->columns(),
                    'summary' => $roster->summary($rows),
                    'statuses' => FormResponse::STATUSES,
                    'sortable' => $this->rosterSortable($roster),
                    // The same door filters and payment block the list sends, so the
                    // roster shows its payment columns, filters and staff codes for the
                    // form it was asked about, whichever list was read before it.
                    'payment_filters' => IndexFormResponsesRequest::PAYMENT_FILTERS,
                    'collected_filters' => IndexFormResponsesRequest::COLLECTED_FILTERS,
                    'payment' => $this->paymentMeta($form),
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET .../responses/roster/export
     *
     * The printable check-in sheet: one line per attendee. This is the artefact a camp
     * actually carries to the door.
     */
    public function rosterExport(IndexFormResponsesRequest $request, $masjid_id, $form_id): StreamedResponse
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $form = $masjid->forms()->findOrFail($form_id);

        $roster = FormRoster::for($form);
        $columns = $roster->columns();
        $rows = $this->sortRoster($roster->rows($this->query($request, $masjid, $form)->get()), $request);

        $filename = $form->slug . '-roster-' . now()->format('Y-m-d') . '.csv';

        // Payment and Collected only on a form set up to take payment: every other roster
        // is the sheet it always was (Form::hasPaymentSettings()).
        $money = $form->hasPaymentSettings();

        return response()->stream(function () use ($rows, $columns, $money) {
            $out = fopen('php://output', 'w');

            $header = [];
            foreach ($columns as $column) {
                $header[] = $column['label'];
            }
            $header = array_merge($header, ['Registered by', 'Registrant email', 'Registrant phone', 'Status', 'Submitted']);

            if ($money) {
                array_push($header, 'Payment', 'Collected');
            }

            fputcsv($out, $header);

            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = $this->csvCell($this->stringify($row['values'][$column['key']] ?? ''));
                }
                $line[] = $this->csvCell((string) ($row['registered_by'] ?? ''));
                $line[] = $this->csvCell((string) ($row['registrant_email'] ?? ''));
                $line[] = $this->csvCell((string) ($row['registrant_phone'] ?? ''));
                $line[] = $row['status'] ?? '';
                $line[] = $row['submitted_at'] ? date('Y-m-d H:i', strtotime($row['submitted_at'])) : '';

                if ($money) {
                    // The cash holder's name rides in the payment cell: guarded like any answer.
                    $line[] = $this->csvCell((string) ($row['payment'] ?? ''));
                    $line[] = ! empty($row['collected_at']) ? date('Y-m-d H:i', strtotime($row['collected_at'])) : '';
                }

                fputcsv($out, $line);
            }

            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Roster sorting is applied to the flattened collection, because the sort keys live
     * inside the JSON entries and cannot be expressed in the SQL that produced them.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $rows
     */
    private function sortRoster($rows, IndexFormResponsesRequest $request)
    {
        $sort = $request->input('sort');
        $descending = $request->sortDirection() === 'desc';

        // Default: submission order, then the order people were listed within it, so a
        // family stays together and in the order the parent typed them.
        if (! $sort) {
            return $rows->sortBy([
                fn ($a, $b) => strcmp((string) $b['submitted_at'], (string) $a['submitted_at']),
                fn ($a, $b) => $a['entry_index'] <=> $b['entry_index'],
            ])->values();
        }

        $reader = match (true) {
            $sort === 'submitted_at' => fn ($row) => $row['submitted_at'] ?? '',
            $sort === 'respondent_name' => fn ($row) => mb_strtolower((string) ($row['registered_by'] ?? '')),
            $sort === 'status' => fn ($row) => $row['status'] ?? '',
            // Anything else is a roster COLUMN key.
            default => fn ($row) => $this->sortableValue($row['values'][$sort] ?? null),
        };

        $sorted = $rows->sortBy($reader, SORT_NATURAL | SORT_FLAG_CASE, $descending);

        return $sorted->values();
    }

    /** Numbers must sort numerically (age 9 before 10), text case-insensitively. */
    private function sortableValue(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return str_pad((string) (int) $value, 12, '0', STR_PAD_LEFT);
        }

        return mb_strtolower((string) $value);
    }

    /** @return array<int,string> */
    private function rosterSortable(FormRoster $roster): array
    {
        return array_merge(
            ['submitted_at', 'respondent_name', 'status'],
            collect($roster->columns())->pluck('key')->all()
        );
    }

    /** GET /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/{response_id} */
    public function show($masjid_id, $form_id, $response_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);
            $response = $form->responses()->with(self::EAGER)->findOrFail($response_id);

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($response, $form, withData: true),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET .../responses/{response_id}/attachments/{attachment_id}
     *
     * Streams one uploaded file — a résumé on a Schools careers form — off the PRIVATE
     * disk. This endpoint is the reason those files are not in the public root: it runs
     * behind auth:sanctum + admin + tenant, and resolves the attachment through
     * masjid → form → response → attachment, so the only way to read one is to be an
     * admin of the masjid that collected it. Another masjid's id anywhere in that chain
     * is a 404.
     *
     * findOrFail is deliberately NOT inside a try/catch: the app's JSON renderer turns
     * ModelNotFoundException into a clean 404, whereas catching it here would report a
     * missing attachment as a 500. Same arrangement as export() above.
     */
    public function downloadAttachment($masjid_id, $form_id, $response_id, $attachment_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $form = $masjid->forms()->findOrFail($form_id);
        $response = $form->responses()->where('masjid_id', $masjid->id)->findOrFail($response_id);
        $attachment = $response->attachments()->findOrFail($attachment_id);

        if (! $attachment->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This attachment is no longer stored on the server.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $attachment->storage()->download(
            $attachment->path,
            $attachment->original_name,
            [
                // The type was sniffed from the bytes at upload and constrained to the
                // configured allowlist, so it is ours to state rather than the
                // respondent's. Attachment disposition (set by download()) plus the
                // global nosniff header keeps a document from ever being rendered.
                'Content-Type' => $attachment->mime_type,
                // Private and uncached: an intermediate proxy must never hold one
                // masjid's résumé and hand it to the next admin who asks for the URL.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * PUT /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/{response_id}
     *
     * Triage. On the locked row, so whether it carries a money leg is decided on the row
     * as it is now: re-triaging one — cancelling a cash row takes it out of its holder's
     * total — is stamped with who and when (FormResponse::stampStatusChange()).
     *
     * Cancelling an unpaid card registration also closes its open payment page, under the
     * same lock, so a cancelled registration is never left payable: the
     * MealOrdersController::updateStatus() rule at 42f07d6 (closePageOfCancelled()). The
     * cancellation stands whatever Stripe says, and the answer's `message` and `warning`
     * tell the admin what happened. Cancelling one the card has already paid for is a
     * warning too: cancelling refunds nothing, so whichever of the payment and the cancel
     * lands first, the admin is told to refund it.
     *
     * Every answer to a request saying "cancelled" also carries `card_page`
     * (self::CARD_PAGE_*): what that did about the card page. Any other triage answers
     * without it, as it always did.
     */
    public function update(UpdateFormResponseRequest $request, $masjid_id, $form_id, $response_id)
    {
        [, $form, $response] = $this->resolveResponse($masjid_id, $form_id, $response_id);

        try {
            [$message, $warning, $cardPage] = DB::transaction(function () use ($request, $form, $response): array {
                $row = $this->lockRow($response, $form);
                $row->fill($request->safe()->all());

                // Read before save() clears it: whether it is THIS request that cancels.
                $cancelling = $row->isDirty('status') && $row->status === FormResponse::STATUS_CANCELLED;

                $row->stampStatusChange($request->user());
                $row->save();

                // Whenever the admin says "cancelled", not only the first time: saying it
                // again retries a close that failed.
                return $request->safe()->has('status') && $row->isCancelled()
                    ? $this->closePageOfCancelled($row, $cancelling)
                    : [null, false, null];
            });

            $body = [
                'status' => 'success',
                'data' => $this->serialize($this->reload($form, $response), $form, withData: true),
            ];

            // Only when there is something to say, so every other triage answers as it
            // always did.
            if ($message !== null) {
                $body['message'] = $message;
                $body['warning'] = $warning;
            }

            if ($cardPage !== null) {
                $body['card_page'] = $cardPage;
            }

            return response()->json($body, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->failed($e);
        }
    }

    /**
     * A cancelled card registration must not stay payable: close its open Stripe page
     * (FormResponseCheckoutService::closeOpenSession(), which asks Stripe again after a
     * refused close). MealOrdersController::closePageOfCancelled() at 42f07d6, for a form
     * response. Run inside update()'s transaction, on the locked row, and every Stripe
     * failure is caught here, so the cancellation commits whatever Stripe says. `true`
     * marks an answer the admin must act on.
     *
     * The session id stays on the row, as it does when cash is taken: a payment that
     * still lands is recorded against this registration, and the admin was told to
     * refund it.
     *
     * A row the card has already paid for has no page to close, and cancelling refunds
     * nothing, so the admin is told to refund it whenever they say "cancelled", not only
     * on the cancel that moves it ($justCancelled). A payment can land on a registration
     * cancelled earlier (the close failed and the payer finished the page), and the next
     * "cancelled" is where the admin first sees it.
     *
     * @return array{0: ?string, 1: bool, 2: string}  the message, whether the admin must act, `card_page`
     */
    private function closePageOfCancelled(FormResponse $row, bool $justCancelled): array
    {
        if ($row->payment_method !== FormResponse::METHOD_ONLINE) {
            return [null, false, self::CARD_PAGE_NONE];
        }

        if ($row->isPaid()) {
            return [...$this->cancelledAfterCardPayment($row, $justCancelled), self::CARD_PAGE_PAID_ON_STRIPE];
        }

        if (! $row->stripe_checkout_session_id) {
            return [null, false, self::CARD_PAGE_NONE];
        }

        try {
            // Resolved here, not injected: triaging a row with no card page never builds
            // a Stripe client.
            $closed = app(FormResponseCheckoutService::class)->closeOpenSession($row);
        } catch (FormCheckoutRefused|StripeException $e) {
            // At warning, the level production runs at; by id and Stripe's code, never
            // Stripe's message.
            Log::warning('A cancelled form registration\'s card payment page could not be closed.', [
                'masjid_id' => $row->masjid_id,
                'form_id' => $row->form_id,
                'form_response_id' => $row->id,
                'exception' => $e::class,
                'stripe_code' => $e instanceof ApiErrorException ? $e->getStripeCode() : null,
            ]);

            return [self::PAGE_NOT_CLOSED, true, self::CARD_PAGE_UNCONFIRMED];
        }

        // A page charged through another organisation's account (DECISIONS.md 2026-09-15):
        // only that organisation can refund it, so the admin is told who, and how it finds it.
        $refund = FormChargeAccount::refundInstruction($row) ?? 'Refund it in Stripe if it should not stand.';

        return match ($closed) {
            'complete' => ["This registration had just been paid by card, so it will show as paid once Stripe confirms it. {$refund}", true, self::CARD_PAGE_PAID_ON_STRIPE],
            'expired' => ['Cancelled, and its card payment page is closed.', false, self::CARD_PAGE_CLOSED],
            // The page is pinned to an account Stripe no longer lets the platform act on.
            // Retrying cannot help; the page takes no payment once charge_expires_at passes.
            FormResponseCheckoutService::UNREACHABLE => [
                'Cancelled. Its card payment page is on ' . ($this->holderName($row) ?? 'another organisation') . '\'s Stripe account, which no longer lets us check or close it, '
                . 'so it can take a payment until it expires. If one lands, ' . $refund,
                true,
                FormResponseCheckoutService::UNREACHABLE,
            ],
            // The row carries a page and is unpaid, so closeOpenSession() found no Stripe
            // account on record to ask about it.
            null => [null, false, self::CARD_PAGE_UNCHECKED],
            // A status Stripe does not give a page: it cannot be called closed.
            default => [self::PAGE_NOT_CLOSED, true, self::CARD_PAGE_UNCONFIRMED],
        };
    }

    /**
     * A cancelled registration its card payment was recorded for: cancelling refunds
     * nothing. Said on every "cancelled" (closePageOfCancelled()), and logged once, by the
     * cancel that moves it ($justCancelled), when the webhook got there first. A payment
     * that lands on a registration already cancelled is logged by the webhook
     * (FormResponsePaymentService::settle()), and the cancel that loses the race the other
     * way is told by closeOpenSession()'s 'complete', so a log line exists whichever lands
     * first. Logged at warning, the level production runs at, by ids and the payment intent
     * (how the charge is found in Stripe), never the payer.
     *
     * @return array{0: string, 1: bool}
     */
    private function cancelledAfterCardPayment(FormResponse $row, bool $justCancelled): array
    {
        if ($justCancelled) {
            Log::warning('A form registration already paid by card was cancelled; cancelling does not refund it. The organisation '
                . 'should refund it in its Stripe dashboard if it should not stand.', [
                    'masjid_id' => $row->masjid_id,
                    'form_id' => $row->form_id,
                    'form_response_id' => $row->id,
                    'payment_intent' => $row->stripe_payment_intent_id,
                ]);
        }

        $amount = $row->total_minor !== null ? ' (' . FormNotifier::money((int) $row->total_minor, $row->currency) . ')' : '';

        // Charged through another organisation's account (DECISIONS.md 2026-09-15): only it
        // can refund, and the payment intent is how it finds the charge.
        $refund = FormChargeAccount::refundInstruction($row) ?? 'Refund it in Stripe if it should not stand.';

        return ["This registration was already paid by card{$amount}. Cancelling does not refund it. {$refund}", true];
    }

    /**
     * The organisation whose account a row's card page was pinned to, withTrashed, read once
     * per request (a list page shows many rows of one holder). Null for a row never pinned.
     */
    private function pinHolder(FormResponse $row): ?Masjid
    {
        if ($row->charge_masjid_id === null) {
            return null;
        }

        $id = (int) $row->charge_masjid_id;
        $cache = request()->attributes->get('forms.charge_holders', []);

        if (! array_key_exists($id, $cache)) {
            $cache[$id] = Masjid::withTrashed()->find($id);
            request()->attributes->set('forms.charge_holders', $cache);
        }

        return $cache[$id];
    }

    /** The pinned holder's name, or null. Never its account id. */
    private function holderName(FormResponse $row): ?string
    {
        return $this->pinHolder($row)?->name;
    }

    /** `charged_through`: {id, name} of the other organisation a row was charged through, or null. */
    private function chargedThroughOf(FormResponse $row): ?array
    {
        $holder = $row->isChargedThroughAnotherOrg() ? $this->pinHolder($row) : null;

        return $holder !== null ? ['id' => (int) $holder->id, 'name' => $holder->name] : null;
    }

    /**
     * `page_unreachable`: true only when it is KNOWN that nobody can ask Stripe about an
     * unpaid card row's pinned page any more, because no live organisation holds the account
     * it was opened on (the holder offboarded, or moved to another account). A disconnect
     * Stripe has not told us about reads false here, and take cash answers 409 when it meets it.
     */
    private function knownUnreachable(FormResponse $row): bool
    {
        if (! $row->hasChargePin()
            || $row->payment_method !== FormResponse::METHOD_ONLINE
            || $row->isPaid()
            || $row->stripe_checkout_session_id === null) {
            return false;
        }

        $pin = (string) $row->charge_account_id;
        $cache = request()->attributes->get('forms.live_accounts', []);

        if (! array_key_exists($pin, $cache)) {
            $cache[$pin] = Masjid::query()->where('stripe_account_id', $pin)->exists();
            request()->attributes->set('forms.live_accounts', $cache);
        }

        return ! $cache[$pin];
    }

    /**
     * DELETE /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/{response_id}
     *
     * A registration with a money leg is never deleted (festival brief, blocker 2): a
     * holder's cash, a Stripe charge and the reconciliation all point at it. It is
     * cancelled instead, which keeps it — in its own column — in the cash totals. Checked
     * on the locked row, so a payment recorded that same second is not deleted from under
     * it.
     *
     * Nor is a row imported from the school website (`external_ref`, alrazi:sync-website):
     * the next five-minute run would write it straight back, files and all. It is
     * cancelled instead, like a paid one. The one path that deletes an imported row
     * is `alrazi:purge-website-removed`, and only once the website itself no longer
     * returns it (the sync's `websiteRemoved` marker).
     */
    public function destroy($masjid_id, $form_id, $response_id)
    {
        [, , $response] = $this->resolveResponse($masjid_id, $form_id, $response_id);

        try {
            $refusal = DB::transaction(function () use ($response): ?string {
                $row = FormResponse::query()->whereKey($response->getKey())->lockForUpdate()->firstOrFail();

                if ($row->hasMoneyLeg()) {
                    return 'A registration with a payment is never deleted. Cancel it instead, and add a note.';
                }

                if ($row->isExternal()) {
                    return 'Imported from the school website. Cancel it instead.';
                }

                $row->delete();

                return null;
            });
        } catch (\Exception $e) {
            return $this->failed($e);
        }

        if ($refusal !== null) {
            return $this->refused($refusal);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Response deleted successfully',
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------------ the door

    /**
     * GET .../responses/cash-totals
     *
     * The cash each staff member holds (App\Support\FormCashTotals), over this screen's
     * own query, so every filter the list honours narrows the totals the same way.
     * Declared before /{response_id} so "cash-totals" is not captured as an id.
     */
    public function cashTotals(IndexFormResponsesRequest $request, $masjid_id, $form_id): JsonResponse
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $form = $masjid->forms()->findOrFail($form_id);

        $codes = $form->staffCodes()->orderBy('holder_name')->orderBy('id')->get();

        return response()->json([
            'status' => 'success',
            'data' => FormCashTotals::for($this->query($request, $masjid, $form), $codes),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../responses/{response_id}/collect — the bracelets have been handed out.
     *
     * Stamped by the first press only (FormResponse::markCollected()): a second press, or
     * a colleague's at the next table, is answered with the row as it stands and never
     * rewrites who handed them over. Refused, on the locked row: a registration that is
     * not settled ("Not paid yet" — collected is never a way round paying), and a
     * triage-cancelled one.
     */
    public function collect(Request $request, $masjid_id, $form_id, $response_id): JsonResponse
    {
        [, $form, $response] = $this->resolveResponse($masjid_id, $form_id, $response_id);

        try {
            $outcome = DB::transaction(function () use ($request, $form, $response): string {
                $row = $this->lockRow($response, $form);

                if ($row->isCollected()) {
                    return 'already';
                }

                if ($row->status === FormResponse::STATUS_CANCELLED) {
                    return 'cancelled';
                }

                if (! $row->isSettled()) {
                    return 'unpaid';
                }

                $row->markCollected($request->user());

                return 'collected';
            });
        } catch (\Exception $e) {
            return $this->failed($e);
        }

        return match ($outcome) {
            'cancelled' => $this->refused('This registration is cancelled. Re-open it before handing anything out.'),
            'unpaid' => $this->refused('Not paid yet.'),
            'already' => $this->done($form, $response, 'Already marked collected.'),
            default => $this->done($form, $response, 'Marked collected.'),
        };
    }

    /** DELETE .../responses/{response_id}/collect — "Undo" at the table. */
    public function uncollect($masjid_id, $form_id, $response_id): JsonResponse
    {
        [, $form, $response] = $this->resolveResponse($masjid_id, $form_id, $response_id);

        try {
            $undone = $response->uncollect();
        } catch (\Exception $e) {
            return $this->failed($e);
        }

        return $this->done($form, $response, $undone ? 'Collection undone.' : 'This registration was not marked collected.');
    }

    /**
     * POST .../responses/{response_id}/take-cash — cash taken at the table for an unpaid
     * registration (festival brief, blocker 4).
     *
     * The card leg is closed before the cash is recorded, all under the row lock, so no
     * card page can be opened in between: the open Checkout Session is expired
     * (FormResponseCheckoutService::closeOpenSession(), which asks Stripe again after a
     * refused close), and when Stripe reports it complete the payer has just paid by card,
     * so the cash is refused and the webhook records the card. Only then is the row
     * settled as cash, at the list price, stamped with the operator
     * (FormResponse::settleCashBy()). If Stripe cannot say the page is closed, nothing is
     * recorded: cash is never taken against a page that might still be paid.
     */
    public function takeCash(TakeFormResponseCashRequest $request, FormResponseCheckoutService $checkout, $masjid_id, $form_id, $response_id): JsonResponse
    {
        return $this->settleByHand($request, $checkout, FormResponse::METHOD_CASH, $masjid_id, $form_id, $response_id, null, $request->confirmsHolderChecked());
    }

    /**
     * POST .../responses/{response_id}/mark-paid-external — "Mark paid (external)": the
     * person paid somewhere else, the Wix page if Stripe Connect is not live in time
     * (festival brief, blocker 5). The same lock and the same card-page rule as
     * takeCash(); recorded as payment_method 'external', stamped with who marked it. That
     * is what puts a Wix payer in the paid set the door filters on, and the receipt it
     * sends is where the group link travels in the fallback.
     *
     * `via` (MarkFormResponsePaidRequest) says how the money came and lands in
     * `paid_via`. A family that chose to pay the office MUST have one — the office keeps
     * its books by how the money came — so its absence is refused on the locked row,
     * before anything is recorded. For every other row it stays optional.
     */
    public function markPaidExternal(MarkFormResponsePaidRequest $request, FormResponseCheckoutService $checkout, $masjid_id, $form_id, $response_id): JsonResponse
    {
        return $this->settleByHand($request, $checkout, FormResponse::METHOD_EXTERNAL, $masjid_id, $form_id, $response_id, $request->validated('via'), $request->confirmsHolderChecked());
    }

    /**
     * takeCash() and markPaidExternal(): one path, two methods.
     *
     * A card page pinned to an account Stripe no longer lets the platform act on
     * (closeOpenSession() answers UNREACHABLE; DECISIONS.md 2026-09-15) cannot be checked or
     * closed. Nothing is recorded, a 409 `page_unreachable` says whose account it is and when
     * the page stops taking payments, UNLESS that time has passed AND the admin confirms
     * ($holderChecked) they checked the holder's Stripe dashboard. Only then is it settled.
     */
    private function settleByHand(Request $request, FormResponseCheckoutService $checkout, string $method, $masjid_id, $form_id, $response_id, ?string $via = null, bool $holderChecked = false): JsonResponse
    {
        [, $form, $response] = $this->resolveResponse($masjid_id, $form_id, $response_id);
        $operator = $request->user();
        $unreachable = null;

        try {
            [$outcome, $announced] = DB::transaction(function () use ($checkout, $method, $form, $response, $operator, $via, $holderChecked, &$unreachable): array {
                $row = $this->lockRow($response, $form);

                // Everyone but a card payer still waiting on Stripe was emailed at submit
                // (FormNotifier emails no unpaid money leg but a family paying the office),
                // and the coordinators need not hear about the same registration twice.
                $announced = $row->payment_method !== FormResponse::METHOD_ONLINE;

                if ($row->isPaid()) {
                    return ['paid:' . $row->payment_method, $announced];
                }

                if ($row->status === FormResponse::STATUS_CANCELLED) {
                    return ['cancelled', $announced];
                }

                if ((int) $row->owedMinor() <= 0) {
                    return ['nothing', $announced];
                }

                // A family paying the office: the books must say how the money came. Cash
                // says so itself (settleCashBy()); anything else has to be named.
                if ($method === FormResponse::METHOD_EXTERNAL && $row->payment_method === FormResponse::METHOD_OFFICE && $via === null) {
                    return ['via-required', $announced];
                }

                if ($row->payment_method === FormResponse::METHOD_ONLINE) {
                    $closed = $checkout->closeOpenSession($row);

                    if ($closed === 'complete') {
                        return ['paid-on-stripe', $announced];
                    }

                    // Stripe no longer lets the platform check the page on the account it was
                    // pinned to. Settled only once the page can take no payment (its pinned
                    // expiry has passed) and the admin says they checked the holder's dashboard.
                    if ($closed === FormResponseCheckoutService::UNREACHABLE) {
                        $expired = $row->charge_expires_at !== null && $row->charge_expires_at->isPast();

                        if (! $expired || ! $holderChecked) {
                            $unreachable = ['row' => $row, 'expired' => $expired];

                            return ['unreachable', $announced];
                        }

                        Log::warning('A form registration was settled by hand although Stripe no longer lets the platform check its card page; the page had expired and the admin confirmed checking the account holder\'s dashboard.', [
                            'masjid_id' => $row->masjid_id,
                            'form_id' => $row->form_id,
                            'form_response_id' => $row->id,
                            'charge_masjid_id' => $row->charge_masjid_id,
                            'checkout_session_id' => $row->stripe_checkout_session_id,
                            'method' => $method,
                            'user_id' => $operator?->id,
                        ]);
                    }
                }

                $settled = $method === FormResponse::METHOD_CASH
                    ? $row->settleCashBy($operator)
                    : $row->markExternalPaid($operator, $via);

                return [$settled ? 'settled' : 'paid:' . $row->payment_method, $announced];
            });
        } catch (FormCheckoutRefused $e) {
            return $this->refused($e->getMessage());
        } catch (StripeException $e) {
            // Any failure of the Stripe SDK, not only an API error: a connection that never
            // answered, or a reply the SDK could not read, leaves the page's state as unknown
            // as a refused close does. At warning, the level production runs at; by id and
            // Stripe's code, never Stripe's message.
            Log::warning('Stripe did not confirm a form payment page was closed, so a payment by hand was not recorded.', [
                'masjid_id' => $response->masjid_id,
                'form_id' => $response->form_id,
                'form_response_id' => $response->id,
                'method' => $method,
                'exception' => $e::class,
                'stripe_code' => $e instanceof ApiErrorException ? $e->getStripeCode() : null,
                'http_status' => $e instanceof ApiErrorException ? $e->getHttpStatus() : null,
            ]);

            return response()->json([
                'status' => 'failed',
                'message' => 'Stripe did not confirm that this registration\'s card payment page is closed, so nothing was recorded. Try again in a moment.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            return $this->failed($e);
        }

        if ($outcome === 'unreachable') {
            return $this->pageUnreachable($unreachable['row'], $unreachable['expired']);
        }

        if ($outcome !== 'settled') {
            return $this->refused(match ($outcome) {
                'cancelled' => 'This registration is cancelled. Re-open it before recording a payment.',
                'nothing' => 'This registration has nothing to pay.',
                'via-required' => MarkFormResponsePaidRequest::refusal(),
                'paid-on-stripe' => 'This registration has just been paid by card, and Stripe is confirming it. Do not take a second payment.',
                'paid:' . FormResponse::METHOD_ONLINE => 'This registration has already been paid by card. Do not take a second payment.',
                'paid:' . FormResponse::METHOD_CASH => 'Cash has already been recorded for this registration.',
                default => 'This registration is already marked paid.',
            });
        }

        // After the commit: a mail failure can neither undo the payment nor reach the admin
        // (FormNotifier swallows its own errors). The receipt says how it was paid, and
        // carries the group link now that the registration is settled.
        FormNotifier::submitted($form, FormResponse::query()->findOrFail($response->getKey()), toCoordinators: ! $announced);

        return $this->done($form, $response, $method === FormResponse::METHOD_CASH ? 'Cash recorded.' : 'Marked paid.');
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/forms/{form_id}/responses/export
     *
     * Streams a CSV of the CURRENT filter selection — what the admin sees is what they
     * get. Declared before /{response_id} so "export" is not captured as an id.
     */
    public function export(IndexFormResponsesRequest $request, $masjid_id, $form_id): StreamedResponse
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $form = $masjid->forms()->findOrFail($form_id);

        $columns = $this->columns($form);
        $filename = $form->slug . '-responses-' . now()->format('Y-m-d') . '.csv';

        $query = $this->query($request, $masjid, $form);

        // The money leg's columns only on a form set up to take payment. Every other form,
        // Burlington's camp among them (families pay elsewhere and are triaged
        // "confirmed"), exports exactly the columns it always did (Form::hasPaymentSettings()).
        $money = $form->hasPaymentSettings();

        return response()->stream(function () use ($query, $columns, $form, $money) {
            $out = fopen('php://output', 'w');

            // The first four columns are as they always were. The money leg follows them
            // (DECISIONS.md 2026-09-11), formatted from integer cents at output only.
            $header = ['Submitted', 'Status', 'Entries', 'Amount due'];

            if ($money) {
                array_push(
                    $header,
                    'Registration no.', 'Payment method', 'Payment status', 'Paid at',
                    'Staff code holder', 'Marked paid by', 'Card fee covered', 'Total paid',
                    'Collected at', 'Collected by',
                    // Last, so every column before it keeps its place (BISS, 2026-09-13). An
                    // unpaid family paying the office reads Payment method "office", status
                    // "unpaid"; once paid, "cash" or "external" with how it came here.
                    'Paid via',
                );
            }

            foreach ($columns as $column) {
                $header[] = $column['label'];
            }
            fputcsv($out, $header);

            // chunkById keeps memory flat on a large registration list.
            $query->chunkById(200, function ($chunk) use ($out, $columns, $form, $money) {
                foreach ($chunk as $response) {
                    $row = [
                        optional($response->submitted_at)->format('Y-m-d H:i'),
                        $response->status,
                        $response->entry_count,
                        $response->amount_due,
                    ];

                    if ($money) {
                        $response->setRelation('form', $form);

                        array_push(
                            $row,
                            $response->id,
                            (string) $response->payment_method,
                            // 'unpaid' for a Wix-fallback row with no money leg, never blank.
                            (string) $response->paymentState(),
                            optional($response->paid_at)->format('Y-m-d H:i'),
                            // Typed by an admin, so as open to formula injection as any answer.
                            $this->csvCell((string) $response->staffCode?->holder_name),
                            $this->csvCell((string) $response->markedPaidBy?->name),
                            $response->hasMoneyLeg() ? $this->minor((int) $response->fee_covered_minor) : '',
                            $response->isPaid() && $response->total_minor !== null ? $this->minor((int) $response->total_minor) : '',
                            optional($response->collected_at)->format('Y-m-d H:i'),
                            $this->csvCell((string) $response->collectedBy?->name),
                            // From an allowlist today; guarded like every other cell anyway.
                            $this->csvCell((string) $response->paid_via),
                        );
                    }

                    foreach ($columns as $column) {
                        $row[] = $this->csvCell($this->valueFor($response, $column));
                    }

                    fputcsv($out, $row);
                }
            });

            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ------------------------------------------------------------------ internals

    /**
     * The shared query behind the list, the export, the roster and the cash totals, so
     * they can never disagree.
     */
    private function query(IndexFormResponsesRequest $request, Masjid $masjid, Form $form)
    {
        return $form->responses()
            // Redundant with the relation, but keeps the tenant predicate on every row
            // read and lets the (masjid_id, form_id, submitted_at) index do the work.
            ->where('masjid_id', $masjid->id)
            ->with(self::EAGER)
            ->search($request->input('q'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('submitted_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('submitted_at', '<=', $request->date('to')))
            ->when($request->filled('payment'), fn ($q) => $this->wherePayment($q, (string) $request->input('payment'), $form))
            ->when($request->filled('collected'), fn ($q) => $request->input('collected') === 'yes'
                ? $q->whereNotNull('collected_at')
                : $q->whereNull('collected_at'))
            ->when($request->filled('staff_code_id'), fn ($q) => $q->where('staff_code_id', (int) $request->input('staff_code_id')))
            // Column comes from an allowlist in the request class; never interpolated raw.
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', 'desc');
    }

    /**
     * payment=… — paid / unpaid / settled are FormResponse::isSettled() in SQL, so a row
     * with no money leg on a form that charges (every Wix-fallback row, before an admin
     * marks it paid) is UNPAID here and never free; cash / online / external are the
     * method, allowlisted by the request.
     */
    private function wherePayment($query, string $payment, Form $form)
    {
        return match ($payment) {
            'paid' => $query->where('payment_status', FormResponse::PAYMENT_PAID),
            'unpaid' => $query->where(fn ($q) => $this->whereUnsettled($q, $form)),
            'settled' => $query->where(fn ($q) => $this->whereSettled($q, $form)),
            default => $query->where('payment_method', $payment),
        };
    }

    /**
     * Not settled: an unpaid money leg; no money leg on a form that charges; or, on a form
     * that charges nothing, a row with no paid money leg whose own snapshot owed something.
     */
    private function whereUnsettled($q, Form $form): void
    {
        $q->where('payment_status', FormResponse::PAYMENT_UNPAID)
            ->orWhere(function ($q) use ($form) {
                $q->whereNull('payment_status');

                if (! $form->chargesFee()) {
                    $q->where(fn ($q) => $q->whereNotNull('payment_method')->orWhere(fn ($q) => $this->whereOwed($q, true)));
                }
            });
    }

    /** Settled: paid, or — on a form that charges nothing — no money leg on a row that never owed anything. */
    private function whereSettled($q, Form $form): void
    {
        $q->where('payment_status', FormResponse::PAYMENT_PAID);

        if (! $form->chargesFee()) {
            $q->orWhere(fn ($q) => $q->whereNull('payment_status')->whereNull('payment_method')->where(fn ($q) => $this->whereOwed($q, false)));
        }
    }

    /**
     * FormResponse::owedMinor() above zero, or not: the cents snapshot when there is one,
     * else the legacy decimal. Each NULL is spelled out rather than negated, because NOT
     * over a NULL comparison is NULL and the row would fall out of both halves.
     */
    private function whereOwed($q, bool $owed): void
    {
        if ($owed) {
            $q->where(fn ($q) => $q->whereNotNull('amount_due_minor')->where('amount_due_minor', '>', 0))
                ->orWhere(fn ($q) => $q->whereNull('amount_due_minor')->whereNotNull('amount_due')->where('amount_due', '>', 0));

            return;
        }

        $q->where(fn ($q) => $q->whereNotNull('amount_due_minor')->where('amount_due_minor', '<=', 0))
            ->orWhere(fn ($q) => $q->whereNull('amount_due_minor')->where(fn ($q) => $q->whereNull('amount_due')->orWhere('amount_due', '<=', 0)));
    }

    /**
     * What the screen needs to choose its payment columns, filters and actions.
     *
     * @return array<string,mixed>
     */
    private function paymentMeta(Form $form): array
    {
        return [
            // Whether this screen shows the money leg at all; both CSVs follow the same
            // switch (Form::hasPaymentSettings()).
            'enabled' => $form->hasPaymentSettings(),
            'charges_fee' => $form->chargesFee(),
            'online' => $form->takesOnlinePayment(),
            'staff_codes' => $form->takesStaffCodes(),
            // Families may pay the office (BISS, 2026-09-13), and the ways "Mark paid" can
            // say the money came — required for those families, optional otherwise.
            'office' => $form->takesOfficePayment(),
            'paid_via' => array_map(
                fn (string $via) => ['value' => $via, 'label' => FormResponse::PAID_VIA_LABELS[$via]],
                FormResponse::PAID_VIA_EXTERNAL
            ),
            // For the "Staff member" filter. Never the digest.
            'codes' => $form->staffCodes()
                ->orderBy('holder_name')
                ->orderBy('id')
                ->get()
                ->map(fn ($code) => [
                    'id' => $code->id,
                    'holder_name' => $code->holder_name,
                    'code_hint' => $code->code_hint,
                    'revoked' => $code->isRevoked(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * masjid → form → response, each through its parent, OUTSIDE any try/catch: another
     * masjid's form or response is a clean 404 from the app's JSON renderer, never a 500.
     *
     * @return array{0: Masjid, 1: Form, 2: FormResponse}
     */
    private function resolveResponse($masjid_id, $form_id, $response_id): array
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $form = $masjid->forms()->findOrFail($form_id);
        $response = $form->responses()->where('masjid_id', $masjid->id)->findOrFail($response_id);

        return [$masjid, $form, $response];
    }

    /** The response re-read under a row lock, its form attached so isSettled() needs no query. */
    private function lockRow(FormResponse $response, Form $form): FormResponse
    {
        return FormResponse::query()
            ->whereKey($response->getKey())
            ->lockForUpdate()
            ->firstOrFail()
            ->setRelation('form', $form);
    }

    private function reload(Form $form, FormResponse $response): FormResponse
    {
        return $form->responses()->with(self::EAGER)->findOrFail($response->getKey());
    }

    /** A door action done (or already done): the row as it now stands. */
    private function done(Form $form, FormResponse $response, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $this->serialize($this->reload($form, $response), $form, withData: true),
        ], Response::HTTP_OK);
    }

    /** A door action refused, in words the admin at the table can act on. */
    private function refused(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * 409 `page_unreachable` (DECISIONS.md 2026-09-15, D9): the card page was pinned to an
     * account Stripe no longer lets the platform check or close, so nothing was recorded.
     * Names the organisation whose Stripe dashboard shows the payment (never its account
     * id) and when the page stops taking payments. $expired: that time has passed, so a
     * confirmed check is all that is missing.
     */
    private function pageUnreachable(FormResponse $row, bool $expired): JsonResponse
    {
        $holder = $row->charge_masjid_id !== null ? Masjid::withTrashed()->find((int) $row->charge_masjid_id) : null;
        $name = $holder?->name ?? 'the organisation it was charged through';

        $message = $expired
            ? "Stripe no longer lets us check this registration's card payment page on {$name}'s Stripe account. The page can no longer take a payment, but it may have been paid before it closed. Check {$name}'s Stripe dashboard for this payment, then confirm you have checked. Nothing was recorded."
            : "Stripe no longer lets us check this registration's card payment page on {$name}'s Stripe account, and the page can still take a payment until it expires. Nothing was recorded. Try again after it expires.";

        return response()->json([
            'status' => 'failed',
            'code' => 'page_unreachable',
            'message' => $message,
            'holder_name' => $holder?->name,
            'expires_at' => optional($row->charge_expires_at)->toIso8601String(),
        ], Response::HTTP_CONFLICT);
    }

    private function failed(\Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => Errors::publicMessage($e),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * One column per question in the schema, flattening a repeatable section to a single
     * summarised column (the admin table cannot grow a column per attendee).
     *
     * @return array<int,array{key:string,label:string,section:?string,repeatable:bool,field:string}>
     */
    private function columns(Form $form): array
    {
        $columns = [];

        foreach ($form->sections() as $section) {
            $sectionId = $section['id'] ?? null;
            $repeatable = ! empty($section['repeatable']);

            foreach ($section['fields'] ?? [] as $field) {
                if (! is_array($field) || ! isset($field['name'])) {
                    continue;
                }

                $columns[] = [
                    'key' => $repeatable ? $sectionId . '.' . $field['name'] : $field['name'],
                    'label' => $field['label'] ?? $field['name'],
                    'section' => $repeatable ? ($section['title'] ?? $sectionId) : null,
                    'repeatable' => $repeatable,
                    'field' => $field['name'],
                ];
            }
        }

        return $columns;
    }

    /** Read one column's value out of a response, joining repeatable rows. */
    private function valueFor(FormResponse $response, array $column): string
    {
        $data = $response->data ?? [];

        if ($column['repeatable']) {
            $sectionId = explode('.', $column['key'])[0];
            $rows = $data[$sectionId] ?? [];

            if (! is_array($rows)) {
                return '';
            }

            return collect($rows)
                ->map(fn ($row) => is_array($row) ? ($row[$column['field']] ?? '') : '')
                ->filter(fn ($v) => $v !== '' && $v !== null)
                ->map(fn ($v) => $this->stringify($v))
                ->implode(' | ');
        }

        return $this->stringify($data[$column['field']] ?? '');
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * Excel and Sheets evaluate a cell beginning with =, +, - or @ as a formula, so a
     * registrant could put `=HYPERLINK(...)` in a name field and have it execute when a
     * volunteer opens the export. Prefixing with an apostrophe makes it literal text.
     */
    private function csvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * List rows stay light: the full submission is only sent for a single response.
     * `data` on every row of a 300-row page would be megabytes of PII in one payload.
     */
    private function serialize(FormResponse $response, Form $form, bool $withData = false): array
    {
        // The form is in hand; isSettled() reads it for a row with no money leg. Read only
        // on a form set up to take payment: a fee form that never was (the camp) has no
        // payment state to show, and reading one would badge every family "Unpaid".
        $response->setRelation('form', $form);
        $money = $form->hasPaymentSettings();
        $state = $money ? $response->paymentState() : null;

        $row = [
            'id' => $response->id,
            'form_id' => $response->form_id,
            'respondent_name' => $response->respondent_name,
            'respondent_email' => $response->respondent_email,
            'respondent_phone' => $response->respondent_phone,
            'entry_count' => $response->entry_count,
            'amount_due' => $response->amount_due,
            'status' => $response->status,
            'admin_notes' => $response->admin_notes,
            'submitted_at' => optional($response->submitted_at)->toIso8601String(),

            // The money leg and the door (DECISIONS.md 2026-09-11): the raw columns, plus
            // the two readings the screen acts on — `payment_state` for the badge ('paid';
            // 'unpaid', a Wix-fallback row with no money leg included; or null when nothing
            // was owed) and `settled` for "Mark collected". Both null on a form not set up
            // to take payment.
            'uuid' => $response->uuid,
            'payment_method' => $response->payment_method,
            'payment_status' => $response->payment_status,
            'payment_state' => $state,
            'settled' => $money ? $state !== FormResponse::PAYMENT_UNPAID : null,
            'currency' => $response->currency,
            'amount_due_minor' => $response->amount_due_minor,
            'fee_covered_minor' => (int) $response->fee_covered_minor,
            'total_minor' => $response->total_minor,
            'paid_at' => optional($response->paid_at)->toIso8601String(),
            // How a payment recorded by hand came (FormResponse::PAID_VIA), or null.
            'paid_via' => $response->paid_via,
            // An unpaid card registration whose hosted page has been opened. It may have
            // expired since (a page lives 30 minutes); "Take cash" closes it either way,
            // and refuses if Stripe says it was paid.
            'card_page_opened' => $response->payment_method === FormResponse::METHOD_ONLINE
                && $state === FormResponse::PAYMENT_UNPAID
                && $response->stripe_checkout_session_id !== null,
            // Charged through another organisation's account (DECISIONS.md 2026-09-15): who
            // holds the charge ({id, name}, never its account id), the payment intent that
            // organisation finds it by, what it did to the charge in its own dashboard
            // (`refunded` | `disputed`, which never changes payment_status), and whether the
            // unpaid page is known to be beyond checking. Null / false for every other row.
            'charged_through' => $this->chargedThroughOf($response),
            'stripe_payment_intent_id' => $response->stripe_payment_intent_id,
            'charge_flag' => $response->charge_flag,
            'charge_flagged_at' => optional($response->charge_flagged_at)->toIso8601String(),
            // How much of that charge the holder has refunded so far (minor units), or null:
            // below total_minor is a PARTIAL refund (the card fee back, say), not a full one.
            'charge_refunded_minor' => $response->charge_refunded_minor,
            'page_unreachable' => $this->knownUnreachable($response),
            // Whose cash this is, at the gate. Never the code, and never its digest.
            'staff_code' => $response->staffCode !== null ? [
                'id' => $response->staffCode->id,
                'holder_name' => $response->staffCode->holder_name,
                'code_hint' => $response->staffCode->code_hint,
            ] : null,
            'marked_paid_by' => $this->person($response->markedPaidBy),
            'collected_at' => optional($response->collected_at)->toIso8601String(),
            'collected_by' => $this->person($response->collectedBy),
            'status_changed_at' => optional($response->status_changed_at)->toIso8601String(),
            'status_changed_by' => $this->person($response->statusChangedBy),
        ];

        if ($withData) {
            $row['data'] = $response->data;
            // Only on the detail view, for the same reason `data` is: the list would
            // otherwise issue a query per row for something no list column shows. The
            // filename itself already appears in `data` under the field's own name.
            $row['attachments'] = $this->attachments($response);
        }

        return $row;
    }

    /** @return array{id: int, name: ?string}|null */
    private function person(?User $user): ?array
    {
        return $user !== null ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    /** Integer cents as a plain decimal for a spreadsheet ("30.00"), with no float in between. */
    private function minor(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The uploaded files on one response, each with the authenticated URL that serves
     * it. There is no public URL to give — the link points back at
     * downloadAttachment(), which re-checks the tenant before streaming anything.
     *
     * @return array<int,array<string,mixed>>
     */
    private function attachments(FormResponse $response): array
    {
        $base = url("/api/admin/masjids/{$response->masjid_id}/forms/{$response->form_id}/responses/{$response->id}/attachments");

        return $response->attachments()
            ->orderBy('id')
            ->get()
            ->map(fn ($attachment) => array_merge(
                $attachment->toAdminArray(),
                ['download_url' => "{$base}/{$attachment->id}"],
            ))
            ->all();
    }
}
