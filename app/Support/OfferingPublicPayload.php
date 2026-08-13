<?php

namespace App\Support;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Offering;
use App\Services\Registrations\RegistrationService;
use App\Support\OfferingRegistrationState as State;

/**
 * The PUBLIC shape of one offering — everything an anonymous visitor needs in
 * order to decide to register, and nothing else (T-006g).
 *
 * ONE presenter, TWO consumers, on purpose:
 *
 *   - Api\V1\OfferingRegistrationsController@show serves it at
 *     GET /api/v1/offerings/{slug} (a dedicated registration page);
 *   - SectionContentBinder::bindOffering inlines it under `content.offering`
 *     when a page carrying an `offering` section is fetched.
 *
 * They must never drift. Two hand-written copies of "what is safe to publish
 * about an offering" is exactly how a private field reaches a public page: the
 * one that is reviewed gets tightened and the one nobody remembered does not.
 *
 * ------------------------------------------------------------------ WHAT IS IN
 *
 * Every field here is either printed on the page or required to POST
 * /api/v1/offerings/{slug}/register:
 *
 *   slug                the public handle every write endpoint is keyed on
 *   name, description   the copy
 *   kind                presentation discriminator only (Offering::kind())
 *   opens_at/closes_at  the window, so a page can say "opens 4 September"
 *   is_open             the MODEL's accessor, verbatim — is_active AND window
 *   closed_reason       the model's accessor, verbatim
 *   seats               is_full + how many places remain
 *   registration_state  the single verdict a renderer switches on
 *   registration_state_reason
 *                       WHY that verdict, when it is `closed`. Null otherwise.
 *                       Computed by the SAME call that produces the verdict
 *                       (App\Support\OfferingRegistrationState), so the two
 *                       cannot disagree. It is NOT a second `closed_reason`:
 *                       `closed_reason` above answers "is the window open",
 *                       which is the model's question, and reports null for an
 *                       offering whose intake form is gone or whose fee plans
 *                       are all deactivated. This one answers "would a
 *                       registration be accepted", which is the write path's
 *                       question, and it is the one a renderer explains itself
 *                       from.
 *   fee_plans[]         id (needed to register), kind, label, currency, amounts
 *   intake_form         name, description, schema, the five wording settings
 *
 * --------------------------------------------------------------- WHAT IS OUT
 *
 *   - `id`, `masjid_id`, `intake_form_id`, `group_id` — the public API is
 *     addressed by SLUG (offerings) and UUID (registrations); an internal id is
 *     not needed to register, so it is not published. Fee-plan ids are the one
 *     exception, because `register` takes `fee_plan_id` as its input.
 *   - `registration_count`, and every per-status roster count. `seats.remaining`
 *     is a property of the OFFERING (how many more places it will accept);
 *     `registration_count` is a count of PEOPLE, and a public page is never a
 *     window onto the CRM (.claude/rules/section-types.md, .claude/rules/groups.md).
 *     `capacity` is withheld for the same reason: publishing capacity AND
 *     remaining hands out the subtraction.
 *   - Anything about a registrant. There is no roster here, no names, no
 *     "3 people have signed up".
 *   - `settings` (the offering's operational knobs) and the intake form's own
 *     `settings` beyond the five wording keys — that bag holds notification
 *     recipients and the identity map (SectionContentBinder::bindForm makes the
 *     same cut for the same reason).
 *   - The intake form's `fee` rule. MONEY FOR AN OFFERING COMES FROM ITS FEE
 *     PLANS AND NOWHERE ELSE: RegistrationService::register prices from the plan
 *     and deliberately leaves form_responses.amount_due null. Publishing a
 *     legacy form fee here would put a second, contradictory price on the page.
 *   - The intake form's own `accepting` / `closed_reason`. The OFFERING's window
 *     is what register() enforces; a second "is this open" on the same payload is
 *     how the two drift apart and one of them starts lying.
 *
 * ---------------------------------------------------------------- MONEY RULES
 *
 * Integer minor units everywhere, currency always beside the amount, and every
 * total is computed SERVER-SIDE by RegistrationService::listTotalFor — the same
 * function that snapshots `list_total_minor` at intake, so the number on the
 * page and the number charged come from one implementation. Nothing here is a
 * float, a formatted string, or a subtotal for a client to add up.
 */
