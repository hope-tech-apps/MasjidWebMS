<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Registrations\QuoteRegistrationRequest;
use App\Http\Requests\Api\V1\Registrations\RegisterForOfferingRequest;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Services\Registrations\RegistrationException;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\Errors;
use App\Support\OfferingPublicPayload;
use App\Support\OfferingRegistrationState;
use App\Support\PublicTenant;
use App\Support\RegistrationOutstanding;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The PUBLIC registration endpoints — read, quote, register, checkout (T-006c
 * and T-006g, docs/t006-registration-billing-design.md).
 *
 * These are unauthenticated writes, so they follow the public form-submission
 * idiom (FormSubmissionsController) defensively:
 *
 *  - The organization comes from the `masjid-id` header, MUST STILL EXIST, MUST
 *    HAVE ITS CRM SWITCHED ON, and the offering must belong to it. Nothing here
 *    leans on the BelongsToMasjid
 *    global scope: /api/v1 never runs the tenant middleware, so an UNBOUND scope
 *    adds no filter and a caller could otherwise reach any tenant's rows. Every
 *    lookup filters masjid_id explicitly, and the uuid lookup goes through
 *    Registration::findByUuidForMasjid, which is masjid-filtered by
 *    construction (.claude/rules/registration-billing-data.md).
 *
 *    The existence half was missing until 2026-08-12, and `masjids` SOFT-deletes:
 *    every lookup here matched an offboarded organisation's rows exactly as
 *    happily as a live one's. Measured — offering under masjid A, then
 *    `$masjidA->delete()`: show/quote/register all answered 200 and register
 *    wrote a confirmed row with its form response and bumped the seat counter.
 *    With a PRICED plan the Stripe leg refused one layer further down (the
 *    checkout service resolves the org with `Masjid::find()`, which excludes
 *    trashed rows), so no Session opened and the family got a phantom pending
 *    seat instead — a single guard in the money layer doing work that belonged
 *    at this boundary. Every method here now goes through resolveTenant()
 *    (App\Support\PublicTenant), which is also what the public READ does.
 *
 *    The CRM half was missing until 2026-08-12 as well, with the same shape and
 *    a worse consequence: `masjids.crm_enabled` gates the offering, fee-plan and
 *    registration ADMIN routes, and NO endpoint here consulted it — so with the
 *    flag off this surface went on selling ($150 quotes, live Checkout Sessions,
 *    Contact and Registration rows) for an organisation whose own staff got 403
 *    from every screen that could have shown them. Why the gate belongs here
 *    rather than being lifted off the admin routes is argued in full on
 *    PublicTenant::crmEnabled().
 *  - The same 404 whether an offering does not exist, belongs to another
 *    masjid, is inactive, belongs to an offboarded organisation, or belongs to
 *    one whose CRM is switched off — no probing for which offerings live where,
 *    and no publishing an organisation's account standing to strangers.
 *  - Intake answers are validated by the offering's STORED schema inside
 *    RegistrationService, never from the payload's shape, and undeclared keys
 *    are dropped before storage.
 *  - A honeypot catches naive bots; the named throttles catch the rest.
 *  - Validation failures return the legacy {status:'failed'} 422 field bag via
 *    the BaseFormRequest subclasses (and, for schema failures, the same shape
 *    the form endpoint returns).
 *
 * MONEY: pricing is decided SERVER-SIDE, always — the quote prices from the
 * immutable fee plan, and the charged amount is the registration's own
 * snapshot. A client can name a plan and a code; it can never name a price.
 * `checkout` hands back a Stripe-HOSTED URL and nothing more: the registration
 * stays pending until a signature-verified webhook says otherwise
 * (.claude/rules/stripe-payments.md).
 */
class OfferingRegistrationsController extends Controller
{
    public function __construct(
        private RegistrationService $registrations,
        private RegistrationCheckoutService $checkout,
    ) {
    }

