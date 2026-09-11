<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Forms\SubmitFormResponseRequest;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Services\Stripe\FormCheckoutRefused;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\Errors;
use App\Support\FormAttachments;
use App\Support\FormNotifier;
use App\Support\FormPayment;
use App\Support\FormPaymentReturn;
use App\Support\FormSchema;
use App\Support\FormStaffCodes;
use App\Support\PublicTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The public form-submission endpoint.
 *
 * This is the only unauthenticated write in the forms feature, so it is written
 * defensively:
 *
 *  - The masjid comes from the `masjid-id` header, must STILL EXIST, and the form must
 *    belong to it. It does NOT use Section::scopeFilterByMasjid, which no-ops when the
 *    header is absent and would let a caller submit against any tenant's form.
 *
 *    The existence half was added on 2026-08-12 alongside the same omission on the
 *    offering endpoints. Filtering `masjid_id` proves the form belongs to the id; it
 *    never asks whether the id still names anybody, and `masjids` SOFT-deletes — so an
 *    offboarded organisation's forms went on accepting public submissions, notifying
 *    its former coordinators and emailing receipts on its behalf, indefinitely
 *    (App\Support\PublicTenant).
 *  - Validation is derived from the stored schema (App\Support\FormSchema), never from
 *    the payload. The renderer validating client-side is a convenience; this decides.
 *  - Anything the schema does not declare is dropped before storage, so a caller cannot
 *    inject content that later renders in the admin table or reaches the assistant.
 *  - The window and capacity are re-checked here, inside the same transaction that
 *    writes the row, because a page can sit open in a tab long after a form closed.
 *  - A honeypot field catches the naive bots; `throttle:form-submit` catches the rest.
 *  - Uploads (a `file` question — the Schools careers form's résumé) arrive in their
 *    own `files` bag and are type/size-checked at the boundary by
 *    SubmitFormResponseRequest before anything here runs. They are written to a
 *    PRIVATE disk inside the same transaction as the response, so a submission that
 *    loses the race for the last place leaves no bytes behind.
 *
 * Once a submission is accepted it also notifies the masjid's coordinators and sends the
 * submitter a receipt (App\Support\FormNotifier) — a registration nobody is told about is
 * only half-accepted.
 *
 * ## Cash at the gate (DECISIONS.md 2026-09-11)
 *
 * A submission carrying a staff credential — the token a staff member's phone got for
 * its code (FormStaffSessionsController), or the code itself — is a walk-up who has
 * handed that staff member cash. It is settled as cash in the SAME transaction that
 * writes it, at the list price, stamped with the code, so the holder owes it
 * (FormResponse::settleCash()). There is no Stripe call, so never a $0 session. The
 * credential is checked BEFORE the answers (App\Support\FormStaffCodes), and every way
 * it can fail is one uniform 422. A staff entry ignores the registration window —
 * walk-ups arrive after online sales close — but not an inactive or full form. The
 * answer never names the holder.
 *
 * ## Card payment (DECISIONS.md 2026-09-11)
 *
 * On a form that takes cards, a submission without a staff credential is a card
 * registration. Everything that could stop Stripe's page opening is asked BEFORE
 * anything is written: the organisation can take a card (Connect live), the total is
 * chargeable, the request's Origin is on config('forms.payment_return_origins') and
 * its return_path is a relative path (App\Support\FormPaymentReturn). A refusal
 * leaves no row. The row is then written UNPAID with its integer-cents snapshot
 * (App\Support\FormPayment, with the card fee only when the payer ticked the box and
 * the form offers it), and the hosted page is opened after the commit
 * (App\Services\Stripe\FormResponseCheckoutService). Nobody is emailed yet: the
 * receipt and the coordinator email go when the signed webhook marks the row paid. If
 * Stripe fails after the commit, the answer is a 422 carrying the uuid, and the page
 * offers "Try payment again" (POST /api/v1/form-responses/{uuid}/checkout).
 *
 * ## Never free by accident
 *
 * A form that takes payment (card or staff codes) never takes a submission that owes
 * nothing: an empty attendee list sent past the renderer, or a $0 price in force, is a
 * 422 — never the free path, which would hand out the group link and a bracelet.
 *
 * ## The replay guard
 *
 * The renderer sends one `client_submission_key` per render. Wherever money moves (a form
 * that takes payment, or any request carrying a staff credential) a request without one
 * is refused as out of date: an opt-in guard is no guard on the one path where a
 * double-tap makes a holder owe twice. Every other form keeps it optional, as it always
 * was. A second request with the
 * same key and the same submission gets the FIRST row's answer — nothing is written,
 * settled, counted or emailed twice, which for cash is a holder owing twice for one
 * walk-up. The same key with a different submission (edited after a lost response,
 * or under another credential) is a 409, never the old row. A double-tap that races
 * past the lookup meets the unique index, and is answered the same way — never a 500.
 * A card registration that is still unpaid gets its payment page back: the SAME page
 * while it is open, so a double-tap never leaves a payer holding two.
 *
 * Pinned by tests/Feature/FormSubmissionTest.php, tests/Feature/FormStaffCodeTest.php
 * and tests/Feature/FormPaymentCheckoutTest.php.
 */
class FormSubmissionsController extends Controller
{
    /**
     * POST /api/v1/forms/{form_id}/responses
     */
    public function store(SubmitFormResponseRequest $request, $form_id)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            // Same 404 as a missing form, so an offboarded organisation is
            // indistinguishable from one that never had this form.
            if (! PublicTenant::exists($masjidId)) {
                return response()->api(404, 'This form is not available.', null);
            }

            $form = Form::query()
                ->where('masjid_id', $masjidId)
                ->whereKey($form_id)
                ->first();

            // Same response whether the form does not exist, belongs to another masjid,
            // or is soft-deleted — no probing for which forms exist where.
            if (! $form) {
                return response()->api(404, 'This form is not available.', null);
            }

            if (! $form->is_active) {
                return response()->api(422, 'This form is not currently accepting responses.', null);
            }

            // Read before the honeypot, because it changes what the honeypot answers.
            $credential = FormStaffCodes::presented($request);

            // A bot filling every input trips this; a human never sees the field.
            if (filled($request->input('website'))) {
                if ($credential !== null) {
                    // A staff member is about to take cash on the strength of this
                    // answer, so the fake success below would be money in hand and
                    // nothing recorded. Say so, loudly.
                    return response()->api(422, 'This entry was not recorded. Reload the page and try again.', null);
                }

                // Report success so a scripted submitter gets no signal to adapt to,
                // while nothing is written.
                return response()->api(200, 'Thank you — your response has been received.', [
                    'id' => null,
                ]);
            }

            // The replay guard is what stops a double-tapped cash entry making its holder
            // owe twice, and a double-tapped card registration writing a second row with a
            // second page, so it is required wherever money moves. Asked before the
            // credential, and it says nothing about it.
            if ($request->input('client_submission_key') === null
                && ($credential !== null || $form->takesOnlinePayment() || $form->takesStaffCodes())) {
                return response()->json([
                    'status' => 'failed',
                    'data' => ['client_submission_key' => [SubmitFormResponseRequest::OUT_OF_DATE]],
                ], 422);
            }

            // The staff credential, BEFORE the answers: a wrong code counts against the
            // failure limiter even when the rest would not have validated, and a request
            // carrying any credential either presents a good one for this form or
            // writes nothing — so junk codes cannot get round the public limit.
            $staffCode = null;

            if ($credential !== null) {
                $check = FormStaffCodes::check($form, $masjidId, $request);

                if ($check->isLockedOut()) {
                    return FormStaffCodes::lockedOutResponse($check->retryAfter);
                }

                if (! $check->isAccepted()) {
                    return FormStaffCodes::refusedResponse();
                }

                $staffCode = $check->code;
            }

            $submitted = $request->input('data');

            if (! is_array($submitted)) {
                return response()->api(422, 'No form data was submitted.', null);
            }

            $schema = FormSchema::for($form);

            // Only the uploads this form actually asked for; anything else in the
            // bag is dropped, exactly as undeclared answers are. Empty for every
            // form without a `file` question, which leaves the rest untouched.
            $uploads = $schema->uploads($request->file(FormSchema::UPLOAD_KEY));

            // Validated together with the answers so a missing REQUIRED résumé
            // reports under its own field name, alongside every other error, in one
            // field bag — rather than as a second, differently shaped rejection.
            $validator = $schema->validator(array_merge($submitted, $uploads));

            if ($validator->fails()) {
                // Field bag under `data`, matching how the SPA and the Nuxt site already
                // read 422s from BaseFormRequest.
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors(),
                ], 422);
            }

            $clean = $schema->only($submitted);

            // A card registration: no staff credential, on a form that takes cards. A
            // staff entry on the same form is cash, whatever the card switch says.
            $online = $staffCode === null && $form->takesOnlinePayment();

            // Never free by accident. Only a form that takes payment is asked, so every
            // other form keeps exactly the behaviour it had. The card fee is the payer's
            // yes/no, priced here, and only on a card payment.
            $quote = null;

            if ($form->takesOnlinePayment() || $form->takesStaffCodes()) {
                $quote = FormPayment::quote($form, $clean, $online && $request->boolean('cover_fees'), $online);

                if ($quote === null || $quote['total_minor'] <= 0) {
                    return $this->owesNothing($form, $quote);
                }
            }

            // A card payment: everything that could stop the page opening, BEFORE any
            // write, so a refusal never leaves an unpayable row behind.
            $returnTo = null;

            if ($online) {
                $refusal = FormResponseCheckoutService::refusal(Masjid::find($masjidId), $quote['total_minor']);

                if ($refusal !== null) {
                    return response()->api(422, $refusal, null);
                }

                $returnTo = FormPaymentReturn::base($request, ['masjid_id' => $masjidId, 'form_id' => $form->id]);

                if ($returnTo === null) {
                    return response()->api(422, FormPaymentReturn::REFUSED, null);
                }
            }

            $clientKey = $request->input('client_submission_key');
            $fingerprint = $this->fingerprint($clean, $staffCode, $quote);

            try {
                [$outcome, $response] = DB::transaction(function () use ($form, $clean, $schema, $request, $masjidId, $uploads, $staffCode, $quote, $clientKey, $fingerprint, $online): array {
                    // Re-read inside the transaction and lock, so two submissions racing for
                    // the last place cannot both pass the capacity check. The counter is the
                    // thing capacity is enforced against, so it must be read under the lock
                    // that the insert will update.
                    $locked = Form::whereKey($form->id)->lockForUpdate()->first();

                    if (! $locked) {
                        return ['closed', null];
                    }

                    // Before the acceptance check: a retry of a submission that was
                    // accepted gets its answer even if the form has filled up since.
                    if ($clientKey !== null && ($earlier = $this->earlierSubmission((int) $locked->id, $clientKey)) !== null) {
                        return ['replay', $earlier];
                    }

                    // A staff entry ignores the registration WINDOW; everyone else does not.
                    // Inactive and full refuse both.
                    $accepting = $staffCode !== null ? $locked->acceptsStaffEntry() : $locked->acceptsSubmissions();

                    if (! $accepting) {
                        return ['closed', null];
                    }

                    // The code again, under its own lock: one revoked since it was checked
                    // records nothing, and one revoked from now on waits for this entry.
                    if ($staffCode !== null) {
                        $live = FormStaffCode::withoutMasjidScope()->whereKey($staffCode->getKey())->lockForUpdate()->first();

                        if ($live === null || ! $live->isUsable()) {
                            return ['refused', null];
                        }
                    }

                    $created = new FormResponse(array_merge(
                        [
                            'form_id' => $locked->id,
                            'masjid_id' => $masjidId,
                            'data' => $clean,
                            'entry_count' => $schema->entryCount($clean),
                            'amount_due' => $schema->amountDue($clean),
                            'status' => 'new',
                            'device_id' => $request->input('device_id'),
                            'ip_address' => $request->ip(),
                            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                            'submitted_at' => now(),
                        ],
                        $schema->identity($clean)
                    ));

                    // Not fillable: the replay guard's pair, and — for a staff entry — the
                    // cents snapshot the cash is settled from (App\Support\FormPayment).
                    $guarded = [];

                    if ($clientKey !== null) {
                        $guarded['client_submission_key'] = $clientKey;
                        $guarded['client_payload_hash'] = FormResponse::payloadHash($fingerprint);
                    }

                    if ($staffCode !== null) {
                        $guarded['amount_due_minor'] = $quote['amount_due_minor'];
                        $guarded['currency'] = $quote['currency'];
                    }

                    // A card registration is written UNPAID, with the snapshot Stripe is
                    // charged from. Only the signed webhook moves it to paid.
                    if ($online) {
                        $guarded += [
                            'payment_method' => FormResponse::METHOD_ONLINE,
                            'payment_status' => FormResponse::PAYMENT_UNPAID,
                            'currency' => $quote['currency'],
                            'amount_due_minor' => $quote['amount_due_minor'],
                            'fee_covered_minor' => $quote['fee_covered_minor'],
                            'total_minor' => $quote['total_minor'],
                        ];
                    }

                    $created->forceFill($guarded)->save();

                    // Inside the transaction, and only once the row exists: a submission
                    // that arrives after the last place is taken returns above without
                    // ever touching the disk, and a write that fails here rolls the
                    // response back (FormAttachments removes what it had written).
                    $names = FormAttachments::store($created, $uploads);

                    if ($names !== []) {
                        // The respondent's filenames go back into `data` under their
                        // field names, so the admin table and the CSV export have a cell
                        // to render — the bytes stay on the private disk, reachable only
                        // through the authenticated download endpoint.
                        $created->update(['data' => array_merge($clean, $names)]);
                    }

                    // Cash its holder owes, in the transaction that wrote the row: the row
                    // never exists unpaid, and use_count moves with it.
                    if ($staffCode !== null && ! $created->settleCash($staffCode)) {
                        // Unreachable — the code is locked above and the row is new — so
                        // a false here is a bug: loud, and the row rolls back with it.
                        throw new LogicException("Staff code {$staffCode->getKey()} did not settle the entry it was checked for.");
                    }

                    return ['created', $created];
                });
            } catch (UniqueConstraintViolationException $e) {
                // A double-tap that raced past the lookup: the other request's row is
                // committed, and it is the answer.
                $earlier = is_string($clientKey) && self::isClientKeyViolation($e)
                    ? $this->earlierSubmission((int) $form->id, $clientKey)
                    : null;

                if ($earlier === null) {
                    throw $e;
                }

                return $this->replayOf($form, $earlier, $fingerprint, $returnTo);
            }

            if ($outcome === 'replay') {
                return $this->replayOf($form, $response, $fingerprint, $returnTo);
            }

            if ($outcome === 'refused') {
                return FormStaffCodes::refusedResponse();
            }

            if ($outcome === 'closed') {
                // Closed or full between the page loading and the submit landing. A staff
                // entry is told why in ITS terms: the window it ignores is not the reason.
                $fresh = $form->fresh();

                $reason = $staffCode === null
                    ? $fresh?->closedReason()
                    : ($fresh?->isAtCapacity() ? 'This form has reached capacity.' : 'This form is not currently accepting responses.');

                return response()->api(422, $reason ?? 'This form is no longer accepting responses.', null);
            }

            // A card registration is not settled until Stripe says so, so nobody is
            // emailed now: the webhook sends the receipt and the coordinator email when
            // it marks the row paid.
            if ($online) {
                return $this->openPayment($form, $response, $returnTo, fresh: true);
            }

            // Deliberately after the transaction: the registration is already committed, so
            // a mail failure can neither roll it back nor reach the submitter. FormNotifier
            // swallows its own errors for the same reason.
            FormNotifier::submitted($form, $response);

            return $this->accepted($form, $response);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * The row an earlier request with this client_submission_key wrote on this form.
     *
     * Protected so a test can open the race window between this lookup and the insert
     * (tests/Feature/FormStaffCodeTest.php); nothing else overrides it.
     */
    protected function earlierSubmission(int $formId, string $clientKey): ?FormResponse
    {
        return FormResponse::query()
            ->where('form_id', $formId)
            ->where('client_submission_key', $clientKey)
            ->first();
    }

    /**
     * What the replay guard compares: everything that decides what a row owes and who
     * owes it — the cleaned answers (the attendee rows are the quantity), the code
     * whose holder takes the cash, and whether a card payer covers the card fee. A
     * replay that differs in any of it is a different submission. Anything later
     * added to what a row owes belongs in here too.
     *
     * @param  array<string,mixed>  $clean
     * @param  array<string,mixed>|null  $quote  FormPayment::quote()
     * @return array<string,mixed>
     */
    private function fingerprint(array $clean, ?FormStaffCode $staffCode, ?array $quote): array
    {
        return [
            'data' => $clean,
            'staff_code_id' => $staffCode?->getKey(),
            'fee_covered' => $quote !== null && $quote['fee_covered_minor'] > 0,
        ];
    }

    /**
     * The answer to a replayed client_submission_key: the first row's own answer when
     * the replay is the same submission, and a 409 when it is not — a card payer would
     * otherwise pay for fewer people than they entered, and a holder owe less than
     * they took. A card registration that is still unpaid gets its payment page back
     * (FormResponseCheckoutService::reopen()): the same page while it is open.
     *
     * @param  array<string,mixed>  $fingerprint
     * @param  string|null  $returnTo  the checked return address, when this request is a card payment
     */
    private function replayOf(Form $form, FormResponse $earlier, array $fingerprint, ?string $returnTo)
    {
        if (! $earlier->matchesPayload($fingerprint)) {
            return response()->api(409, 'This entry was already sent with different answers. Reload the page and try again.', null);
        }

        if ($returnTo !== null && $earlier->payment_method === FormResponse::METHOD_ONLINE && ! $earlier->isPaid()) {
            return $this->openPayment($form, $earlier, $returnTo, fresh: false);
        }

        return $this->accepted($form, $earlier);
    }

    /**
     * Send a card registration to Stripe's hosted page: the first page for a row the
     * submit has just written, or, for a replay of one still unpaid, its page again.
     *
     * The row is committed and stays unpaid whatever happens here, and nobody is
     * emailed. A refusal, or Stripe failing, is a 422 that still carries the uuid and
     * the amounts, so the page can offer "Try payment again" rather than starting over.
     * The one exception is a page Stripe says was paid before the webhook has recorded
     * it: that 422 says `confirming: true` and `can_pay: false`, so the page waits for
     * the webhook and never offers to pay again (FormCheckoutRefused::answer()).
     * The service is resolved here rather than injected, so a form that takes no card
     * never builds a Stripe client.
     */
    private function openPayment(Form $form, FormResponse $response, string $returnTo, bool $fresh)
    {
        $checkout = app(FormResponseCheckoutService::class);

        try {
            $page = $fresh ? $checkout->checkout($response, $returnTo) : $checkout->reopen($response, $returnTo);
        } catch (FormCheckoutRefused $e) {
            return response()->api(422, $e->getMessage(), $e->answer($this->answerFor($form, $response)));
        } catch (\Stripe\Exception\ExceptionInterface $e) {
            FormResponseCheckoutService::reportFailure($e, $response);

            return response()->api(422, FormResponseCheckoutService::COULD_NOT_OPEN, $this->answerFor($form, $response));
        }

        return $this->accepted(
            $form,
            $page['response'],
            ['checkout_url' => $page['checkout_url']],
            'Continue to payment to complete your registration.'
        );
    }

    /**
     * The success answer. Every form keeps the keys it always had; a row with a money
     * leg adds its payment state, and the group link rides along only once the row is
     * settled (FormResponse::isSettled()) — never to someone who has not paid, nor to a
     * cancelled registration. A card registration adds the hosted page's address,
     * `checkout_url`, through $extra.
     *
     * @param  array<string,mixed>  $extra
     */
    private function accepted(Form $form, FormResponse $response, array $extra = [], string $message = 'Thank you — your response has been received.')
    {
        return response()->api(200, $message, $this->answerFor($form, $response) + $extra);
    }

    /**
     * A written row as the page is told about it: the keys every form always had, the
     * payment state of a row with a money leg (whether an admin cancelled it included,
     * so a replay of a refunded card registration never reads as paid), and the group
     * link once it is settled and not cancelled.
     *
     * @return array<string,mixed>
     */
    private function answerFor(Form $form, FormResponse $response): array
    {
        $settings = $form->settings ?? [];

        $data = [
            'id' => $response->id,
            'amount_due' => $response->amount_due,
            'entry_count' => $response->entry_count,
            'success_title' => $settings['successTitle'] ?? null,
            'success_body' => $settings['successBody'] ?? null,
            'success_next_steps' => $settings['successNextSteps'] ?? [],
        ];

        if ($response->hasMoneyLeg()) {
            // Never the holder: the confirmation a walk-up may glimpse on the staff
            // member's phone says the entry was recorded, not against whom.
            $data += [
                'uuid' => $response->uuid,
                'cancelled' => $response->isCancelled(),
                'payment_method' => $response->payment_method,
                'payment_status' => $response->payment_status,
                'amount_due_minor' => $response->amount_due_minor,
                'fee_covered_minor' => (int) $response->fee_covered_minor,
                'total_minor' => $response->total_minor,
                'currency' => $response->currency,
            ];
        }

        $whatsapp = $form->whatsappUrl();

        if ($whatsapp !== null && ! $response->isCancelled() && $response->setRelation('form', $form)->isSettled()) {
            $data['whatsapp_url'] = $whatsapp;
            $data['whatsapp_label'] = $settings['whatsappLabel'] ?? null;
        }

        return $data;
    }

    /**
     * A form that takes payment was sent a submission that owes nothing. Refused
     * (festival brief, blocker 1): taking it would register someone for free by
     * accident. An empty attendee list is reported on its section, so the renderer
     * shows it there; a $0 price in force is the form's fault, not the submitter's.
     *
     * @param  array<string,mixed>|null  $quote  FormPayment::quote()
     */
    private function owesNothing(Form $form, ?array $quote)
    {
        $section = $quote !== null && $quote['quantity'] === 0 ? ($form->feeRule()['perEntryOfSection'] ?? null) : null;

        if (is_string($section) && $section !== '') {
            return response()->json([
                'status' => 'failed',
                'data' => [$section => ['Add at least one entry.']],
            ], 422);
        }

        return response()->api(422, 'This form cannot take entries right now.', null);
    }

    /**
     * Whether a unique violation is the replay guard's index and not another one. The
     * driver's own message, not getMessage(), which also carries the SQL — and the
     * INSERT names client_submission_key whichever index objected.
     */
    private static function isClientKeyViolation(UniqueConstraintViolationException $e): bool
    {
        $driver = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driver, FormResponse::CLIENT_KEY_UNIQUE_INDEX)   // MySQL names the index
            || str_contains($driver, 'form_responses.client_submission_key'); // SQLite names the columns
    }
}