final class OfferingPublicPayload
{
    /**
     * The verdict vocabulary. Aliases of App\Support\OfferingRegistrationState,
     * which OWNS the decision — kept here so existing callers of
     * `OfferingPublicPayload::STATE_*` keep resolving, and defined by reference
     * so there is exactly one spelling of each word.
     */
    public const STATE_OPEN = State::STATE_OPEN;

    public const STATE_WAITLIST = State::STATE_WAITLIST;

    public const STATE_CLOSED = State::STATE_CLOSED;

    /**
     * Resolve one offering by its public slug for this tenant.
     *
     * masjid_id is filtered EXPLICITLY. /api/v1 never runs the tenant
     * middleware, so the BelongsToMasjid global scope is UNBOUND here and adds
     * no filter at all — the same fail-open shape that leaked 14 rows across two
     * tenants through SearchableTrait on 2026-08-11
     * (.claude/rules/tenant-scoping.md). Every lookup in this class names the
     * masjid.
     *
     * THE MASJID MUST STILL EXIST. Naming a tenant is not the same as verifying
     * one: `masjids` soft-deletes, so an offboarded organisation's id goes on
     * matching its own offerings forever, and this read is what makes those
     * offerings reachable by a family at all. Measured on this branch before the
     * fix — offering under masjid A, `$masjidA->delete()`, then GET / quote /
     * register all answered 200 and wrote a confirmed registration, its form
     * response and a seat, for an organisation that had been offboarded. See
     * App\Support\PublicTenant for how far that got and for what the money layer
     * refused on its own.
     *
     * THE ORGANISATION'S CRM MUST BE SWITCHED ON. Offerings, fee plans and
     * rosters all live inside the `crm`-gated admin group, so an organisation
     * with `crm_enabled = false` cannot see, price or staff a single thing this
     * payload publishes — while this read is what makes the registration
     * endpoints reachable by a family at all. Measured before the fix: with the
     * flag off, the public read served `registration_state: "open"`, quote
     * priced it at $150 and register opened a live Checkout Session on the
     * organisation's connected account, and its own admins got 403 from every
     * offerings and registrations endpoint. `PublicTenant::crmEnabled()` carries
     * the whole argument for why the gate belongs on this side.
     *
     * `is_active` is required, so this returns null for an offering another
     * tenant owns, one whose organisation is gone, one whose organisation has no
     * CRM, one that does not exist, and one that has been switched off — one
     * indistinguishable miss, and no probing for which offerings live where.
     * That predicate is deliberately the SAME one
     * OfferingRegistrationsController::findOffering uses for quote/register: the
     * read and the write agree on what "publicly available" means, so a page can
     * never render a Register button that the write path would 404.
     */
    public static function forSlug(int $masjidId, string $slug): ?array
    {
        if ($masjidId <= 0 || $slug === '' || ! PublicTenant::crmEnabled($masjidId)) {
            return null;
        }

        $offering = Offering::query()
            ->where('masjid_id', $masjidId)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        return $offering ? self::build($offering) : null;
    }

    /**
     * Resolve by internal id — the handle a page SECTION stores, since an admin
     * picks an offering from a list rather than typing a slug. Same tenant
     * filter, the same live-and-CRM-enabled organisation check and the same
     * `is_active` predicate as forSlug(): a section pointing at another masjid's
     * offering, an offboarded organisation's, one whose CRM is switched off, a
     * deleted one, or a switched-off one all inline as null and the renderer
     * draws nothing.
     *
     * This is the half a standalone-endpoint fix would miss. An `offering`
     * section publishes the identical payload inside a page, so gating only
     * GET /api/v1/offerings/{slug} would leave the organisation's own website
     * still rendering the program, its prices and its Register button.
     */
    public static function forId(int $masjidId, int $offeringId): ?array
    {
        if ($masjidId <= 0 || $offeringId <= 0 || ! PublicTenant::crmEnabled($masjidId)) {
            return null;
        }

        $offering = Offering::query()
            ->where('masjid_id', $masjidId)
            ->whereKey($offeringId)
            ->where('is_active', true)
            ->first();

        return $offering ? self::build($offering) : null;
    }

