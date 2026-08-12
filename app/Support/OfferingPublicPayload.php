<?php

namespace App\Support;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Offering;
use App\Services\Registrations\RegistrationService;

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
    /** Accepting sign-ups right now. */
    public const STATE_OPEN = 'open';

    /**
     * Accepting sign-ups, but every seat is taken — register() waitlists rather
     * than refusing (RegistrationService: `$hasSeat` false => STATUS_WAITLISTED).
     * A renderer switching on this says "join the waitlist", not "closed".
     */
    public const STATE_WAITLIST = 'waitlist';

    /** register() would refuse. */
    public const STATE_CLOSED = 'closed';

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
     * `is_active` is required, so this returns null for an offering another
     * tenant owns, one that does not exist, and one that has been switched off —
     * one indistinguishable miss, and no probing for which offerings live where.
     * That predicate is deliberately the SAME one
     * OfferingRegistrationsController::findOffering uses for quote/register: the
     * read and the write agree on what "publicly available" means, so a page can
     * never render a Register button that the write path would 404.
     */
    public static function forSlug(int $masjidId, string $slug): ?array
    {
        if ($masjidId <= 0 || $slug === '') {
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
     * filter and the same `is_active` predicate as forSlug(): a section pointing
     * at another masjid's offering, a deleted one, or a switched-off one all
     * inline as null and the renderer draws nothing.
     */
    public static function forId(int $masjidId, int $offeringId): ?array
    {
        if ($masjidId <= 0 || $offeringId <= 0) {
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

            'registration_state' => self::state($offering, $form),

            'fee_plans' => self::feePlans($offering),

            'intake_form' => $form ? self::form($form) : null,
        ];
    }

    /**
     * The one verdict a renderer switches on, decided here so the browser never
     * has to reimplement "full still means yes, closed means no".
     *
     * A MISSING INTAKE FORM IS CLOSED. `offerings.intake_form_id` is NOT NULL,
     * but forms soft-delete, and RegistrationService::register throws
     * offeringClosed() when it cannot load the form. Reporting `open` for an
     * offering whose intake has been deleted would put a Register button on a
     * page that refuses every submission. `is_open` above still reports the
     * model's own answer unchanged — this field is the one that accounts for
     * everything the write path checks.
     */
    private static function state(Offering $offering, ?Form $form): string
    {
        if (! $offering->is_open || $form === null) {
            return self::STATE_CLOSED;
        }

        return $offering->isAtCapacity() ? self::STATE_WAITLIST : self::STATE_OPEN;
    }

    /**
     * The ACTIVE plans of this offering, oldest first — the deactivate-and-
     * replace chain in the order it was created, so the plan an admin added last
     * reads last.
     *
     * Filtered to `is_active` because a deactivated plan is refused by
     * RegistrationService::register (planInactive) — offering it on the page
     * would be advertising a price nobody can buy.
     *
     * Filtered to known KINDS because money never degrades: FeePlan has no
     * kind() fallback on purpose, and listTotalFor() throws on an unknown kind.
     * A row whose kind is not in FeePlan::KINDS is therefore not presented as
     * purchasable rather than crashing the public page or being guessed at
     * (.claude/rules/registration-billing-data.md).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function feePlans(Offering $offering): array
    {
        $service = app(RegistrationService::class);

        return FeePlan::query()
            ->where('masjid_id', $offering->masjid_id)
            ->where('offering_id', $offering->id)
            ->where('is_active', true)
            ->whereIn('kind', FeePlan::KINDS)
            ->orderBy('id')
            ->get()
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
                    'amount_minor' => (int) $plan->amount_minor,
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
