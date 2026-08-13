<?php

namespace App\Services\Registrations;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\User;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\FormSchema;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * RegistrationService — the T-006b intake engine
 * (docs/t006-registration-billing-design.md).
 *
 * One transaction per registration: FormSchema validation → form_response →
 * capacity lockForUpdate re-check → registration with snapshot totals → seat
 * reserved (or waitlisted) → free-path synchronous confirmation + roster
 * writes. Ships FREE offerings end-to-end; the paid legs (Checkout Sessions,
 * webhooks) are T-006c/e — this class makes NO Stripe API calls, ever.
 *
 * RESERVE-AT-PENDING (the capacity doctrine): the transaction takes
 * lockForUpdate on the offering row, re-checks registration_count < capacity,
 * and increments under that lock — two guardians racing the last seat means
 * exactly one holds it (pending/confirmed) and the other is waitlisted.
 * Capacity is NEVER exceeded; a waitlisted registration reserves nothing and
 * carries no payment leg, so nobody ever pays for a seat they don't hold.
 *
 * SNAPSHOT PRICING: list_total_minor is computed here, once, from the
 * immutable fee plan; adjusted_total_minor = list − Σ adjustments, floored at
 * 0 (adjustments are UNSIGNED reductions — this engine cannot surcharge, see
 * .claude/rules/registration-billing-data.md). Every later read of what a
 * registration costs comes from the registration row, never back from the
 * plan. Currency lives on fee_plans ONLY and is resolved via fee_plan_id.
 *
 * FREE-PATH CARVE-OUT (declared in the ratified design): a total of 0 — a
 * free plan, or a waiver that reduces the total to 0 — has no Stripe leg, so
 * confirmation is synchronous in-request: no checkout window, no idempotency
 * key, never a $0 session. This is the ONE path that advances state outside a
 * webhook.
 *
 * PAID PENDINGS honour the reaper contract from day one: checkout_expires_at
 * is set here so T-006c's `checkout.session.expired` handler and T-006f's
 * reaper have a bound to sweep against, and the Checkout idempotency key is
 * minted here (the moment the factory's default state describes) for T-006c
 * to key the Session create with.
 *
 * TENANCY: the public register path runs UNBOUND, so every row written here
 * sets masjid_id explicitly from the offering; when a tenant IS bound it must
 * match the offering's masjid or the whole intake is refused. Offering, plan,
 * payer, and every registrant must belong to one organization — a
 * registration can never reference another tenant's rows.
 *
 * GROUPS: on confirmation, registrants materialise as `member` rows in
 * offerings.group_id and the payer gains one (guardian, ward, group) edge per
 * registrant who is not themselves — after the ward's participant row exists,
 * per .claude/rules/groups.md. Contacts-first: this service creates no
 * Contact rows; callers resolve people to Contacts before registering.
 *
 * WHICH MAKES THE CALLER RESPONSIBLE FOR THE GUARDIAN CLAIM, and that is worth
 * saying out loud because it was got wrong once. `writeRosterMemberships()`
 * writes a guardian edge from the payer over every registrant, and a guardian
 * edge is the single fact the parent portal reads to decide whose child's
 * behaviour, ḥifẓ and safeguarding records a credential opens. This service
 * cannot see who asked: `confirm()` runs from a webhook minutes or days later,
 * with no request and no principal, so it takes the registrant list AS the
 * claim and the door that assembled that list owns proving it.
 *
 * The public endpoint's rule is therefore part of THIS contract, not a detail
 * of that controller: `Api\V1\OfferingRegistrationsController` resolves a
 * REGISTRANT to a contact it creates in that same request and never to a
 * pre-existing one, because nothing on an unauthenticated path authorises the
 * claim "I am this person's guardian" over somebody who already exists. Before
 * that split, an anonymous POST naming two real addresses made one parent the
 * recorded guardian of another family's child, in the class she was actually
 * in. Any FUTURE caller that resolves registrants differently — an admin
 * enrolment screen, an importer — is asserting that its own act carries that
 * authority, and had better be authenticated.
 *
 * THE ROSTER IS A MATERIALISATION, NOT THE REGISTRATION. It is optional by
 * construction (`offerings.group_id` is nullable and an offering without one
 * registers families perfectly well), so a group that cannot be resolved is
 * logged and skipped, never thrown — see writeRosterMemberships(). A throw
 * there unwound the ledger row and the `paid` status of a payment the webhook
 * had already been told about.
 */
class RegistrationService
{
    /**
     * How long a paid pending holds its seat before the T-006f reaper may
     * sweep it, minutes. Config-tunable; the default matches the factory's
     * "checkout window open" state.
     */
    public const DEFAULT_CHECKOUT_WINDOW_MINUTES = 30;

    /**
     * Why a seat is being released — the two callers of `releaseSeat()`, which
     * stays the single writer of `offerings.registration_count` besides intake
     * (.claude/rules/registration-billing-data.md).
     *
     *  - EXPIRY (the default, unchanged): an unpaid checkout window closed —
     *    T-006c's `checkout.session.expired` handler and T-006f's reaper.
     *    Pending-only, and money that already landed keeps its seat, because a
     *    redelivered or out-of-order event must never evict a paying family.
     *  - ADMIN (T-006d): a human deliberately cancelled this registration. A
     *    CONFIRMED seat releases too — freeing it for the waitlist is the whole
     *    point — and a settled charge keeps its `paid` money status.
     */
    public const RELEASE_EXPIRY = 'expiry';

    public const RELEASE_ADMIN = 'admin';

    /**
     * The intake transaction. Returns the Registration in its post-intake
     * state: confirmed (free path), pending+awaiting with the checkout window
     * open (paid path), or waitlisted (offering full).
     *
     * @param  Offering  $offering  what is being registered for
     * @param  FeePlan  $feePlan  the chosen (active) plan of THIS offering
     * @param  Contact  $payer  the payer/guardian submitting the registration
     * @param  array<string,mixed>  $intakeData  answers for the offering's intake form
     * @param  array<int,Contact>  $registrants  who the registration is FOR;
     *         empty means the payer registers themselves
     *
     * @throws RegistrationException  closed offering, cross-tenant reference,
     *         inactive/mismatched/unknown plan
     * @throws ValidationException  intake answers fail the form's schema
     */
    public function register(
        Offering $offering,
        FeePlan $feePlan,
        Contact $payer,
        array $intakeData,
        array $registrants = []
    ): Registration {
        $registrants = $this->normalizeRegistrants($payer, $registrants);

        $this->guardTenancy($offering, $feePlan, $payer, $registrants);

        if (! $feePlan->is_active) {
            throw RegistrationException::planInactive();
        }

        // Pre-flight acceptance (unlocked; re-checked under the lock below —
        // a page can sit open in a tab long after an offering closed).
        if (! $offering->is_active || ! $offering->isWithinWindow()) {
            throw RegistrationException::offeringClosed();
        }

        // Validation derives from the STORED schema, never from the payload
        // (App\Support\FormSchema is the enforcement; any client-side check is
        // a convenience). Failing here means nothing has been written at all.
        //
        // MASJID-FILTERED, like every other reader of this column
        // (OfferingPublicPayload::intakeForm, OfferingRegistrationState::
        // intakeFormExists, AdminDashboard\OfferingsController). The filter was
        // missing here until 2026-08-12, and this is the one path that WRITES:
        // the public register endpoint runs UNBOUND, so the BelongsToMasjid
        // global scope adds no filter at all and a bare whereKey() resolves any
        // tenant's form (.claude/rules/tenant-scoping.md). Measured with
        // `intake_form_id` pointing at another organisation's form: the public
        // page said `closed / no_intake_form` — because the readers DID filter —
        // while `POST .../register` answered 200 and wrote a `form_responses`
        // row carrying org A's `masjid_id` beside org B's `form_id`, validated
        // against org B's schema and questions.
        //
        // Reachability, stated plainly rather than inflated:
        // `OfferingFormRequest::ownedRule` blocks a cross-tenant
        // `intake_form_id` on the admin surface, so a row in this shape needs a
        // seeder, an import or a manual DB edit to arise. It is fixed anyway
        // because it is the exact shape of the two holes that shipped this month
        // — a hand-filter present in every reader and absent in the writer — and
        // because the failure is silent: the page reports the offering closed
        // and the write succeeds regardless.
        $form = Form::query()
            ->where('masjid_id', $offering->masjid_id)
            ->whereKey($offering->intake_form_id)
            ->first();

        if (! $form) {
            throw RegistrationException::offeringClosed();
        }

        $schema = FormSchema::for($form);
        $validator = $schema->validator($intakeData);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Only declared answers survive, exactly as on the public forms path.
        $clean = $schema->only($intakeData);

        $listTotal = $this->listTotalFor($feePlan);

        return DB::transaction(function () use ($offering, $feePlan, $payer, $registrants, $form, $schema, $clean, $listTotal): Registration {
            // THE capacity lock: re-read the offering under lockForUpdate so
            // the counter is read under the same lock the insert will bump.
            $locked = Offering::query()->whereKey($offering->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->is_active || ! $locked->isWithinWindow()) {
                throw RegistrationException::offeringClosed();
            }

            // Null capacity is unlimited (forms convention). The re-check under
            // the lock is what makes two racers on the last seat serialize:
            // the second reads the incremented counter and waitlists.
            $hasSeat = $locked->capacity === null
                || $locked->registration_count < $locked->capacity;

            // The intake answers persist as a NORMAL form_response — forms are
            // the only intake machinery, never duplicated. masjid_id explicit:
            // this path runs unbound. amount_due stays null on purpose — an
            // offering's price is the registration's snapshot, and a legacy
            // form feeRule must not create a second, competing money claim.
            $formResponse = FormResponse::create(array_merge(
                [
                    'form_id' => $form->id,
                    'masjid_id' => $offering->masjid_id,
                    'data' => $clean,
                    'entry_count' => $schema->entryCount($clean),
                    'status' => 'new',
                    'submitted_at' => now(),
                ],
                $schema->identity($clean)
            ));

            // Snapshot totals at creation. No adjustments can exist before the
            // registration row does, so adjusted starts equal to list.
            $attributes = [
                'masjid_id' => $offering->masjid_id,
                'offering_id' => $offering->id,
                'fee_plan_id' => $feePlan->id,
                'contact_id' => $payer->id,
                'form_response_id' => $formResponse->id,
                'list_total_minor' => $listTotal,
                'adjusted_total_minor' => $listTotal,
            ];

            if (! $hasSeat) {
                // Raced out (or arrived late): no seat, no money in flight, no
                // checkout window. Promotion off the waitlist is an explicit
                // admin action (T-006d).
                $attributes += [
                    'status' => Registration::STATUS_WAITLISTED,
                    'payment_status' => Registration::PAYMENT_NONE,
                ];
            } elseif ($listTotal === 0) {
                // The declared free-path carve-out: no Stripe leg, so nothing
                // to expire and nothing to key — confirmed synchronously.
                $attributes += [
                    'status' => Registration::STATUS_CONFIRMED,
                    'payment_status' => Registration::PAYMENT_NONE,
                ];
            } else {
                // Reserve-at-pending: the seat is held while payment is
                // outstanding, bounded by checkout_expires_at so the T-006c
                // expired-handler / T-006f reaper contract holds from day one.
                $attributes += [
                    'status' => Registration::STATUS_PENDING,
                    'payment_status' => Registration::PAYMENT_AWAITING,
                    'checkout_expires_at' => now()->addMinutes($this->checkoutWindowMinutes()),
                    'idempotency_key' => 'reg_checkout_' . Str::uuid(),
                ];
            }

            $registration = Registration::create($attributes);

            foreach ($registrants as $contact) {
                Registrant::create([
                    'masjid_id' => $offering->masjid_id,
                    'registration_id' => $registration->id,
                    'contact_id' => $contact->id,
                ]);
            }

            if ($hasSeat) {
                // Atomic UPDATE … SET registration_count = registration_count + 1
                // under the row lock. Pending AND confirmed both hold a seat;
                // waitlisted holds nothing. This is the ONLY place (with the
                // future release paths) that writes the guarded counter.
                $locked->increment('registration_count');
            }

            if ($registration->status === Registration::STATUS_CONFIRMED) {
                $this->writeRosterMemberships($registration);
            }

            return $registration;
        });
    }

    /**
     * Confirm a pending registration: the single seat-confirmation seam.
     *
     * The free path above inlines this outcome inside the intake transaction;
     * a 100%-waiver adjustment routes here; T-006c's webhook handlers will
     * call it when a payment lands. Idempotent — confirming a confirmed
     * registration changes nothing (and cannot duplicate roster rows). Only a
     * PENDING registration may confirm: a waitlisted one holds no seat
     * (promotion is T-006d's explicit admin action) and a cancelled one is
     * dead.
     */
    public function confirm(Registration $registration): Registration
    {
        if ($registration->isConfirmed()) {
            return $registration;
        }

        if ($registration->status !== Registration::STATUS_PENDING) {
            throw RegistrationException::notConfirmable($registration->status);
        }

        return DB::transaction(function () use ($registration): Registration {
            $registration->status = Registration::STATUS_CONFIRMED;
            $registration->save();

            $this->writeRosterMemberships($registration);

            return $registration;
        });
    }

    /**
     * Release a held seat: the counterpart of the reserve-at-pending increment,
     * and the ONLY other place the guarded `registration_count` is written.
     *
     * Called by T-006c's `checkout.session.expired` handler when a checkout
     * window closes unpaid (and, later, by T-006f's reaper for the holds whose
     * webhook never arrived). Same lock discipline as intake: the offering row
     * is taken with lockForUpdate and decremented under it, so a release racing
     * a registration cannot lose a seat or double-return one.
     *
     * IDEMPOTENT, and that is load-bearing — Stripe redelivers. In the default
     * EXPIRY mode only a PENDING seat releases: a confirmed (paid) registration
     * keeps its seat, a waitlisted one never held one, and an already-cancelled
     * one has already given it back, so a second expired event for the same
     * session changes nothing and the counter is decremented exactly once.
     *
     * RELEASE_ADMIN (T-006d's explicit cancel) widens exactly two things and
     * nothing else: a CONFIRMED seat may release (an admin cancelling a seat
     * means to free it), and money that already settled keeps its `paid` status
     * rather than being restated as `canceled` — v1 refunds are the org's own
     * action in its Stripe dashboard, so rewriting a settled ledger row would
     * be a lie. The seat/money split is the point of the two state machines.
     *
     * @param  string  $mode  self::RELEASE_EXPIRY (webhook/reaper) or
     *                        self::RELEASE_ADMIN (explicit admin cancel)
     */
    public function releaseSeat(Registration $registration, string $mode = self::RELEASE_EXPIRY): Registration
    {
        return DB::transaction(function () use ($registration, $mode): Registration {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return $registration;
            }

            $byAdmin = $mode === self::RELEASE_ADMIN;

            // Which seat states this release may act on. Both pending and
            // confirmed HOLD a seat; only an admin may take back a confirmed one.
            $releasable = $byAdmin
                ? [Registration::STATUS_PENDING, Registration::STATUS_CONFIRMED]
                : [Registration::STATUS_PENDING];

            if (! in_array($locked->status, $releasable, true)) {
                return $locked;
            }

            // Belt and braces: money that has already landed keeps its seat
            // even if an expiry event arrives afterwards out of order. An
            // explicit admin cancel is not an out-of-order event, so it passes.
            if (! $byAdmin && in_array($locked->payment_status, [
                Registration::PAYMENT_PAID,
                Registration::PAYMENT_ACTIVE,
            ], true)) {
                return $locked;
            }

            $offering = Offering::query()->whereKey($locked->offering_id)->lockForUpdate()->first();

            if ($offering && $offering->registration_count > 0) {
                // Atomic UPDATE … SET registration_count = registration_count - 1
                // under the row lock, mirroring the intake increment.
                $offering->decrement('registration_count');
            }

            $locked->status = Registration::STATUS_CANCELLED;
            $locked->checkout_expires_at = null;

            // A settled charge stays `paid` on an admin cancel (see above);
            // everything else — nothing charged, or a subscription the caller
            // has just cancelled at Stripe — becomes `canceled`.
            if (! ($byAdmin && $locked->payment_status === Registration::PAYMENT_PAID)) {
                $locked->payment_status = Registration::PAYMENT_CANCELED;
            }

            $locked->save();

            // Keep the caller's instance current (it may be mid-request).
            $registration->refresh();

            return $locked;
        });
    }

    /**
     * Cancel a registration outright — T-006d's explicit admin action, and the
     * ONLY way a confirmed seat ever comes back.
     *
     * Seat-holding rows (pending, confirmed) go through `releaseSeat()` in
     * ADMIN mode, so the guarded `registration_count` still has exactly one
     * writer besides intake. A WAITLISTED row never held a seat, so it is
     * simply marked cancelled — decrementing for it would hand out a seat that
     * was never taken. IDEMPOTENT: cancelling a cancelled registration is a
     * no-op success.
     *
     * The Stripe subscription leg is NOT cancelled here — that is an outbound
     * API call and lives in RegistrationCheckoutService::cancelSubscription(),
     * which the admin controller invokes alongside this. This service makes no
     * Stripe calls, ever.
     *
     * Group memberships are deliberately left in place. A confirmed
     * registration materialises its registrants into offerings.group_id, but
     * `writeRosterMemberships()` skips anyone who is ALREADY a participant —
     * so a membership that predates this registration is indistinguishable
     * from one it created, and removing it could evict a child from a class
     * they belong to for another reason. Roster removal stays an explicit act
     * through the group-membership endpoint.
     */
    public function cancel(Registration $registration): Registration
    {
        return DB::transaction(function () use ($registration): Registration {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return $registration;
            }

            if ($locked->status === Registration::STATUS_CANCELLED) {
                return $locked;
            }

            if ($locked->status === Registration::STATUS_WAITLISTED) {
                $locked->status = Registration::STATUS_CANCELLED;
                $locked->payment_status = Registration::PAYMENT_CANCELED;
                $locked->checkout_expires_at = null;
                $locked->save();

                $registration->refresh();

                return $locked;
            }

            return $this->releaseSeat($locked, self::RELEASE_ADMIN);
        });
    }

    /**
     * Promote a waitlisted registration into a real seat — MANUAL admin action
     * only. The ratified design defers automatic promotion (and the payment
     * window it would need) to its own task; nothing in this codebase promotes
     * anybody on its own.
     *
     * CAPACITY IS NEVER EXCEEDED: the offering is re-read under `lockForUpdate`
     * and re-checked before the counter moves, exactly as intake does, so an
     * admin clicking promote while a public registration takes the last seat
     * cannot oversell it. A full offering refuses.
     *
     * The promoted row lands where intake would have put it had a seat been
     * free: total 0 → confirmed synchronously through `confirm()` (the single
     * confirmation seam, so the roster materialises through the exact same
     * code); total > 0 → pending + awaiting with a fresh checkout window and a
     * fresh idempotency key, so the registrant can pay through the public
     * re-mint endpoint. Nothing here talks to Stripe.
     */
    public function promoteFromWaitlist(Registration $registration): Registration
    {
        return DB::transaction(function () use ($registration): Registration {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                throw RegistrationException::crossTenant('registration');
            }

            if ($locked->status !== Registration::STATUS_WAITLISTED) {
                throw RegistrationException::notPromotable($locked->status);
            }

            // Same lock discipline as intake: read the counter under the lock
            // the increment will run in.
            $offering = Offering::query()->whereKey($locked->offering_id)->lockForUpdate()->first();

            if (! $offering || ! $offering->is_active) {
                throw RegistrationException::offeringClosed();
            }

            // NOTE: the opens_at/closes_at window is deliberately NOT re-checked.
            // It governs PUBLIC intake; promoting someone who queued while the
            // offering was open is an administrative act on an existing line,
            // and refusing it after the window closed would strand the waitlist.
            if ($offering->capacity !== null && $offering->registration_count >= $offering->capacity) {
                throw RegistrationException::offeringFull();
            }

            $offering->increment('registration_count');

            if ($this->chargeableMinor($locked) <= 0) {
                // The free-path carve-out: no Stripe leg, so the seat confirms
                // in-request through the single confirmation seam (which is
                // pending-only — hence the transition through pending here).
                //
                // The same predicate `grantAdjustment()` uses, and for the same
                // reason: a waitlisted row may carry aid (it has no payment leg
                // to conflict with), so promoting one whose per-charge rounds to
                // nothing used to hand out a pending seat with a checkout link
                // that 422s and a deadline for the reaper to act on.
                $locked->status = Registration::STATUS_PENDING;
                $locked->payment_status = Registration::PAYMENT_NONE;
                $locked->checkout_expires_at = null;
                $locked->idempotency_key = null;
                $locked->save();

                $this->confirm($locked);
            } else {
                $locked->status = Registration::STATUS_PENDING;
                $locked->payment_status = Registration::PAYMENT_AWAITING;
                $locked->checkout_expires_at = now()->addMinutes($this->checkoutWindowMinutes());
                $locked->idempotency_key = 'reg_checkout_' . Str::uuid();
                $locked->save();
            }

            $registration->refresh();

            return $locked;
        });
    }

    /**
     * Grant one auditable reduction (aid / discount / code) and re-derive the
     * snapshot: adjusted_total_minor = list_total_minor − Σ adjustments,
     * floored at 0. STRICTLY PRE-CHECKOUT — once a Stripe leg exists there is
     * no post-hoc money movement, ever. A waiver that reaches 0 branches to
     * the free path (never a $0 session): payment leg cleared, and a pending
     * seat confirms synchronously.
     *
     * The recompute reads list_total_minor from the REGISTRATION row — the
     * immutable plan is not consulted again, so a (hypothetical) later plan
     * edit can never restate what somebody agreed to pay.
     *
     * ## "WAIVED" IS WHEN THE CHARGE REACHES ZERO, NOT WHEN THE TOTAL DOES
     *
     * The carve-out below used to test `adjusted_total_minor === 0` exactly,
     * which is the right test for a one-time plan and the wrong one for an
     * installment plan. What Stripe is asked for is `perChargeMinor()` =
     * `intdiv(adjusted_total, installment_count)`, so an installment plan
     * crosses into "nothing to charge" the moment the TOTAL drops below the
     * COUNT — not when it reaches zero. Measured, 9 x $100.00 with
     * `grantAdjustment(aid, 89995)`:
     *
     *     adjusted_total_minor = 5, the admin sees the grant succeed
     *     per charge           = intdiv(5, 9) = 0
     *     checkout             -> 422 "This registration has nothing left to pay"
     *     seat                 = 1, checkout_expires_at still set
     *     +46 min              -> the reaper cancels the seat
     *
     * A registrar granting near-total aid ejected the family she was helping.
     * The rounding doctrine already reasons about "up to N-1 minor units dropped
     * in the PAYER's favour"; this is that same rule at its limit, where the
     * remainder happens to be the whole total, and it lands where every other
     * uncollectable total lands — the free-path carve-out.
     *
     * `adjusted_total_minor` is deliberately NOT rewritten to 0. It is derived
     * from the audit trail (`adjusted = list - sum(adjustments)`) and restating
     * it would make the ledger disagree with the rows it is computed from; the
     * money state (`none`) is what says nothing is being collected.
     */
    public function grantAdjustment(
        Registration $registration,
        string $kind,
        int $amountMinor,
        ?string $reason = null,
        ?User $grantedBy = null
    ): RegistrationAdjustment {
        if (! in_array($kind, RegistrationAdjustment::KINDS, true)) {
            throw RegistrationException::unknownAdjustmentKind($kind);
        }

        // Unsigned reduction magnitude: the table cannot express a surcharge,
        // and this guard keeps a negative from sneaking one in arithmetically.
        if ($amountMinor < 0) {
            throw RegistrationException::adjustmentNotAReduction();
        }

        return DB::transaction(function () use ($registration, $kind, $amountMinor, $reason, $grantedBy): RegistrationAdjustment {
            // Lock the registration so two concurrent grants serialize their
            // recomputes and cannot lose an adjustment.
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                throw RegistrationException::crossTenant('registration');
            }

            $this->guardPreCheckout($locked);

            $adjustment = RegistrationAdjustment::create([
                'masjid_id' => $locked->masjid_id,
                'registration_id' => $locked->id,
                'kind' => $kind,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'granted_by_user_id' => $grantedBy?->id,
            ]);

            $reductions = (int) RegistrationAdjustment::query()
                ->where('registration_id', $locked->id)
                ->sum('amount_minor');

            $locked->adjusted_total_minor = max(0, $locked->list_total_minor - $reductions);

            if ($this->chargeableMinor($locked) <= 0
                && $locked->payment_status === Registration::PAYMENT_AWAITING) {
                // 100% waiver → the free-path carve-out: never a $0 session,
                // so the payment leg (window, key) is dismantled entirely.
                $locked->payment_status = Registration::PAYMENT_NONE;
                $locked->checkout_expires_at = null;
                $locked->idempotency_key = null;
                $locked->save();

                if ($locked->status === Registration::STATUS_PENDING) {
                    // Seat already reserved at pending; confirming keeps it.
                    $this->confirm($locked);
                }
            } else {
                $locked->save();
            }

            // Keep the caller's instance current (it may be mid-request).
            $registration->refresh();

            return $adjustment;
        });
    }

    /**
     * The list total, integer minor units, snapshotted from the immutable
     * plan at registration time:
     *
     *  - free        → 0 (the synchronous no-Stripe path)
     *  - one_time    → amount_minor (what the one Checkout charge will be)
     *  - installment → amount_minor × installment_count (the full commitment
     *                  across the schedule — adjusted_total is the CHARGED
     *                  amount, always, and an installment plan charges this
     *                  much in total)
     *  - recurring   → amount_minor (the per-interval charge; an open-ended
     *                  subscription has no finite total to snapshot)
     *
     * Unknown kinds fail LOUDLY — money kinds never degrade
     * (.claude/rules/registration-billing-data.md).
     */
    public function listTotalFor(FeePlan $feePlan): int
    {
        if (! in_array($feePlan->kind, FeePlan::KINDS, true)) {
            throw RegistrationException::unknownPlanKind((string) $feePlan->kind);
        }

        if ($feePlan->isFree()) {
            return 0;
        }

        if ($feePlan->kind === FeePlan::KIND_INSTALLMENT) {
            return (int) $feePlan->amount_minor * max(1, (int) $feePlan->installment_count);
        }

        return (int) $feePlan->amount_minor;
    }

    /**
     * WHAT STRIPE WOULD ACTUALLY BE ASKED FOR, integer minor units — the test
     * for "is there still anything to collect", as opposed to "is the total
     * zero".
     *
     * Delegates to `RegistrationCheckoutService::perChargeMinor()`, which is THE
     * per-charge function (.claude/rules/registration-billing-data.md) and pure
     * arithmetic over the registration's snapshot and the plan's shape — no
     * Stripe call, which is why calling it does not break this class's "no
     * Stripe API calls, ever". `RegistrationPaymentService::perChargeFallback`
     * reaches for it the same way and for the same reason: two implementations
     * of "what is one charge worth" is how they come to disagree, and the
     * disagreement is always somebody's money.
     *
     * A plan that cannot be loaded, or one whose shape `perChargeMinor()`
     * refuses, degrades to the snapshot total: the free-path carve-out is a
     * decision to collect NOTHING, and it must never be reached by accident
     * through a broken plan row.
     */
    private function chargeableMinor(Registration $registration): int
    {
        $total = (int) $registration->adjusted_total_minor;

        $feePlan = FeePlan::query()
            ->where('masjid_id', $registration->masjid_id)
            ->whereKey($registration->fee_plan_id)
            ->first();

        if (! $feePlan) {
            return $total;
        }

        try {
            return RegistrationCheckoutService::perChargeMinor($registration, $feePlan);
        } catch (\Throwable $e) {
            return $total;
        }
    }

    // ------------------------------------------------------------------ guards

    /**
     * A registration may never reference another tenant's offering, fee plan,
     * or contacts — and a bound tenant (admin-initiated intake) must BE the
     * offering's tenant, or the BelongsToMasjid creating hook would stamp the
     * rows into the wrong organization.
     */
    private function guardTenancy(
        Offering $offering,
        FeePlan $feePlan,
        Contact $payer,
        array $registrants
    ): void {
        $tenant = app(TenantContext::class);

        if ($tenant->hasTenant() && $tenant->get() !== (int) $offering->masjid_id) {
            throw RegistrationException::crossTenant('offering');
        }

        if ((int) $feePlan->offering_id !== (int) $offering->id
            || (int) $feePlan->masjid_id !== (int) $offering->masjid_id) {
            throw RegistrationException::planMismatch();
        }

        if ((int) $payer->masjid_id !== (int) $offering->masjid_id) {
            throw RegistrationException::crossTenant('payer contact');
        }

        foreach ($registrants as $contact) {
            if ((int) $contact->masjid_id !== (int) $offering->masjid_id) {
                throw RegistrationException::crossTenant('registrant contact');
            }
        }
    }

    /**
     * Adjustments are strictly pre-checkout: refuse once any Stripe leg
     * exists or the money machine has advanced beyond `awaiting` (a free
     * registration's `none` is fine — there is no leg to conflict with).
     * A cancelled seat takes no adjustments either; there is nothing to pay.
     */
    private function guardPreCheckout(Registration $registration): void
    {
        $hasStripeLeg = $registration->stripe_checkout_session_id !== null
            || $registration->stripe_subscription_id !== null
            || $registration->stripe_subscription_schedule_id !== null;

        $moneyAdvanced = ! in_array($registration->payment_status, [
            Registration::PAYMENT_NONE,
            Registration::PAYMENT_AWAITING,
        ], true);

        if ($hasStripeLeg || $moneyAdvanced
            || $registration->status === Registration::STATUS_CANCELLED) {
            throw RegistrationException::adjustmentAfterCheckout();
        }
    }

    // ------------------------------------------------------------------- roster

    /**
     * Empty means the payer registers themselves; otherwise dedupe by contact
     * id (the registrants unique index would reject the duplicate anyway —
     * this keeps a doubled form row from aborting the whole intake).
     *
     * @param  array<int,Contact>  $registrants
     * @return array<int,Contact>
     */
    private function normalizeRegistrants(Contact $payer, array $registrants): array
    {
        if ($registrants === []) {
            return [$payer];
        }

        $unique = [];

        foreach ($registrants as $contact) {
            $unique[$contact->id] = $contact;
        }

        return array_values($unique);
    }

    /**
     * Materialise a CONFIRMED registration into offerings.group_id per
     * .claude/rules/groups.md:
     *
     *  - each registrant → a `member` (participant) row, deduped in code —
     *    the DB unique index cannot dedupe participant rows because their
     *    null ward is distinct under both MySQL and SQLite;
     *  - the payer → one (guardian, ward, group) edge PER registrant who is
     *    not themselves, written only AFTER the ward's participant row exists
     *    (the edge invariant), deduped exactly;
     *  - a payer who is also a registrant gets a participant row and NO
     *    self-guardian edge.
     *
     * ## EVERY ROW THIS METHOD WRITES IS `self_asserted`, AND NEVER CONFIRMED
     *
     * This is THE unauthenticated roster writer in this application. The list it
     * materialises came from a public form — no session, no token, no proof of
     * control of either address — so what it writes is a CLAIM and not a grant:
     * it lists a person, it counts towards capacity, a teacher may keep records
     * about the child it enrols, and it opens not one byte of anybody's records
     * until the office confirms it (`GroupMembership::PROVENANCES`,
     * `GroupAudience::membershipsFor()`, `FamilyAccessService::guardianEdges()`).
     *
     * NEVER CONFIRMED — not conditionally, not when a staff principal happens to
     * be on the request. A free registration confirms in-request while an
     * anonymous POST is in flight; a priced one confirms minutes later from a
     * Stripe webhook with no request at all; and an admin re-driving either path
     * is still materialising a list the public typed. The authority behind the
     * claim is identical in all three, so reading the ambient principal would
     * make provenance a fact about WHO PRESSED GO rather than about who vouched
     * for the child — which is the exact substitution that produced three rounds
     * of defects here. Confirmation is a separate, deliberate act with its own
     * endpoint and its own actor: `GroupMembershipsController::confirm`.
     *
     * A row that ALREADY EXISTS is left exactly as it is — the `exists()` checks
     * below are what keep a second season's registration from downgrading an
     * edge the office confirmed last September.
     *
     * Consent is NOT written here: a guardian edge records a relationship,
     * never consent — absence of a record means no consent, and recording it
     * is GroupConsentController's job at the guardian's own request.
     *
     * ## A ROSTER THAT CANNOT BE WRITTEN IS NOT A REASON TO UNDO A PAYMENT
     *
     * A roster is OPTIONAL by construction: `offerings.group_id` is nullable,
     * and an offering with no group has always registered families perfectly
     * well — the first branch below returns and nothing is materialised. That
     * is the fact that decides what the other two failures mean.
     *
     * This method used to throw `rosterMisconfigured()` when the group did not
     * resolve for the tenant, and the throw was the defect. `confirm()` calls
     * this INSIDE its transaction and `settle()` calls `confirm()` inside ITS
     * transaction, so the throw rolled back the `registration_payments` ledger
     * row and `payment_status = paid` with it, and propagated to
     * `StripeWebhookController::handle`, which answered 500. Measured, with the
     * offering's roster group soft-deleted by one unguarded admin click:
     *
     *     checkout.session.completed x3 (Stripe retries) -> 500, 500, 500
     *     after the retries:  status=pending payment=awaiting payments=0
     *     +46 min reaper:     cancelled / canceled / seat 0 / payments 0
     *
     * The family paid, the platform kept no record, and the reaper cancelled her
     * seat. The FREE path failed the same way and more visibly: `register`
     * answered an anonymous parent 422 with this class's internal invariant
     * sentence — a refusal no read surface predicts and that is not a member of
     * `OfferingRegistrationState::REASONS`.
     *
     * So the two failures are now what they actually are:
     *
     *  - THE GROUP DOES NOT RESOLVE (soft-deleted underneath the pointer, or
     *    hard-deleted before `nullOnDelete` could fire). This is the same fact
     *    as `group_id === null` — there is no live roster to materialise into —
     *    and the codebase already treats that as ordinary. Logged loudly with
     *    everything an admin needs to repair it, and the registration stands.
     *  - THE GROUP BELONGS TO ANOTHER ORGANISATION. Still never written into —
     *    that is the whole point of the check — but by SKIPPING, not by
     *    throwing. Refusing to write the row is what protects the other tenant;
     *    destroying this tenant's money was only ever the delivery mechanism.
     *
     * Nothing is swallowed except this one judgement: a database error, a
     * deadlock or a constraint violation still propagates, still 500s, and
     * Stripe still retries it — which is correct, because those are transient
     * and this is not.
     *
     * `AdminDashboard\GroupsController::destroy` now refuses to delete a group
     * that is a live offering's roster, which is what stops this arising at all.
     * This branch is what keeps rows broken before that guard — or broken by an
     * import — from costing a family her money.
     */
    private function writeRosterMemberships(Registration $registration): void
    {
        $offering = Offering::query()->whereKey($registration->offering_id)->first();

        if (! $offering || $offering->group_id === null) {
            return;
        }

        $group = Group::query()->whereKey($offering->group_id)->first();

        if (! $group || (int) $group->masjid_id !== (int) $registration->masjid_id) {
            // Loud, searchable, and carrying everything the repair needs: which
            // program, which group id, and which registration did not land on a
            // roster because of it.
            Log::warning(
                'Registration confirmed with NO roster: this offering\'s group could not be resolved for its '
                . 'organisation. The seat and any payment stand; re-point the offering at a live group and add '
                . 'the registrant to it.',
                [
                    'registration_id' => $registration->id,
                    'masjid_id' => (int) $registration->masjid_id,
                    'offering_id' => (int) $offering->id,
                    'group_id' => (int) $offering->group_id,
                    'cross_tenant' => $group !== null,
                ]
            );

            return;
        }

        $wardIds = Registrant::query()
            ->where('registration_id', $registration->id)
            ->pluck('contact_id')
            ->all();

        // Participants first — the guardian-edge invariant requires the ward
        // to already hold a participant membership in the group.
        foreach ($wardIds as $contactId) {
            $alreadyParticipant = GroupMembership::query()
                ->where('group_id', $group->id)
                ->where('contact_id', $contactId)
                ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
                ->exists();

            if (! $alreadyParticipant) {
                $participant = new GroupMembership([
                    'masjid_id' => $registration->masjid_id,
                    'group_id' => $group->id,
                    'contact_id' => $contactId,
                    'role' => GroupMembership::ROLE_MEMBER,
                    'joined_at' => now(),
                ]);

                $participant->selfAssertedFrom($registration)->save();
            }
        }

        $guardianId = $registration->contact_id;

        if ($guardianId === null) {
            return;
        }

        foreach ($wardIds as $wardId) {
            if ((int) $wardId === (int) $guardianId) {
                continue;   // nobody is their own guardian
            }

            $edgeExists = GroupMembership::query()
                ->where('group_id', $group->id)
                ->where('contact_id', $guardianId)
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->where('guardian_of_contact_id', $wardId)
                ->exists();

            if (! $edgeExists) {
                $edge = new GroupMembership([
                    'masjid_id' => $registration->masjid_id,
                    'group_id' => $group->id,
                    'contact_id' => $guardianId,
                    'role' => GroupMembership::ROLE_GUARDIAN,
                    'guardian_of_contact_id' => $wardId,
                    'joined_at' => now(),
                ]);

                $edge->selfAssertedFrom($registration)->save();
            }
        }
    }

    // -------------------------------------------------------------------- misc

    private function checkoutWindowMinutes(): int
    {
        return (int) config(
            'services.stripe.registration_checkout_window_minutes',
            self::DEFAULT_CHECKOUT_WINDOW_MINUTES
        );
    }
}