    /**
     * The payload for an already-resolved, already-tenant-checked offering.
     *
     * @return array<string,mixed>
     */
    public static function build(Offering $offering): array
    {
        $form = self::intakeForm($offering);

        // Whether this organisation can take a card at all. Read ONCE and used
        // for both halves below, so the plans this payload publishes and the
        // verdict it publishes beside them are answers to the same question
        // about the same organisation at the same instant.
        $canCollect = \App\Models\Masjid::find($offering->masjid_id)?->canAcceptDonations();

        // The raw chain, filtered exactly once, by the predicate that also
        // decides the verdict and that `findFeePlan()` accepts on. A plan
        // withheld here is not one a registrant can name in `fee_plan_id`, so
        // it must not count towards "there is something to buy" either.
        $rows = State::planRows($offering);
        $plans = self::feePlans(State::purchasablePlans($rows, $canCollect));

        $state = State::decide($offering, $form !== null, $rows, $canCollect);

        return [
            'slug' => $offering->slug,
            'name' => $offering->name,
            'description' => $offering->description,
            // Degraded read: an unrecognised kind renders as the plainest
            // presentation rather than reaching the page as a raw string.
            'kind' => $offering->kind(),

            'opens_at' => $offering->opens_at?->toISOString(),
            'closes_at' => $offering->closes_at?->toISOString(),

            // Verbatim from the model's accessors — never recomputed here and
            // never recomputed in the browser. The null-bound window convention
            // and the server's clock have exactly one implementation
            // (Offering::getIsOpenAttribute).
            'is_open' => (bool) $offering->is_open,
            'closed_reason' => $offering->closed_reason,

            'seats' => [
                'is_full' => $offering->isAtCapacity(),
                // null = unlimited (the forms convention), which is genuinely
                // unknown rather than 0. Capacity itself is NOT published — see
                // the class docblock.
                'remaining' => $offering->capacity === null
                    ? null
                    : max(0, (int) $offering->capacity - (int) $offering->registration_count),
            ],

            // The one verdict a renderer switches on, so the browser never has
            // to reimplement "full still means yes, closed means no". The
            // clauses it accounts for — and the write-path refusal each one
            // corresponds to — are enumerated in OfferingRegistrationState.
            // `is_open` above still reports the MODEL's own answer unchanged;
            // this pair is what accounts for everything the write path checks.
            'registration_state' => $state['state'],
            'registration_state_reason' => $state['reason'],

            'fee_plans' => $plans,

            'intake_form' => $form ? self::form($form) : null,
        ];
    }

