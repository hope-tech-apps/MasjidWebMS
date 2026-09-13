<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\Member\UpdateRecurringGiftRequest;
use App\Models\Contact;
use App\Models\DonationSubscription;
use App\Services\Stripe\DonationService;
use App\Services\Stripe\RecurringAmountChangedOnlyAtStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Your monthly giving" — a donor managing their OWN standing commitments.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SIGNED-IN MEMBER ROUTE AND NOT A SIGNED LINK
 * ---------------------------------------------------------------------------
 * A donor identity already exists and is a real one. Every succeeded card gift
 * and every recurring commitment gets a `Contact` on that masjid, find-or-created
 * by the Stripe webhook from the Checkout Session's `customer_details.email`
 * (StripeWebhookController::linkSubscriptionContact → DonorContactService), so
 * `donation_subscriptions.contact_id` is populated for essentially every real
 * recurring donor. That same Contact can authenticate: the member realm's
 * passwordless email-code sign-in is over the `contacts` provider, and
 * `member.active` gates on `contacts.verified_at` rather than
 * `login_enabled_at`, so a donor needs no office action to reach this screen.
 * `MemberSignupService` merges onto an existing contact by email and never
 * duplicates it, so the row the webhook created IS the row they sign in as.
 *
 * So the door is the member bearer token. The repo's one signed-token flow
 * (AccountAccessService) is typed to `App\Models\User` — staff — and rides
 * `password_reset_tokens`; it is not reusable here, and inventing a second
 * emailed-secret channel for money would be a new attack surface for no gain.
 *
 * ---------------------------------------------------------------------------
 * TWO CLAUSES, ALWAYS, AND A 404 RATHER THAN A 403
 * ---------------------------------------------------------------------------
 * A commitment is addressed by `uuid` and resolved by `uuid` AND `contact_id`.
 * The tenant scope alone is not enough: it fences off other ORGANISATIONS, and
 * every member of THIS organisation would still be inside it. `contact_id` is
 * what makes a commitment the caller's own.
 *
 * A miss is `firstOrFail()` — a 404. Not a 403, which would confirm that the
 * uuid names a real commitment belonging to somebody else. The member realm's
 * whole sign-in design is that it discloses nothing about who exists
 * (MemberSignupService); a 403 here would re-open that leak in a new place, and
 * for donation records, whose mere existence says something about a person.
 *
 * The `{masjid_id}` in the path is decorative, exactly as on every other member
 * route: `family.tenant` binds the tenant from the TOKEN's contact and refuses
 * (403) a path naming anyone else, so a token cannot be pointed at another
 * organisation by editing the URL.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS WRITTEN HERE AND WHAT IS NOT
 * ---------------------------------------------------------------------------
 * See the long block comment on DonationService's self-service verbs. In short:
 * pause and resume are statements about Stripe's billing clock and write NOTHING
 * locally (the pause a screen shows is READ BACK from Stripe); an amount change
 * writes the commitment's own terms, after Stripe has accepted it and using the
 * figure Stripe echoed; a cancel writes 'canceled' only once Stripe agrees the
 * subscription has stopped (`cancelSubscriptionOrFail`, not the admin surface's
 * tolerant `cancelSubscription`). No individual `donations` row is ever touched
 * by any of them — a gift that has been made is settled money and belongs to the
 * webhook.
 *
 * The single rule underneath all four: THE LOCAL ROW IS NEVER THE SOURCE OF
 * TRUTH ABOUT WHAT STRIPE DID. It may only ever repeat something Stripe has
 * already said. That is why every failure on this surface can honestly be
 * answered "nothing has changed", and why the one case where it cannot —
 * Stripe accepted an amount our database then refused to record — is a distinct
 * type, a distinct status code and a critical log rather than a shrug.
 */
class MemberRecurringGivingController extends Controller
{
    public function __construct(private DonationService $donations)
    {
    }