    /**
     * GET /api/v1/offerings/{slug}
     *
     * The PUBLIC FRONT DOOR (T-006g): everything a family needs in order to
     * decide to register, and nothing else. WRITES NOTHING.
     *
     * Until this existed, an organization could be fully configured — offering,
     * fee plans, intake form, connected Stripe account — and still have no way
     * for anyone to reach it: nothing in resources/ called the registration
     * endpoints, so a registration could only be created by a caller who already
     * knew the API.
     *
     * The payload, and the reasoning for every field in and every field out,
     * lives in App\Support\OfferingPublicPayload — the SAME presenter
     * SectionContentBinder uses when an `offering` page section is served, so
     * the standalone page and the published section can never disagree about
     * what is safe to publish.
     *
     * The tenant comes from the masjid-id header and is filtered explicitly, and
     * a foreign / missing / switched-off offering is the same 404: no probing
     * for which offerings live where.
     */
    public function show(Request $request, string $slug)
    {
        try {
            [$masjidId, $refusal] = $this->resolveTenant($request);

            if ($refusal) {
                return $refusal;
            }

            $payload = OfferingPublicPayload::forSlug($masjidId, $slug);

            if (! $payload) {
                return response()->api(404, 'This offering is not available.', null);
            }

            return response()->api(200, 'Offering loaded.', $payload);
        } catch (\Throwable $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * POST /api/v1/offerings/{slug}/quote
     *
     * A server-priced preview OF A REGISTRATION THIS OFFERING WOULD ACCEPT.
     * WRITES NOTHING — no registration, no form response, no seat.
     *
     * ## It used to price anything with an id
     *
     * Until 2026-08-12 this method resolved the offering through
     * `findOffering()` (`is_active` only) and went straight to the price,
     * consulting neither the window, nor the intake form, nor capacity, nor the
     * `registration_state` the page had just rendered from. Measured:
     *
     *   window closed a month ago   page `closed`             quote 200,
     *                                                         amount_due_minor 15000,
     *                                                         requires_payment true
     *   intake form soft-deleted    page `closed/no_intake_form`
     *                                                         quote 200 for $150,
     *                                                         register 422
     *
     * A renderer that prices before it reads state quotes a parent $150 for a
     * program their child cannot join, and the refusal arrives after the form is
     * filled in — if it arrives at all.
     *
     * ## The verdict comes from the decider, not from a third opinion
     *
     * `App\Support\OfferingRegistrationState::for()` is the SAME call
     * `OfferingPublicPayload` renders `registration_state` from, so the page and
     * the quote cannot disagree — that class exists because this question had
     * four disagreeing implementations once already.
     *
     * Three deliberate details:
     *
     *  - `waitlist` is NOT a refusal. `register()` QUEUES a sign-up for a full
     *    offering rather than turning it away, so a family joining a waitlist is
     *    entitled to know what the place will cost.
     *  - The fee plan is resolved FIRST, so an unrecognised `fee_plan_id` keeps
     *    its own 404 ("This fee plan is not available.") — which is exactly what
     *    the write path answers for the `no_fee_plan` clause, and what
     *    OfferingRegistrationStateTest pins as the parent-facing half of that
     *    state. The state check then covers the clauses no plan lookup can see.
     *  - A PREVIEW IS NEVER STRICTER THAN THE WRITE IT PREVIEWS. For a quote
     *    that names no registration, the write it previews is `register()`, and
     *    both are decided by the same two calls in the same order:
     *    `findFeePlan()` (the per-plan predicate) and then the offering-level
     *    verdict. Every clause of that verdict which is not a plan fact — the
     *    window, the intake form — is one `register()` refuses on too.
     *
     * ## A quote that names a REGISTRATION previews `checkout()`, not `register()`
     *
     * That sentence went false on 2026-08-13 and this branch is what makes it
     * true again. `checkout()` deliberately re-checks the clauses about the
     * PROGRAM and NONE of the clauses about a NEW registration: not which plans
     * are purchasable (this row already chose its plan and is charged from its
     * own `adjusted_total_minor`), and not the opens_at/closes_at window (that
     * is the deadline for SIGNING UP, and she signed up before it). Meanwhile
     * this method resolved the plan through `findFeePlan()` — which gates on
     * `isPurchasable()`, including `is_active` — BEFORE it ever looked at the
     * uuid, and then applied the offering-level verdict on top. Measured on one
     * fixture at one instant:
     *
     *   CHECKOUT  registration held on the deactivated plan -> 200, charged 15000
     *   QUOTE     same registration, its OWN fee_plan_id    -> 404 "This fee plan
     *                                                          is not available."
     *   QUOTE     same registration, the NEW plan's id      -> 200, fee_plan_id=2,
     *                                                          kind=recurring,
     *                                                          amount_due=15000
     *
     * So the only plan id the quote would accept for an in-flight registration
     * was one that is NOT its plan, and naming it reported that plan's identity
     * beside this registration's snapshot — `recurring` for a one-time $150
     * sign-up. Before round two removed `is_active` from the checkout side, both
     * surfaces refused and therefore agreed; removing it from one of them left
     * the preview strictly stricter than the write.
     *
     * A registration therefore answers from ITSELF: its own plan (resolved by
     * OWNERSHIP, because purchasability is a question about new intake), its own
     * snapshot, and no offering-level verdict at all. That is looser than
     * `checkout()`, which is the only safe direction for a preview — it writes
     * nothing, and every refusal still lands at the door where money moves. The
     * uuid is masjid-filtered by construction, so this discloses one number to
     * somebody who already holds the handle to that registration.
     *
     * A client may still send a `fee_plan_id` alongside; the registration's own
     * plan wins. Pricing is server-side, always — a client can name a plan, it
     * can never name a price, and it can never restate which plan a registration
     * is on.
     *
     * ## "Looser than checkout" is only safe while it stays a PRICE, not a PROMISE
     *
     * The sentence above — a preview may be looser, never stricter — was written
     * about what this endpoint REFUSES, and it is still right about that. It was
     * then read as licence for what this endpoint ASSERTS, and that is where it
     * broke on 2026-08-13. In the same pass that removed the offering-level
     * verdict from this branch, `checkout()` gained two program-level refusals
     * (the intake form must still resolve; the organisation must still be able
     * to collect). Neither pass looked at the other, and in the two cells their
     * intersection creates, a live hold was quoted `amount_due_minor: 15000,
     * requires_payment: true` against a checkout that answered 422.
     *
     * Being looser cost nothing while the extra 200s were only ever a PRICE for
     * a program somebody had already joined. It costs a family her seat the
     * moment one of them is also a promise that a payment can be completed. So
     * the refusals stay off this branch — she is still told what her place cost,
     * after the window shuts and after her plan is superseded, which is the
     * whole reason the verdict came off — and the promise, `requires_payment`,
     * is answered by asking `checkout()` itself. See the
     * `canOpenCheckout()` call below.
     *
     *    This paragraph used to say `for()` deliberately did NOT ask whether the
     *    organisation could collect, because `register()` did not refuse on it.
     *    Both halves were the defect rather than the reasoning: the page DID ask
     *    (through `decide()`), so the page said `closed / org_cannot_collect`
     *    while this method answered 200 with `amount_due_minor: 15000` and
     *    `register` answered 200 "Your place is held while you complete
     *    payment." with `checkout_url: null`. The question is now asked once,
     *    per PLAN, inside `isPurchasable()` — so it reaches the page, this
     *    quote and `register` through the same door, and the free tier of an
     *    un-onboarded organisation goes on being quoted and registered.
     */
    public function quote(QuoteRegistrationRequest $request, string $slug)
    {
        try {
            [$masjidId, $refusal] = $this->resolveTenant($request);

            if ($refusal) {
                return $refusal;
            }

            $offering = $this->findOffering($masjidId, $slug);

            if (! $offering) {
                return response()->api(404, 'This offering is not available.', null);
            }

            // Resolved FIRST, because a registration changes which question is
            // being asked. Masjid-filtered by construction, and required to be
            // OF THIS OFFERING — another tenant's uuid, or one belonging to a
            // different program, simply does not resolve and the quote falls
            // through to the list-price branch below.
            $registration = $this->quotedRegistration($masjidId, $offering, $request->input('registration_uuid'));

            // The default for the list-price branch, where no registration
            // exists: there is no Stripe door to be open or shut yet, so "can
            // she pay now" is the same question as "is a payment expected",
            // answered below. (`$amountPaid` deliberately does NOT have a
            // default out here — it is meaningless without a registration, and a
            // zero sitting in scope is how a later edit reads a ledger figure
            // for a row that has no ledger.)
            $canPayNow = null;

            if ($registration) {
                // Its OWN plan, by ownership only. Purchasability gates NEW
                // intake; re-deciding it here would be re-pricing a row that is
                // charged from its snapshot and already holds its seat.
                $feePlan = $this->ownedFeePlan($masjidId, $offering, (int) $registration->fee_plan_id);

                if (! $feePlan) {
                    // The FK is RESTRICT and fee plans do not soft-delete, so
                    // this is unreachable through the schema; answered as a
                    // plain miss rather than as an internal invariant.
                    return response()->api(404, 'This fee plan is not available.', null);
                }

                $listTotal = (int) $registration->list_total_minor;
                $adjustedTotal = (int) $registration->adjusted_total_minor;

                // WHETHER STRIPE IS STILL EXPECTED and WHAT IS STILL OWED, both
                // from App\Support\RegistrationOutstanding — THE statement of
                // those two questions, shared verbatim with `register()` and
                // `checkout()`, which published a different number under the
                // same field name until this round. The whole argument, and the
                // measurement of what the three doors used to disagree about,
                // is on that class.
                $requiresPayment = RegistrationOutstanding::requiresPayment($registration);

                // ...AND, FOR A SEAT STILL HOLDING, WHETHER THE DOOR SHE WOULD
                // PAY THROUGH IS ACTUALLY OPEN.
                //
                // A PENDING registration has exactly one way to pay: POST
                // /registrations/{uuid}/checkout. So on that row, and only that
                // row, `requires_payment: true` is a direct promise that the
                // checkout endpoint will hand back a link — and the removal of
                // the offering-level verdict from this branch (correct: see the
                // method docblock) met two program-level refusals ADDED to
                // `checkout()` in the same pass, in two cells nobody checked
                // against each other. Measured, same fixture, same instant, a
                // live 30-minute hold on a $150 seat:
                //
                //   intake form soft-deleted     page  closed / no_intake_form
                //                                quote 200, 15000, TRUE
                //                                checkout 422 offeringClosed
                //   stripe_charges_enabled false page  closed / org_cannot_collect
                //                                quote 200, 15000, TRUE
                //                                checkout 422 orgCannotCollect
                //
                // She opens the payment link, the page renders "Amount due:
                // $150.00 — Pay now", she clicks, she gets a 422, and her seat
                // sits held until the reaper takes it.
                //
                // Asked by RUNNING checkout's own refusal ladder
                // (`RegistrationCheckoutService::canOpenCheckout`), never by
                // restating its clauses here — a second copy of that ladder is
                // the defect, not the fix, and it would drift on the next round
                // exactly as this pair did. Nothing is written: the predicate is
                // the preflight half of `checkout()`, which persists nothing.
                //
                // Deliberately NOT applied to a non-pending row. `checkout()`
                // refuses those with `notCheckoutable`, but for a CONFIRMED
                // subscription (`active`, `past_due`) Stripe genuinely is still
                // expected — it bills on its own clock and this endpoint is not
                // the door — so mirroring the refusal there would report "no
                // payment expected" to a family whose next invoice is real.
                // WHETHER SHE CAN PAY RIGHT NOW IS NOT WHETHER SHE OWES.
                //
                // This used to overwrite `$requiresPayment`, which made the
                // read surface tell the opposite lie from the one it was fixing.
                // Measured on one fixture, one instant:
                //
                //   live hold  quote 200  adjusted 15000  due 15000  requires TRUE
                //   +31 min    status=pending pay=awaiting, SEAT STILL HELD
                //              quote 200  adjusted 15000  due 0  requires FALSE
                //              checkout 422 "The payment window … has closed"
                //
                // A lapsed thirty-minute hold is the single most common state on
                // this surface — every abandoned checkout between T+30min and
                // the reaper — and she was told she owed nothing while her seat
                // was still held and $150 was still outstanding.
                //
                // So the two questions get two fields. `requires_payment` and
                // `amount_due_minor` answer "what is outstanding", which does
                // not change because a window lapsed. `can_pay_now` answers "is
                // the Stripe door open", asked by RUNNING checkout's own
                // refusal ladder rather than by restating its clauses here.
                if ($registration->status === Registration::STATUS_PENDING) {
                    $canPayNow = $requiresPayment && $this->checkout->canOpenCheckout($registration);
                }

                $amountDue = RegistrationOutstanding::minor($registration, $feePlan);
            } else {
                // A UUID WAS NAMED AND DID NOT RESOLVE, and nothing else on the
                // request names a price. `fee_plan_id` became
                // `required_without:registration_uuid` in the same pass that
                // taught this endpoint about registrations, so this request now
                // validates and falls through to the list-price branch with
                // `(int) null = 0` — a plan id that cannot exist. Measured:
                //
                //   POST .../quote {"registration_uuid":"not-a-real-uuid"}
                //     -> 404 "This fee plan is not available."
                //
                // A parent whose payment link is stale, mistyped, or for a
                // different program is told something about fee plans, a noun
                // she was never shown and cannot act on. The sibling endpoint
                // already has the right words for this exact miss, so they are
                // reused verbatim (`checkout`: "This registration is not
                // available.").
                //
                // NO NEW DISCRIMINATION. Every non-resolving uuid — another
                // tenant's, another program's, a typo, one that never existed —
                // gets this one answer, exactly as they all got the fee-plan one
                // before; a resolving uuid already answered 200 and still does.
                // The words changed, not what a caller can learn.
                //
                // A uuid alongside an explicit `fee_plan_id` deliberately still
                // falls through and prices that plan's list price: there IS
                // something to answer then, which is what a page showing "here
                // is what it costs" asks for.
                if (filled($request->input('registration_uuid'))
                    && blank($request->input('fee_plan_id'))) {
                    return response()->api(404, 'This registration is not available.', null);
                }

                $feePlan = $this->findFeePlan($masjidId, $offering, (int) $request->input('fee_plan_id'));

                if (! $feePlan) {
                    return response()->api(404, 'This fee plan is not available.', null);
                }

                // The acceptance check the price used to skip. One decider, one
                // answer: whatever the page reports as `closed`, this refuses in
                // the write path's own words — and it refuses BEFORE quoting a
                // number, because a price for a program nobody can join is the
                // misleading half of the pair.
                //
                // NOT applied to the branch above: every clause of this verdict
                // is about accepting a NEW registration, and `checkout()` — the
                // write an existing registration's quote previews — re-checks
                // none of them that are not also properties of the program.
                if (OfferingRegistrationState::for($offering)['state'] === OfferingRegistrationState::STATE_CLOSED) {
                    throw RegistrationException::offeringClosed();
                }

                $listTotal = $this->registrations->listTotalFor($feePlan);
                $adjustedTotal = $listTotal;
                // No registration exists yet, so "is there a Stripe leg" IS
                // "is the total above zero" — the free-path carve-out — and it
                // is also the answer to "could she pay", there being nothing yet
                // that a window or an offboarding could have shut.
                $requiresPayment = $adjustedTotal > 0;
                $canPayNow = $requiresPayment;
                // Nothing has been collected against a registration that does
                // not exist, so the whole list price is what signing up costs —
                // for a recurring plan that list price is ONE INTERVAL, which is
                // exactly the first charge. Same number the branch above reaches
                // through `perChargeMinor()`.
                $amountDue = $requiresPayment ? $adjustedTotal : 0;
            }

            return response()->api(200, 'Quote generated.', [
                'offering' => [
                    'slug' => $offering->slug,
                    'name' => $offering->name,
                    'kind' => $offering->kind(),
                ],
                'fee_plan_id' => $feePlan->id,
                'fee_plan_kind' => $feePlan->kind,
                'currency' => $feePlan->currency,
                // Integer minor units throughout — never a float.
                'list_total_minor' => $listTotal,
                // WHAT THE PLACE COST: the plan's list price for a preview, the
                // registration's own snapshot for an existing row. Never
                // restated — a settled registration still cost what it cost, and
                // this is the field that says so.
                'adjusted_total_minor' => $adjustedTotal,
                // WHAT IS OWED, which is a different question and now gets a
                // different answer. This was a second copy of
                // `adjusted_total_minor`, under a name that says otherwise, and
                // it was measured lying in three shapes at once: a settled
                // registration quoted `amount_due_minor 15000` on money already
                // paid, a cancelled one the same on a seat that no longer
                // exists, and an aid-floored installment quoted
                // `amount_due_minor: 5` — the 5¢ the docblock above already
                // calls out as a number nobody would ever ask her for, still on
                // the wire under the field named *amount due*.
                //
                // `requires_payment` was made to carry the whole truth and the
                // amount was left carrying none of it, so every renderer that
                // printed the amount without first switching on the boolean told
                // a paid family she still owed $150. Costing information is not
                // lost by fixing this: it is `adjusted_total_minor`, one line
                // up, which is where it belonged all along.
                // …and it is what is OUTSTANDING, not the commitment. Measured
                // on a 9 × $100.00 installment plan before that: `amount_due_minor
                // 90000` on a Session that charges 10000, and still 90000 after
                // three of the nine had been paid, because nothing subtracted
                // the ledger. A parent on a payment plan was told she owed $900
                // six months and $600 in.
                //
                // See App\Support\RegistrationOutstanding for what the field
                // means per plan kind, for the two ways "a balance" was the
                // wrong word for it, and for why all three endpoints that
                // publish this name now compute it there.
                'amount_due_minor' => $amountDue,
                // Whether money is still owed. False is the free-path carve-out
                // (confirmed in-request, never a $0 Session) and, for an
                // existing registration, also a settled or cancelled one.
                // NOT affected by whether the Stripe door happens to be open —
                // that is the next field, and conflating them told a lapsed hold
                // she owed nothing while her seat was still held.
                'requires_payment' => $requiresPayment,
                // Whether checkout would open RIGHT NOW, asked by running
                // checkout's own refusal ladder. Null when there is no
                // registration to open one for (a list-price preview), where the
                // question is not yet meaningful.
                'can_pay_now' => $canPayNow,
                // Codes are validated server-side; nothing a client sends can
                // reduce a price by itself. Aid is granted by an admin.
                'code_applied' => false,
            ]);
            // NO `amount_paid_minor`. It was added here on 2026-08-14 so a
            // renderer could show "$300 of $900" without arithmetic of its own,
            // and the docblock defending the change never asked WHO MAY READ IT.
            // This endpoint takes no Authorization header and no cookie: a uuid
            // and the `masjid-id` header are the entire credential, and the uuid
            // is a bearer token that lives in payment-link URLs, forwarded
            // emails, browser history and referrer headers.
            //
            // HOW BIG A REDUCTION THIS ACTUALLY IS, measured rather than
            // asserted — the first draft of this comment claimed `paid` is
            // always `adjusted − due` for a finite plan, and that is FALSE once
            // aid leaves a rounding remainder:
            //
            //   9 × $100.00, no aid, 3 settled   adjusted 90000 due 60000
            //                                    adjusted−due 30000 = paid  ✔
            //   9 × $100.00, 10¢ aid, 0 settled  adjusted 89990 due 89982
            //                                    adjusted−due 8    ≠ paid 0 ✘
            //   9 × $100.00, 10¢ aid, 4 settled  adjusted 89990 due 49990
            //                                    adjusted−due 40000 ≠ 39992  ✘
            //
            // because `amount_due_minor` is now `per-charge × N − paid` and the
            // payload publishes neither the per-charge amount nor N. So in the
            // ORDINARY case (no aid, or aid that divides evenly) a link-bearer
            // could already compute the history and still can; on an
            // aid-adjusted plan, and on an open-ended subscription — where
            // `amount_due_minor` is one interval rather than a balance — this
            // genuinely removes a number nothing else carries. That number is
            // the one with the longest memory: how many months this family has
            // been paying, or has not. Nothing in this repository renders it.
            //
            // So the anonymous surface answers what the place cost, what is
            // outstanding, whether payment is expected and whether the door is
            // open. A payment HISTORY belongs on the authenticated family stack
            // (routes/family.php), which knows who is asking — and note that
            // stack serves no registration endpoints today, so this is a
            // removal rather than a relocation. Building a parent-facing
            // "you have paid $X of $Y" is deliberate future work behind a
            // credential, not a reason to keep volunteering it to a link.
            //
            // The aid gap this payload still discloses — `list_total_minor`
            // beside `adjusted_total_minor` on a registration-scoped quote —
            // predates that change and is deliberately left alone this round:
            // it is her own receipt, a renderer showing "financial aid applied"
            // needs both halves, and narrowing it is a decision about the whole
            // payment-link model rather than about one field. It is written down
            // in .claude/rules/registration-billing-data.md instead of being
            // re-engineered quietly here.
        } catch (RegistrationException $e) {
            return response()->api(422, $e->getMessage(), null);
        } catch (\Throwable $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * POST /api/v1/offerings/{slug}/register
     *
     * One transaction inside RegistrationService: schema validation → form
     * response → capacity lock → registration with snapshot totals → seat
     * reserved (or waitlisted), free totals confirmed synchronously. A PAID
     * registration then gets its hosted Checkout URL.
     */
    public function register(RegisterForOfferingRequest $request, string $slug)
    {
        try {
            [$masjidId, $refusal] = $this->resolveTenant($request);

            if ($refusal) {
                return $refusal;
            }

            $offering = $this->findOffering($masjidId, $slug);

            if (! $offering) {
                return response()->api(404, 'This offering is not available.', null);
            }

            // A bot filling every input trips this; a human never sees the
            // field. Report success so a scripted submitter gets no signal to
            // adapt to, while nothing is written.
            if (filled($request->input('website'))) {
                return response()->api(200, 'Thank you — your registration has been received.', [
                    'registration_uuid' => null,
                ]);
            }

            $feePlan = $this->findFeePlan($masjidId, $offering, (int) $request->input('fee_plan_id'));

            if (! $feePlan) {
                return response()->api(404, 'This fee plan is not available.', null);
            }

            // Contacts-first (.claude/rules/groups.md): people are resolved to
            // Contacts BEFORE the service runs — RegistrationService creates no
            // Contact rows of its own.
            // TWO RESOLVERS, NOT ONE, AND THEY ASK DIFFERENT QUESTIONS. A payer
            // asserts their OWN address, so they are found by it. A registrant is
            // a claim ABOUT somebody, and a child's identity is not an address —
            // most children have none and the siblings who do share the household
            // mailbox — so a registrant is found by NAME WITHIN THIS PAYER'S
            // HOUSEHOLD. See resolveRegistrantContact() for the whole argument.
            $submittedPayerEmail = $this->normaliseEmail($request->input('payer.email'));
            $submittedPayerName = $this->normaliseName($request->input('payer.name'));

            $payer = $this->resolvePayerContact($masjidId, [
                'name' => $request->input('payer.name'),
                'email' => $request->input('payer.email'),
                'phone' => $request->input('payer.phone'),
            ]);

            $registrants = [];

            foreach ((array) $request->input('registrants', []) as $person) {
                if (! is_array($person)) {
                    continue;
                }

                $registrants[] = $this->resolveRegistrantContact(
                    $masjidId,
                    $person,
                    $payer,
                    $submittedPayerName,
                    $submittedPayerEmail,
                );
            }

            $registration = $this->registrations->register(
                $offering,
                $feePlan,
                $payer,
                (array) $request->input('data', []),
                $registrants
            );

            $checkoutUrl = null;

            // A paid, seat-holding registration gets its hosted session now.
            // A failure here is NOT fatal: the seat is committed and bounded by
            // checkout_expires_at, and the client can re-mint through the
            // checkout endpoint — losing Stripe for a moment must not lose the
            // registration.
            if ($registration->isPending() && (int) $registration->adjusted_total_minor > 0) {
                try {
                    $checkoutUrl = $this->checkout->checkout($registration)['checkout_url'];
                } catch (\Throwable $e) {
                    $checkoutUrl = null;
                }
            }

            return response()->api(200, $this->outcomeMessage($registration), [
                'registration_uuid' => $registration->uuid,
                'status' => $registration->status,
                'payment_status' => $registration->payment_status,
                'currency' => $feePlan->currency,
                // WHAT THE PLACE COST — the snapshot, never restated. Added
                // here this round because the field below stopped being a
                // second copy of it, and a "thank you" screen needs the number
                // she just agreed to.
                'adjusted_total_minor' => (int) $registration->adjusted_total_minor,
                // WHAT IS STILL OWED. This WAS `(int) $adjusted_total_minor`
                // under a name that says otherwise — the same defect round six
                // fixed on `quote()` and did not carry to the two doors money
                // actually moves through. Measured on a FULL offering: this
                // endpoint answered "you have been added to the waitlist" and
                // `amount_due_minor: 15000` in the same 200, for a row holding
                // no seat and no payment leg, while `quote()` on that very uuid
                // answered 0. One name, one meaning, one implementation —
                // App\Support\RegistrationOutstanding.
                'amount_due_minor' => RegistrationOutstanding::minor($registration, $feePlan),
                'checkout_url' => $checkoutUrl,
            ]);
        } catch (ValidationException $e) {
            // The intake answers failed the offering's own schema. Field bag
            // under `data`, matching how the SPA and the public site already
            // read 422s from BaseFormRequest.
            return response()->json([
                'status' => 'failed',
                'data' => $e->errors(),
            ], 422);
        } catch (RegistrationException $e) {
            return response()->api(422, $e->getMessage(), null);
        } catch (\Throwable $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * POST /api/v1/registrations/{uuid}/checkout
     *
     * Re-mint the hosted Checkout Session for a registration whose first one
     * was abandoned (or never reached the client). Idempotency-keyed: the first
     * mint reuses the key minted at intake so a double-submit cannot open two
     * sessions; a genuine re-mint rotates it.
     *
     * Returns a URL and NOTHING else — no state is advanced here, ever.
     */
    public function checkout(Request $request, string $uuid)
    {
        try {
            [$masjidId, $refusal] = $this->resolveTenant($request, 'This registration is not available.');

            if ($refusal) {
                return $refusal;
            }

            // Masjid-filtered by construction: another tenant's uuid is a miss,
            // never a hit, so a registration can never be checked out against
            // another organization's connected account.
            $registration = Registration::findByUuidForMasjid($uuid, $masjidId);

            if (! $registration) {
                return response()->api(404, 'This registration is not available.', null);
            }

            $result = $this->checkout->checkout($registration);

            return response()->api(200, 'Checkout session created.', [
                'registration_uuid' => $registration->uuid,
                'status' => $registration->status,
                'payment_status' => $registration->payment_status,
                // Both numbers, same meanings as everywhere else on this API.
                'adjusted_total_minor' => (int) $registration->adjusted_total_minor,
                // Measured before this: a 9 × $100.00 plan with 10¢ of aid
                // granted while pending opened a Stripe subscription billing
                // 9998 nine times (89982) and answered `amount_due_minor:
                // 89990` — 8¢ more than the session it had just minted, and 8¢
                // more than the quote rendered beside it said. THE DOOR MONEY
                // MOVES THROUGH MUST NOT NAME A DIFFERENT NUMBER FROM THE ONE
                // STRIPE IS ASKED FOR.
                'amount_due_minor' => RegistrationOutstanding::minor($registration),
                'checkout_url' => $result['checkout_url'],
            ]);
        } catch (RegistrationException $e) {
            return response()->api(422, $e->getMessage(), null);
        } catch (\Throwable $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    // ----------------------------------------------------------- resolution

    /**
     * The organization this request is for — named by the header AND verified to
     * still exist AND to have the CRM this whole feature lives inside switched
     * on.
     *
     * Returns `[$masjidId, null]` on success and `[0, $response]` on refusal;
     * exactly one of the two is meaningful. Returned rather than thrown because
     * every method here wraps its body in a blanket `catch (\Throwable)` that
     * would swallow an HttpResponseException and reissue it as a 500.
     *
     * TWO DIFFERENT REFUSALS, on purpose and matching the contract
     * ZakatCalculatorController and ContactUsController already give:
     *
     *  - 400 when NO organisation is named. `(int)` makes a missing header, an
     *    empty one and `masjid-id: 0` all 0, and the falsy-bypass spelling is
     *    exactly how the 2026-08-11 SearchableTrait leak served 14 rows across
     *    two tenants — so it is refused, never answered.
     *  - 404 when the organisation named is not a live one WITH ITS CRM ON, in
     *    the SAME words as a missing offering. A caller cannot tell "no such
     *    organisation" from "no such offering" from "that organisation has no
     *    registration feature", so this is neither a way to enumerate tenant ids
     *    nor a way to read an organisation's account standing.
     *
     * The existence check is the whole point: hand-filtering `masjid_id` proves
     * a row BELONGS to the id, never that the id still names anybody
     * (App\Support\PublicTenant). `crmEnabled()` asks that and one thing more —
     * whether the organisation actually HAS this feature — and it subsumes the
     * existence check, so this is still one query and one refusal.
     *
     * @return array{0:int, 1:mixed}
     */
    private function resolveTenant(Request $request, string $missingMessage = 'This offering is not available.'): array
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return [0, response()->api(400, 'A masjid must be specified.', null)];
        }

        if (! PublicTenant::crmEnabled($masjidId)) {
            return [0, response()->api(404, $missingMessage, null)];
        }

        return [$masjidId, null];
    }

    /**
     * The offering for this tenant, or null. Explicit masjid filter (the scope
     * is unbound here) and active-only, so a closed or foreign offering is the
     * same miss. The tenant itself has already been verified live by
     * resolveTenant() — this filter proves ownership, not existence.
     */
    private function findOffering(int $masjidId, string $slug): ?Offering
    {
        return Offering::query()
            ->where('masjid_id', $masjidId)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * A PURCHASABLE plan belonging to THIS offering and tenant, or null.
     *
     * Ownership is a query (masjid + offering + key, explicit because /api/v1
     * runs unbound); purchasability is `OfferingRegistrationState::isPurchasable`
     * and NOTHING else — the identical call `OfferingPublicPayload::feePlans()`
     * publishes on and the offering-level verdict is aggregated from. Restating
     * its clauses as `where` conditions here is what produced the whole class of
     * defect this method keeps being fixed for: the SQL and the predicate drift
     * a clause apart and the page and the write path answer differently.
     *
     * `whereIn('kind', FeePlan::KINDS)` was the missing clause until 2026-08-12,
     * and OfferingRegistrationState's docblock asserted it was here while it was
     * not. Measured with a plan whose `kind` is `sliding_scale`: the page
     * withheld it (`fee_plans: []`, `registration_state: closed / no_fee_plan`)
     * but `POST /quote` naming its id got as far as
     * `RegistrationService::listTotalFor()` and answered 422 "Unrecognized fee
     * plan kind 'sliding_scale' — money kinds never degrade." — an internal
     * invariant message, written for a developer reading a stack trace,
     * delivered to an anonymous caller, and a positive confirmation that a plan
     * row with that id and that kind exists. A NONEXISTENT id, meanwhile,
     * correctly got 404 "This fee plan is not available."
     *
     * Two more clauses joined the predicate on the same evidence, and both close
     * the same hole from the other end — a seat taken for a plan that could
     * never be charged:
     *
     *   billing_interval NULL / 'fortnight'   page open, plan published at 2500,
     *   installment_count 0                   quote 200, register 200 "your
     *                                         place is held", checkout 422, seat 1
     *
     *   org with stripe_account_id = null     page closed/org_cannot_collect,
     *                                         quote 200 for 15000, register 200
     *                                         with checkout_url null, checkout 422
     *
     * Withholding a plan and refusing it are one fact: everything the page does
     * not publish is the SAME 404 as a plan that never existed, so a public
     * caller cannot tell an unpublished plan from a missing one, and the "money
     * kinds never degrade" invariant fails where it belongs — loudly, in the
     * logs, on the path an admin created the bad row from — instead of in a
     * family's browser.
     */
    private function findFeePlan(int $masjidId, Offering $offering, int $feePlanId): ?FeePlan
    {
        $plan = $this->ownedFeePlan($masjidId, $offering, $feePlanId);

        if (! $plan) {
            return null;
        }

        return OfferingRegistrationState::isPurchasable($plan, $this->organisationCanCollect($masjidId))
            ? $plan
            : null;
    }

    /**
     * The OWNERSHIP half of the lookup above, on its own: a plan belonging to
     * this tenant and this offering, whether or not it is still on sale.
     *
     * Used only where the plan is already the answer rather than a choice —
     * quoting a registration that is HELD on it. `is_active` means "not offered
     * to NEW registrants" (deactivate-and-replace is the only way to change a
     * price), so asking it about a plan somebody already registered on is the
     * question that made the preview stricter than the write.
     */
    private function ownedFeePlan(int $masjidId, Offering $offering, int $feePlanId): ?FeePlan
    {
        if ($feePlanId <= 0) {
            return null;
        }

        return FeePlan::query()
            ->where('masjid_id', $masjidId)
            ->where('offering_id', $offering->id)
            ->whereKey($feePlanId)
            ->first();
    }

    /**
     * The registration a quote is ABOUT, or null when it is a list-price
     * preview.
     *
     * `Registration::findByUuidForMasjid` is masjid-filtered by construction
     * (.claude/rules/registration-billing-data.md — never resolve a registration
     * from a client uuid alone, because the tenant scope adds no filter when
     * unbound), and the offering is re-asserted on top: a uuid from another
     * program of the same organisation prices that program's snapshot onto this
     * page otherwise.
     */
    private function quotedRegistration(int $masjidId, Offering $offering, $uuid): ?Registration
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $registration = Registration::findByUuidForMasjid($uuid, $masjidId);

        return $registration && (int) $registration->offering_id === (int) $offering->id
            ? $registration
            : null;
    }

    /**
     * Whether this organisation has finished Connect onboarding — the one fact
     * `isPurchasable()` cannot read off a plan row.
     *
     * `resolveTenant()` has already proved the organisation exists and is live,
     * so a miss here would be a race, not a state: `false` is the safe reading
     * of it, and it withholds only the CHARGEABLE plans, never the free ones.
     */
    private function organisationCanCollect(int $masjidId): bool
    {
        return (bool) Masjid::find($masjidId)?->canAcceptDonations();
    }

    /**
     * ==========================================================================
     * WHO THIS ANONYMOUS CALLER IS ALLOWED TO SAY THEY ARE
     * ==========================================================================
     *
     * ONE RESOLVER, find-or-create on `(masjid_id, LOWER(email))`, for the payer
     * and for every registrant alike — which is what this was before an earlier
     * round split it, and what it is again.
     *
     * ------------------------------------------------------------------------
     * WHY THE SPLIT IS GONE (it was the right defect on the wrong axis)
     * ------------------------------------------------------------------------
     *
     * The split existed to stop an anonymous POST becoming the guardian of a
     * child it merely named by email. Measured, no token and no session:
     *
     *     POST /api/v1/offerings/grade-5-enrichment/register   masjid-id: 1
     *       { "payer":       {"name":"Bilal Attacker","email":"bilal@example.test"},
     *         "registrants": [{"name":"Salma Other","email":"salma.other@school.test"}], … }
     *     -> 200, and Bilal's own family credential then opened Salma's
     *        behaviour record, her ḥifẓ and every participant thread naming her.
     *
     * That is real, and refusing to MATCH her row does stop that exact request.
     * It stops nothing else, because the thing being defended is not a row — it
     * is the ASSERTION "I am this child's guardian", and an assertion made
     * through a different field is the same assertion. Three doors were found
     * one at a time: the `registrants` array; the `payer` field, which is the
     * same writer one field away and was never guarded; and
     * `RosterMergeService::carry()`, an ordinary staff de-duplication that
     * re-points the manufactured edge onto the real child. There was no reason
     * to believe the enumeration was complete.
     *
     * The assertion is now recorded as an assertion. A roster row written from
     * this endpoint carries `provenance = self_asserted`
     * (`GroupMembership::PROVENANCES`), and a self-asserted row grants NOTHING —
     * not a feed, not a thread, not an award, not a ḥifẓ record, not eligibility
     * for a family credential — until a staff member confirms it. So matching a
     * pre-existing contact is no longer a disclosure decision, and the three
     * defects the split caused go with it:
     *
     *  1. A TEACHER 403'd OUT OF HER OWN CLASSROOM. `GroupAudience` resolves a
     *     staff caller to a Contact by `LOWER(email)` and requires EXACTLY ONE
     *     ("ambiguous identity is no identity"). A resolver that always creates
     *     therefore let ONE anonymous POST naming a teacher's login address fork
     *     the directory and shut her out of the class she teaches — measured,
     *     her feed, threads, awards and ḥifẓ all 200 -> 403, permanently, with
     *     nothing on any screen saying why, while she kept `manage contacts` and
     *     could still publish into the group she could no longer read.
     *  2. A RETURNING CHILD BECAME N PEOPLE ON ONE ROSTER. Measured, three
     *     byte-identical registrations: the payer converged to one row and the
     *     child forked into three, giving a Grade 5 roster six lines and the
     *     parent portal the same child listed twice. `.claude/rules/groups.md`
     *     promises `GroupMembershipsController` refuses duplicate participant
     *     membership — "that check is the guarantee, not the index" — and a
     *     writer that creates a new PERSON walks straight past it.
     *  3. THE MERGE VERB AS THE PRESCRIBED REMEDY. The cost was written down as
     *     "reconciled by the existing merge verb"; the merge is where a
     *     manufactured edge got re-pointed onto the REAL child, and the UI has
     *     no merge affordance on two duplicate children at all.
     *
     * ------------------------------------------------------------------------
     * WHAT AN ADDRESS MAY AND MAY NOT DO HERE
     * ------------------------------------------------------------------------
     *
     * Matching is on the address AND, for anybody who is not the payer, the
     * name. Two rules, and the second is the one that is easy to lose:
     *
     *  - MATCH ON (address, name) -> the existing contact. A returning family
     *    types the same two things it typed last season and attaches to the same
     *    record, which is the whole reason the directory does not grow a row per
     *    season. The PAYER is matched on the address alone, because they are
     *    asserting their OWN address and a parent who writes "Bilal A." in one
     *    box and "Bilal Attacker" in another is the same customer.
     *  - NO MATCH -> a NEW contact, and it NEVER carries an address another
     *    contact in this tenant already holds. `contacts.email` is routinely a
     *    HOUSEHOLD mailbox — two siblings signed up on one address are two
     *    people, and the second one gets a row with a null email, exactly as a
     *    child with no address of their own already did. This is what makes the
     *    teacher's address resolve to exactly one contact NO MATTER WHAT NAME the
     *    caller pairs with it, which is the property (1) above actually needs;
     *    keying on the name alone would leave the fork one wrong name away.
     *
     * A NULLED EMAIL IS NOT A REFUSAL AND NOT AN ORACLE. The endpoint answers
     * the same 200 with the same body whether the address was known or not, it
     * writes a contact either way, and no public response exposes a contact's
     * columns — so it cannot be used to enumerate a school's families one
     * address at a time, which is the objection that argued the split into
     * existence. What the caller loses is the ability to put somebody else's
     * mailbox on a record they authored, which nobody legitimate wants.
     *
     * masjid_id is set/filtered explicitly throughout — /api/v1 runs unbound, so
     * the BelongsToMasjid scope adds no filter and the creating hook stamps
     * nothing. Deliberately local rather than reaching for the donor-CRM
     * resolver: this is not a donation, and its defaults belong to this path.
     *
     * @param  array{name?:?string,email?:?string,phone?:?string}  $person
     */
    private function resolvePayerContact(int $masjidId, array $person): Contact
    {
        $existing = $this->findByAddress($masjidId, $this->normaliseEmail($person['email'] ?? null));

        return $existing ?? $this->createContact($masjidId, $person);
    }

    /**
     * The person a registration is FOR.
     *
     * Same table, same address rule, one extra clause: a registrant must match
     * the stored NAME as well, because a registrant row is a claim about
     * somebody and `contacts.email` is routinely one mailbox for a whole family.
     * Keying on the address alone collapsed two siblings on `household@…` into
     * one contact, and `RegistrationService::normalizeRegistrants()` then deduped
     * the second away — a family that signed two children up got ONE enrolled,
     * silently. See `resolvePayerContact()` above for the argument in full.
     *
     * THE PAYER REGISTERING THEMSELVES is compared FIRST, and on ADDRESS AND
     * NAME rather than address alone — the documented shape where an adult fills
     * the registrant row in with their own details, which must resolve to the
     * payer rather than to a second copy of themselves that the payer is then
     * made the guardian of. The address is compared against BOTH what they
     * submitted and what is stored on the record it resolved to, because a
     * matched payer's stored spelling need not be the one they just typed.
     * Address alone is what collapsed the siblings: it made every child listed
     * under the household mailbox resolve to the parent.
     *
     * @param  array{name?:?string,email?:?string,phone?:?string}  $person
     */
    private function resolveRegistrantContact(
        int $masjidId,
        array $person,
        Contact $payer,
        string $submittedPayerName,
        string $submittedPayerEmail,
    ): Contact {
        $email = $this->normaliseEmail($person['email'] ?? null);

        $addressMatchesPayer = $email !== '' && in_array($email, array_filter([
            $this->normaliseEmail($payer->email),
            $submittedPayerEmail,
        ]), true);

        $nameMatchesPayer = $submittedPayerName !== '' && in_array(
            $this->normaliseName($person['name'] ?? null),
            array_filter([
                $submittedPayerName,
                $this->normaliseName(trim($payer->first_name . ' ' . $payer->last_name)),
            ]),
            true,
        );

        if ($addressMatchesPayer && $nameMatchesPayer) {
            return $payer;
        }

        // THE RETURNING CHILD, and the reason this clause sits ABOVE the address
        // one. A child's identity is not an address: most have none, and the
        // siblings who do share the household mailbox. Keyed on the address
        // alone, exactly one person per address could ever be re-found — the
        // payer — so every child was written fresh every season. Measured: seven
        // contacts and twelve roster rows for a family of three over three
        // seasons, with the child's ḥifẓ stranded on season one's row.
        //
        // It is above the address clause because a child whose own address the
        // office typed in later must still be the same person when a later
        // season lists them under the household mailbox again — matching by
        // address there would find the PAYER, fail the name test, and fork.
        if (($household = $this->matchInHousehold($masjidId, $payer, $person['name'] ?? null)) !== null) {
            return $household;
        }

        $existing = $this->findByAddress($masjidId, $email);

        // FAILING THIS TEST IS SAFE AND FAILING IT THE OTHER WAY IS NOT. A
        // missed match writes a duplicate contact for a returning child whose
        // name was typed differently this season — visible on the directory
        // screen and reconciled by the merge verb — while a false match binds a
        // registration to a person the caller merely named. And neither outcome
        // is an authorization any more: both rows are `self_asserted` until the
        // office says otherwise.
        if ($existing !== null && $this->namesMatch($existing, $person['name'] ?? null)) {
            return $existing;
        }

        return $this->createContact($masjidId, $person);
    }

    /**
     * The one person in THIS PAYER'S HOUSEHOLD carrying this name, or null.
     *
     * The household is not a new table — it is read from rows the application
     * already keeps: every contact this payer already holds a guardian edge
     * over. A returning family therefore attaches to the records last season
     * wrote, because those records are the ones the payer already stands for.
     *
     * WHY THIS IS NOT THE NAME-BASED RE-RUN OF THE HOLE R1–R4 KEPT RE-OPENING.
     * The household is the PAYER'S OWN and is reached only through edges naming
     * the payer as guardian, so a stranger typing a real child's name matches
     * nothing and writes a new row. What the caller can reach by naming is
     * exactly what they could already reach by being its guardian.
     *
     * PENDING EDGES COUNT. A family's first season writes `self_asserted` edges,
     * and its second season routinely arrives before the office has worked the
     * queue; counting only confirmed edges would make the returning-family
     * property false for precisely the school this exists for. Attaching is an
     * IDENTITY decision, never an authority one — the edge the new season writes
     * is still a claim, and `writeRosterMemberships()` leaves an edge the office
     * already confirmed exactly as it found it.
     *
     * AMBIGUITY RESOLVES TO NOBODY. Two wards of one payer sharing a name is not
     * a match this can decide, so it writes a new row and leaves it to the merge
     * verb — the same direction every other near-miss on this path takes, and
     * the safe one: a duplicate is visible and reconcilable, a false match binds
     * a registration to the wrong child.
     */
    private function matchInHousehold(int $masjidId, Contact $payer, ?string $submittedName): ?Contact
    {
        if ($this->normaliseName($submittedName) === '') {
            return null;
        }

        $wardIds = GroupMembership::query()
            ->where('masjid_id', $masjidId)
            ->where('contact_id', $payer->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->whereNotNull('guardian_of_contact_id')
            ->pluck('guardian_of_contact_id')
            ->unique()
            ->all();

        if ($wardIds === []) {
            return null;
        }

        $matches = Contact::query()
            ->where('masjid_id', $masjidId)
            ->whereIn('id', $wardIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (Contact $ward): bool => $this->namesMatch($ward, $submittedName))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** The one live contact of this tenant holding this address, or null. */
    private function findByAddress(int $masjidId, string $email): ?Contact
    {
        if ($email === '') {
            return null;
        }

        return Contact::query()
            ->where('masjid_id', $masjidId)
            ->whereNotNull('email')
            // LOWER() on both sides rather than relying on the column collation:
            // production is utf8mb4_bin (case-SENSITIVE) and the suite runs
            // SQLite, and which record a family attaches to must not depend on
            // which one it is talking to.
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->orderBy('id')
            ->first();
    }

    /** Does this submitted name name the person on that record? */
    private function namesMatch(Contact $contact, ?string $submitted): bool
    {
        $submitted = $this->normaliseName($submitted);

        if ($submitted === '') {
            return false;
        }

        return $submitted === $this->normaliseName(trim($contact->first_name . ' ' . $contact->last_name));
    }

    /**
     * Write one new Contact for this registration. The ONLY contact-creating
     * path on this endpoint, so "who did this row come from" has one answer.
     *
     * TWO THINGS THIS DOES THAT THE SHAPE OF THE ROW DEPENDS ON:
     *
     *  1. THE ADDRESS IS STORED LOWER-CASED, not merely trimmed. Every identity
     *     comparison in this application — this file's `findByAddress()`,
     *     `GroupAudience::identitiesFor()`, `FamilyAccessService` — is made on
     *     `LOWER(email)`, so storing the caller's capitalisation meant
     *     `" Salma.Other@School.test "`, `"SALMA.OTHER@SCHOOL.TEST"` and the
     *     plain address each added another row for one child. Measured: four
     *     contacts on one address.
     *  2. IT NEVER TAKES AN ADDRESS ANOTHER CONTACT ALREADY HOLDS. A second row
     *     carrying a live address is what makes a staff identity ambiguous, and
     *     ambiguous identity is no identity — one anonymous POST could 403 a
     *     teacher out of her own classroom for good. A household mailbox belongs
     *     to the person who typed it; the sibling registered under it gets a row
     *     with no address, which is what a child with no address of their own
     *     has always had, and the office can type one in later.
     *
     * @param  array{name?:?string,email?:?string,phone?:?string}  $person
     */
    private function createContact(int $masjidId, array $person): Contact
    {
        $email = $this->normaliseEmail($person['email'] ?? null);
        [$first, $last] = $this->splitName((string) ($person['name'] ?? ''), 'Registrant');
        $phone = isset($person['phone']) ? trim((string) $person['phone']) : '';

        if ($email !== '' && $this->findByAddress($masjidId, $email) !== null) {
            $email = '';
        }

        $contact = new Contact([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
        ]);

        // Explicit: unbound, so the creating hook stamps nothing.
        $contact->masjid_id = $masjidId;
        $contact->save();

        return $contact;
    }

    /**
     * Lower-cased and trimmed — the form the identity comparisons above are
     * made in, rather than relying on a column collation that differs between
     * production MySQL and the SQLite the suite runs on.
     */
    private function normaliseEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    /** Lower-cased with runs of whitespace collapsed, for the comparison above. */
    private function normaliseName(?string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
    }

    /**
     * Split a full name into [first, last]; last_name is NOT NULL on contacts,
     * so it degrades to an empty string rather than failing a registration over
     * a mononym.
     *
     * @return array{0:string,1:string}
     */
    private function splitName(string $name, string $fallbackFirst): array
    {
        $name = trim($name);

        if ($name === '') {
            return [$fallbackFirst, ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = (string) array_shift($parts);

        return [mb_substr($first, 0, 255), mb_substr(implode(' ', $parts), 0, 255)];
    }

    /** What actually happened, in the registrant's terms. */
    private function outcomeMessage(Registration $registration): string
    {
        if ($registration->status === Registration::STATUS_WAITLISTED) {
            return 'This offering is full — you have been added to the waitlist.';
        }

        if ($registration->isConfirmed()) {
            return 'Thank you — your registration is confirmed.';
        }

        return 'Your place is held while you complete payment.';
    }
}