    /**
     * The PURCHASABLE plans of this offering, oldest first — the deactivate-and-
     * replace chain in the order it was created, so the plan an admin added last
     * reads last.
     *
     * This method PRESENTS; it does not judge. Which plans reach it is
     * `OfferingRegistrationState::isPurchasable()`'s decision alone — the same
     * one `registration_state` is aggregated from and the same one
     * `OfferingRegistrationsController::findFeePlan()` accepts on — because a
     * private copy of that judgement here is precisely how the page came to
     * publish `amount_minor: 15000` for a plan the verdict beside it declared
     * unbuyable. Everything the predicate withholds (deactivated, an
     * unrecognised money kind, a subscription with no valid interval, an
     * installment plan with no payments, a paid kind that charges nothing, a
     * charge an un-onboarded organisation cannot collect) is the same 404 from
     * the write path as a plan that never existed.
     *
     * ## The claim, stated correctly: it is PER PLAN
     *
     * This docblock used to promise that "a price is never published for a plan
     * this same payload's `registration_state` declares unbuyable", and that is
     * false as written — measured, `not_yet_open`, a window that has `closed`,
     * and `no_intake_form` all serve `registration_state: closed` WITH
     * `fee_plans` populated at 15000. The BEHAVIOUR is right and stays: a page
     * that says "opens 4 September" needs the price to say it with, and those
     * three verdicts are facts about the OFFERING, not about any plan.
     *
     * What is true, and is what the shared predicate actually buys, is the
     * per-plan half: a plan is published if and only if `isPurchasable()`
     * accepts it, and the two verdicts that mean "no plan survives that
     * predicate" — `no_fee_plan` and `org_cannot_collect` — are therefore
     * ALWAYS served beside `fee_plans: []`, because they are computed from the
     * same call over the same rows at the same instant. A future reader trusts
     * the sentence, so the sentence is the one the code holds.
     *
     * @param  list<FeePlan>  $plans  already filtered by the predicate
     * @return array<int,array<string,mixed>>
     */
    private static function feePlans(array $plans): array
    {
        $service = app(RegistrationService::class);

        return collect($plans)
            ->map(function (FeePlan $plan) use ($service): array {
                // Server-computed, by the SAME function that snapshots
                // list_total_minor at intake. The client is never asked to
                // multiply an amount by an installment count.
                $total = $service->listTotalFor($plan);

                return [
                    // The only internal id in this payload, and it is here
                    // because POST /offerings/{slug}/register takes fee_plan_id.
                    'id' => $plan->id,
                    'kind' => $plan->kind,
                    'label' => $plan->label,
                    // Lowercase ISO-4217 as stored. Always beside an amount —
                    // no amount in this payload travels without its currency.
                    'currency' => $plan->currency,
                    // INTEGER MINOR UNITS, per charge: the single charge for
                    // one_time, ONE installment for installment, one interval
                    // for recurring, 0 for free.
                    //
                    // A `free` plan publishes 0 rather than its column. The
                    // column CAN hold a stale amount — `StoreFeePlanRequest`
                    // pins free to exactly 0, but an import or a manual edit is
                    // not that request — and `isPurchasable()` deliberately does
                    // not withhold such a row, because it is a real registration
                    // path that the free-path carve-out confirms in-request and
                    // never charges. Measured: the page printed "$150" beside
                    // `total_minor: 0` and `requires_payment: false` on the same
                    // plan. NO AMOUNT TRAVELS ON THIS PAYLOAD THAT WOULD NOT BE
                    // CHARGED; `$plan->isFree()` is the model's own word for
                    // which rows those are.
                    'amount_minor' => $plan->isFree() ? 0 : (int) $plan->amount_minor,
                    // INTEGER MINOR UNITS, the whole commitment: amount x count
                    // for an installment plan, the same as amount_minor for the
                    // rest. An open-ended `recurring` plan has no finite total,
                    // so this is one interval's charge and `billing_interval`
                    // below is what says so.
                    'total_minor' => $total,
                    'billing_interval' => $plan->billing_interval,
                    'installment_count' => $plan->installment_count,
                    // 0 is the declared free-path carve-out: confirmed
                    // in-request, no Stripe leg, never a $0 Checkout Session.
                    'requires_payment' => $total > 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The intake form, tenant-filtered. Soft-deleted forms do not resolve, which
     * is what makes state() report `closed` for an offering whose intake is gone.
     */
    private static function intakeForm(Offering $offering): ?Form
    {
        if (! $offering->intake_form_id) {
            return null;
        }

        return Form::query()
            ->where('masjid_id', $offering->masjid_id)
            ->whereKey($offering->intake_form_id)
            ->first();
    }

    /**
     * The form's PUBLIC half: the questions, and the wording drawn around them.
     *
     * No id and no slug — the registration is posted to
     * /api/v1/offerings/{slug}/register, not to the form's own endpoint, so
     * neither is needed. No `settings` beyond the five wording keys (the rest is
     * notification recipients and the identity map). No `fee`, and no second
     * `accepting` flag — see the class docblock.
     *
     * @return array<string,mixed>
     */
    private static function form(Form $form): array
    {
        $settings = $form->settings ?? [];

        return [
            'name' => $form->name,
            'description' => $form->description,
            'schema' => $form->schema,
            'settings' => [
                'submitButtonLabel' => $settings['submitButtonLabel'] ?? 'Register',
                'successTitle' => $settings['successTitle'] ?? null,
                'successBody' => $settings['successBody'] ?? null,
                'successNextSteps' => $settings['successNextSteps'] ?? [],
                'intro' => $settings['intro'] ?? null,
            ],
        ];
    }
}