    /**
     * GET — this donor's standing commitments, newest first.
     *
     * Cancelled ones are included rather than hidden: "I cancelled that in
     * March" is exactly the thing a donor comes to this screen to confirm, and
     * a list that silently omits them reads as data loss.
     */
    public function index(Request $request)
    {
        /** @var Contact $contact */
        $contact = $request->user();

        $commitments = DonationSubscription::query()
            ->with('fund')
            ->withCount('donations')
            ->where('contact_id', $contact->id)
            ->latest()
            ->get();

        // One Stripe read per LIVE commitment, because a pause has nowhere local
        // to live (DonationService explains why). A donor holds one or two of
        // these, and `pauseStateOf` degrades to null rather than throwing, so a
        // slow or unreachable Stripe cannot stop somebody opening their own
        // giving screen. A cancelled commitment is not asked about at all: it is
        // not paused, it is over, and its answer costs no API call.
        return $this->ok(
            $commitments->map(fn (DonationSubscription $c) => $this->payload(
                $c,
                $c->status === 'canceled' ? false : $this->donations->pauseStateOf($c)
            ))->all()
        );
    }

    /**
     * POST /{uuid}/pause — stop the charges without retiring the commitment.
     *
     * A real `pause_collection` at Stripe. Stripe raises the invoices, so only
     * Stripe can decline to raise the next one; a local flag would be a promise
     * the billing clock never hears.
     */
    public function pause(Request $request, $masjid_id, string $uuid)
    {
        $commitment = $this->ownCommitment($request, $uuid);

        if ($refusal = $this->refusalFor($commitment)) {
            return $refusal;
        }

        return $this->throughStripe(
            $commitment,
            fn () => $this->donations->pauseSubscription($commitment),
            'pause'
        );
    }

    /** POST /{uuid}/resume — lift the pause on the SAME Stripe subscription. */
    public function resume(Request $request, $masjid_id, string $uuid)
    {
        $commitment = $this->ownCommitment($request, $uuid);

        if ($refusal = $this->refusalFor($commitment)) {
            return $refusal;
        }

        return $this->throughStripe(
            $commitment,
            fn () => $this->donations->resumeSubscription($commitment),
            'resume'
        );
    }

    /**
     * POST /{uuid}/cancel — stop all future charges.
     *
     * Idempotent at the top: cancelling an already-cancelled commitment is a
     * success that changes nothing, so a donor tapping twice on a slow
     * connection is never shown an error about money. That short-circuit is
     * asked FIRST, which is also why this verb cannot simply call
     * `refusalFor()` — that helper 422s a cancelled commitment, which is the
     * right answer for pause, resume and reprice and the wrong one here.
     *
     * Everything after the short-circuit is held to the same standard as the
     * other three verbs, and for a stronger reason:
     *
     *   - NOT SET UP YET is a refusal, not a local cancel. A commitment with no
     *     `stripe_subscription_id` is a checkout that has not completed. Marking
     *     it cancelled here would contact Stripe not at all, and the
     *     `checkout.session.completed` that lands seconds later pins a live
     *     subscription onto a row that says 'canceled' — Stripe bills every
     *     month, `bookRecurringInvoice` books and receipts every charge, the
     *     donor's screen says cancelled, and no verb on this surface can ever
     *     reach that subscription again (every one of them refuses a cancelled
     *     row). So the donor is asked to try again in a moment instead.
     *
     *   - A STRIPE REFUSAL IS A REFUSAL. This runs through `throughStripe`
     *     against `cancelSubscriptionOrFail`, which writes nothing unless Stripe
     *     agrees the subscription has stopped. The admin's tolerant
     *     `cancelSubscription` is deliberately NOT used here: see its sibling's
     *     docblock for why the same tolerance is right for staff and
     *     indefensible for a donor.
     */
    public function cancel(Request $request, $masjid_id, string $uuid)
    {
        $commitment = $this->ownCommitment($request, $uuid);

        if ($commitment->status === 'canceled') {
            return $this->ok($this->payload($commitment, false));
        }

        if ($refusal = $this->refusalFor($commitment)) {
            return $refusal;
        }

        return $this->throughStripe(
            $commitment,
            fn () => $this->donations->cancelSubscriptionOrFail($commitment),
            'cancel',
            self::CANCEL_FAILED,
            // A cancelled subscription collects nothing, so the answer is known
            // without a second Stripe read — and asking about a subscription we
            // have just cancelled is a call that can only add a way to fail.
            pausedAfter: false
        );
    }

    /**
     * PATCH /{uuid} — change what this commitment charges, from the next
     * invoice on.
     *
     * The fund and the zakat designation are not editable here; see
     * UpdateRecurringGiftRequest for why each one is a cancel-and-restart
     * rather than a field.
     */
    public function updateAmount(UpdateRecurringGiftRequest $request, $masjid_id, string $uuid)
    {
        $commitment = $this->ownCommitment($request, $uuid);

        if ($refusal = $this->refusalFor($commitment)) {
            return $refusal;
        }

        return $this->throughStripe(
            $commitment,
            fn () => $this->donations->changeSubscriptionAmount($commitment, $request->intendedAmount()),
            'amount change'
        );
    }

    // ------------------------------------------------------------------ guts

    /**
     * The caller's own commitment, or a 404.
     *
     * Both clauses are load-bearing — see the class docblock. `firstOrFail()`
     * rather than a hand-rolled 403 for the same reason.
     */
    private function ownCommitment(Request $request, string $uuid): DonationSubscription
    {
        /** @var Contact $contact */
        $contact = $request->user();

        return DonationSubscription::query()
            ->with('fund')
            ->withCount('donations')
            ->where('uuid', $uuid)
            ->where('contact_id', $contact->id)
            ->firstOrFail();
    }

    /**
     * Re-read the commitment the way `ownCommitment` read it.
     *
     * NOT `$commitment->refresh()`, twice over: `refresh()` drops the
     * `withCount` aggregate, so every mutating verb would answer with
     * `gifts_count: 0` and tell a donor of three years that they had never
     * given — and it re-reads through `newQueryWithoutScopes()`, which is the
     * tenant scope's documented bypass and has no business on a donor route.
     */
    private function reload(DonationSubscription $commitment): DonationSubscription
    {
        return DonationSubscription::query()
            ->with('fund')
            ->withCount('donations')
            ->where('id', $commitment->getKey())
            ->firstOrFail();
    }

    /**
     * The refusals that are true before any Stripe call, answered as a sentence
     * the donor can read.
     *
     * Asked BEFORE the outbound call on purpose: a commitment that cannot be
     * acted on should cost no API call and produce no Stripe error to interpret.
     */
    private function refusalFor(DonationSubscription $commitment): ?JsonResponse
    {
        if ($commitment->status === 'canceled') {
            return $this->refused('This monthly gift has already been cancelled. Start a new one to give again.');
        }

        // 'pending' with no Stripe subscription: checkout was opened and never
        // completed, so there is no billing clock to pause or reprice yet.
        if (! $commitment->stripe_subscription_id) {
            return $this->refused('This monthly gift is not set up yet. If you have just started it, try again in a moment.');
        }

        return null;
    }

    /**
     * The sentence every verb whose Stripe call failed answers with.
     *
     * It is true for all four, and it is only true because of how the verbs are
     * built: pause and resume write nothing locally at all, an amount change
     * writes only after Stripe has accepted it, and the donor's cancel writes
     * only once Stripe agrees the subscription has stopped. A verb that wrote
     * before or regardless of Stripe could not be answered with this sentence,
     * and that — not the wording — is what would have to change.
     */
    private const NOTHING_CHANGED = 'We could not reach the payment processor, so nothing has changed. Please try again in a few minutes.';

    /**
     * Cancel says the same thing in the words that matter for a cancel.
     *
     * "Nothing has changed" is technically true and practically useless here: a
     * donor who asked for their giving to stop needs to be told, in the one
     * sentence they will read, that it has NOT stopped and the next charge is
     * still coming. The failure a donor must never be able to walk away from is
     * this one.
     */
    private const CANCEL_FAILED = 'We could not reach the payment processor, so your monthly gift has NOT been cancelled and the next charge will still go through. Please try again in a few minutes, or contact the organisation.';

    /**
     * Stripe accepted an amount change our own database then refused to record.
     * Neither 200 nor "nothing has changed" can say that, so it has its own
     * sentence, its own status code and a CRITICAL log — see
     * RecurringAmountChangedOnlyAtStripe.
     */
    private const AMOUNT_UNRECORDED = 'Your new amount was accepted by the payment processor and will apply from your next charge, but we could not update your record here. Please contact the organisation so they can correct it — do not change the amount again.';

    /**
     * Run one outbound verb and answer with the commitment as it now stands.
     *
     * @param  string  $failure  the sentence a Stripe refusal is answered with;
     *                           defaults to NOTHING_CHANGED, which every verb
     *                           routed through here is built to keep true.
     * @param  ?bool  $pausedAfter  the pause state when it is known without
     *                              asking Stripe again (cancel), rather than
     *                              read from the verb's own answer.
     *
     * A 503 rather than a 500 because the donor's correct response is to try
     * again — and because a stack trace about a payment processor is not an
     * answer about their money.
     */
    private function throughStripe(
        DonationSubscription $commitment,
        callable $verb,
        string $what,
        string $failure = self::NOTHING_CHANGED,
        ?bool $pausedAfter = null
    ): JsonResponse {
        try {
            $result = $verb();
        } catch (RecurringAmountChangedOnlyAtStripe) {
            // NOT the 503 below, and deliberately not a 200 either. Stripe did
            // change; we did not. The service has already logged this at
            // critical with everything needed to reconcile the row — all this
            // door owes the donor is a sentence that does not contradict their
            // next statement.
            return response()->json([
                'status' => 'failed',
                'data' => self::AMOUNT_UNRECORDED,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            Log::warning('Donor self-service verb failed at Stripe; nothing was changed.', [
                'verb' => $what,
                'subscription_id' => $commitment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'failed',
                'data' => $failure,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // pause/resume answer with what Stripe reported; the amount change
        // answers with the row it just rewrote from Stripe's echo; cancel is
        // told the answer outright, because a cancelled subscription collects
        // nothing and asking Stripe again could only fail.
        $paused = $pausedAfter
            ?? (is_array($result) ? ($result['paused'] ?? null) : $this->donations->pauseStateOf($commitment));

        return $this->ok($this->payload($this->reload($commitment), $paused));
    }

    /**
     * One commitment, as the donor's screen needs it.
     *
     * `paused` is a TRISTATE: true, false, or null for "we could not ask
     * Stripe". There is nowhere local to cache it (DonationService explains
     * why), so a client must render null as unknown and never as "running" — a
     * screen that shows a paused gift as active invites the donor to pause it
     * twice and then wonder why the charges stopped.
     *
     * Amounts stay integer minor units all the way to the client. Formatting is
     * the client's job; a float here is how $50.00 becomes 50.
     */
    private function payload(DonationSubscription $commitment, ?bool $paused): array
    {
        return [
            'uuid' => $commitment->uuid,
            'fund' => [
                'id' => $commitment->fund?->id,
                'name' => $commitment->fund?->name,
            ],
            // What the org receives, and what the card is actually charged.
            // They differ only when the donor is covering processing fees, and
            // the screen has to be able to say so.
            'intended_amount' => (int) $commitment->intended_amount,
            'charged_amount' => (int) $commitment->charged_amount,
            'currency' => $commitment->currency,
            'interval' => $commitment->interval,
            'status' => $commitment->status,
            'paused' => $paused,
            'donor_covers_fees' => (bool) $commitment->donor_covers_fees,
            // Read-only on this surface. Shown so the donor can see how the gift
            // they made was recorded, never so they can change it here.
            'is_zakat' => (bool) $commitment->is_zakat,
            'started_at' => $commitment->created_at?->toIso8601String(),
            'canceled_at' => $commitment->canceled_at?->toIso8601String(),
            'gifts_count' => (int) ($commitment->donations_count ?? 0),
        ];
    }

    /** The mobile realm's legacy envelope — `{status, data}`, no `meta`. */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $data], Response::HTTP_OK);
    }

    /** A refusal the donor can read, in the shape BaseFormRequest's 422 uses. */
    private function refused(string $sentence): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'data' => $sentence,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
