<?php

namespace App\Support;

use App\Http\Controllers\AdminDashboard\FormStaffCodesController;
use App\Models\Form;
use App\Models\FormDateReservation;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Services\Stripe\FormCheckoutRefused;
use App\Services\Stripe\FormResponseCheckoutService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Dates a form response reserves from its form's list (settings.reservation; Ramadan
 * giving through forms, 2026-09-25): an iftar sponsorship level that takes one evening,
 * which is then no longer offered to anyone else.
 *
 * ## Race-safe: two payers never hold one date
 *
 *   1. The page offers only dates nobody holds (offerable(), through the
 *      `reservable_dates` options source), and the submit's validator checks the answer
 *      against the same live set.
 *   2. Inside the submit's transaction, under the FORM's row lock, claim() looks again:
 *      a hold that still counts refuses the date; one that no longer counts (below) is
 *      released first. Two submissions for one date therefore queue on the form lock and
 *      the second is told the date has gone.
 *   3. hold() inserts the row whose `holding_on` is under unique(form_id, holding_on).
 *      Anything that writes around the lock still meets the index; its violation is
 *      FormDateTaken, answered like step 2, never a 500.
 *
 * ## When a hold is released (the abandoned-payment decision)
 *
 * A card registration is written unpaid before its Stripe page opens. Its hold lasts the
 * page's life (FormResponseCheckoutService::PAGE_LIFETIME_MINUTES, plus the minute of
 * slack that service adds) and HOLD_GRACE_MINUTES more, so a payment made in the last
 * second of the page, and a webhook a little behind it, still find their date. Each new
 * page ("Return to payment") renews it, under the response's lock (renewForPage()), but
 * never past HOLD_LIMIT_MINUTES after the registration was submitted: a new page whose
 * hold would run beyond that is refused (EXPIRED), so nobody keeps an evening off the
 * list for ever by reopening an unpaid page.
 *
 * After that the hold has LAPSED. It is not deleted by a clock: the date is offered
 * again, and only the next payer who asks for it releases it (reason `lapsed`). Until
 * then a late payment still finds its date. A payment that lands after its date was
 * taken is recorded (money is never refused), and the people involved are told: the
 * payer's receipt says the date could not be kept and the organisation will be in
 * touch, the coordinators' email names the conflict (FormNotifier), the admin board
 * lists it and the responses screen counts it (conflicts()), and a warning is logged
 * (notePaidAfterLosingDate()). The organisation refunds or rebooks by hand.
 *
 * A family paying the office, a staff-code cash entry and a paid row never lapse. A row
 * an admin cancels stops protecting its date at once, and is released (reason
 * `cancelled`) when the next payer asks for it; restoring the registration before then
 * gives the date straight back, and restoring it after asks for the date again under
 * the form lock (reclaimForRestore()), refused when someone else holds it. Deleting a
 * response deletes its reservation.
 *
 * ## Scoping
 *
 * Every query filters by form id, and every form reached here was found through its own
 * masjid first (the public submit by the masjid-id header, the checkout by the row, the
 * admin board by the route), so the tenant scope is bypassed on purpose with the
 * filter written out.
 *
 * Pinned by tests/Feature/FormDateReservationTest.php.
 */
final class FormReservations
{
    /** How long after its page's life an unpaid card registration still holds its date. */
    public const HOLD_GRACE_MINUTES = 15;

    /**
     * The longest an unpaid card registration keeps its date, from its submission,
     * however often its payment page is reopened.
     *
     * Derivation: one page's hold is 46 minutes (30 of page, 1 of slack, 15 of grace), so
     * 120 lets a payer whose card was declined, or who closed the tab, open a new page
     * until 74 minutes after submitting, and no later. The figure is the review's own
     * example (submit + 2 hours, 2026-09-25); it has not been checked against MEC's
     * sponsors. Anyone who runs out of it registers again, which the form-submit
     * throttle limits.
     */
    public const HOLD_LIMIT_MINUTES = 120;

    /** Refused under the lock: another payer holds the date. */
    public const TAKEN = 'That date has just been reserved by someone else. Please choose another date.';

    /** "Return to payment" on a registration whose lapsed hold another payer has since taken. */
    public const LOST = 'The date you chose was released because payment was not completed in time, and someone else has since reserved it. Please register again and choose another date.';

    /** "Return to payment" on an unpaid registration past HOLD_LIMIT_MINUTES. */
    public const EXPIRED = 'The time to pay for this date has run out, so it is offered to others again. Please register again to choose a date.';

    /** An admin restoring a cancelled registration whose date someone else now holds. %s is the date. */
    public const RESTORE_TAKEN = 'This registration cannot be restored: its date, %s, has since been reserved by someone else. Free that date first, or register the sponsor again with another date.';

    /** No listed date is open, on a question that requires one. */
    public const NONE_OPEN = 'No dates are open for reservation right now.';

    /** The answer is not among the dates open right now. */
    public const NO_LONGER_OPEN = 'That date is no longer available — reload the form to see the dates still open.';

    /**
     * The dates a payer may choose now: the form's list, from today on the
     * organisation's own clock, less every date a hold still protects.
     *
     * @return array<int,string>
     */
    public static function offerable(Form $form, ?CarbonInterface $now = null): array
    {
        $reservation = $form->reservation();

        if ($reservation === null) {
            return [];
        }

        $now ??= Date::now();
        $today = self::today($form, $now);
        $blocked = [];

        foreach (self::holding($form) as $held) {
            if ($held->response === null || $held->yieldsTo($held->response, $now) === null) {
                $blocked[$held->holding_on] = true;
            }
        }

        return array_values(array_filter(
            $reservation['dates'],
            fn (string $date) => $date >= $today && ! isset($blocked[$date])
        ));
    }

    /**
     * Every date an answer might name, labelled, for reading stored answers: the list,
     * every date ever reserved on the form, and the answers passed in.
     *
     * @param  array<int,mixed>  $answers
     * @return array<int,array{value:string,label:string}>
     */
    public static function labelled(Form $form, array $answers = []): array
    {
        $dates = array_fill_keys($form->reservation()['dates'] ?? [], true);

        foreach (FormDateReservation::withoutMasjidScope()->where('form_id', $form->id)->pluck('reserved_on') as $date) {
            $dates[substr((string) $date, 0, 10)] = true;
        }

        foreach ($answers as $answer) {
            if (is_string($answer) && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $answer) === 1) {
                $dates[$answer] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates, SORT_STRING);

        return array_map(fn (string $date) => ['value' => $date, 'label' => self::label($date)], $dates);
    }

    /** "Wednesday, February 10, 2027". A calendar date, never timezone-converted. */
    public static function label(string $date): string
    {
        return SchoolCalendar::label($date);
    }

    /**
     * Make $date free for a new hold, on the form row the caller has locked: true when
     * nobody holds it now, false when a hold that still counts does.
     *
     * A hold that no longer counts (FormDateReservation::yieldsTo()) is released here,
     * after its registration's row is locked and the hold is read again under that lock:
     * a "Return to payment" renewing it, or its webhook marking it paid, lands either
     * before (and the hold counts again) or after (and finds it released), never between.
     */
    public static function claim(Form $lockedForm, string $date, ?CarbonInterface $now = null): bool
    {
        $now ??= Date::now();

        $held = FormDateReservation::withoutMasjidScope()
            ->where('form_id', $lockedForm->id)
            ->where('holding_on', $date)
            ->first();

        if ($held === null) {
            return true;
        }

        $owner = FormResponse::query()->whereKey($held->form_response_id)->lockForUpdate()->first();
        $held = FormDateReservation::withoutMasjidScope()->whereKey($held->getKey())->lockForUpdate()->first();

        if ($held === null || $held->holding_on === null) {
            return true;
        }

        $reason = $owner === null ? FormDateReservation::RELEASED_LAPSED : $held->yieldsTo($owner, $now);

        if ($reason === null) {
            return false;
        }

        $held->forceFill([
            'holding_on' => null,
            'released_at' => $now,
            'release_reason' => $reason,
        ])->save();

        return true;
    }

    /**
     * The hold for a response the caller has just written, inside its transaction.
     *
     * @throws FormDateTaken when the unique index refuses it: another hold on the date
     */
    public static function hold(FormResponse $row, string $date, ?CarbonInterface $heldUntil): FormDateReservation
    {
        $reservation = (new FormDateReservation())->forceFill([
            'masjid_id' => $row->masjid_id,
            'form_id' => $row->form_id,
            'form_response_id' => $row->getKey(),
            'reserved_on' => $date,
            'holding_on' => $date,
            'held_until' => $heldUntil,
        ]);

        try {
            $reservation->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! self::isHoldingIndexViolation($e)) {
                throw $e;
            }

            throw new FormDateTaken();
        }

        return $reservation;
    }

    /**
     * When an unpaid card registration whose page opens at $now stops holding its date:
     * the page's life, the minute of slack FormResponseCheckoutService adds to it, and
     * HOLD_GRACE_MINUTES.
     */
    public static function cardHoldUntil(CarbonInterface $now): CarbonInterface
    {
        return CarbonImmutable::instance($now)
            ->addMinutes(FormResponseCheckoutService::PAGE_LIFETIME_MINUTES + 1 + self::HOLD_GRACE_MINUTES);
    }

    /**
     * A card payment page is about to open for $row, which the caller holds locked: its
     * hold is renewed for the new page's life. A hold another payer took after it lapsed
     * is refused, so nobody is ever sent to pay for a date that has gone.
     *
     * @throws FormCheckoutRefused
     */
    public static function renewForPage(FormResponse $row, ?CarbonInterface $now = null): void
    {
        $now ??= Date::now();
        $reservation = self::of($row);

        if ($reservation === null) {
            return;
        }

        $refusal = self::renewalRefusal($row, $reservation, $now);

        if ($refusal !== null) {
            throw new FormCheckoutRefused($refusal);
        }

        $reservation->forceFill(['held_until' => self::cardHoldUntil($now)])->save();
    }

    /**
     * Whether "Return to payment" can still work for $row's date: it has no reservation,
     * its current page's hold has not run out, or a new page may still be opened. The
     * public status read asks this so the page never offers a button that can only fail.
     */
    public static function canStillPay(FormResponse $row, ?CarbonInterface $now = null): bool
    {
        $now ??= Date::now();
        $reservation = self::of($row);

        if ($reservation === null) {
            return true;
        }

        if (! $reservation->isHolding()) {
            return false;
        }

        if ($reservation->held_until !== null && $reservation->held_until->greaterThan($now)) {
            return true;
        }

        return self::renewalRefusal($row, $reservation, $now) === null;
    }

    /**
     * When $row may no longer keep its date unpaid: HOLD_LIMIT_MINUTES after it was
     * submitted (created, for a row written before submitted_at was).
     */
    public static function holdDeadline(FormResponse $row): CarbonInterface
    {
        return CarbonImmutable::instance($row->submitted_at ?? $row->created_at ?? Date::now())
            ->addMinutes(self::HOLD_LIMIT_MINUTES);
    }

    /**
     * Why a new payment page may not open for $row's reservation at $now, or null: the
     * date went to another payer (LOST), or the page's hold would run past the
     * registration's deadline (EXPIRED). A page that would outlive the deadline is
     * refused whole, rather than opened with a shorter hold, so a payer is never sent to
     * a page that can still take their money after their date has been offered to others.
     */
    private static function renewalRefusal(FormResponse $row, FormDateReservation $reservation, CarbonInterface $now): ?string
    {
        if (! $reservation->isHolding()) {
            return self::LOST;
        }

        return self::cardHoldUntil($now)->greaterThan(self::holdDeadline($row)) ? self::EXPIRED : null;
    }

    /**
     * An admin is restoring $row from cancelled, on the form row and then the response
     * row the caller has locked, in that order (the submit's order). A reservation its
     * cancellation released is claimed again, so a restored registration never shares its
     * date with the payer who took it; one still holding needs nothing.
     *
     * @throws FormDateTaken when another payer holds the date (the caller refuses the restore)
     */
    public static function reclaimForRestore(Form $lockedForm, FormResponse $row, ?CarbonInterface $now = null): void
    {
        $reservation = self::of($row);

        if ($reservation === null || $reservation->isHolding()) {
            return;
        }

        $date = $reservation->date();

        if (! self::claim($lockedForm, $date, $now)) {
            throw new FormDateTaken();
        }

        try {
            $reservation->forceFill(['holding_on' => $date, 'released_at' => null, 'release_reason' => null])->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! self::isHoldingIndexViolation($e)) {
                throw $e;
            }

            throw new FormDateTaken();
        }
    }

    /** The reservation a response made, or null. */
    public static function of(FormResponse $row): ?FormDateReservation
    {
        return FormDateReservation::withoutMasjidScope()
            ->where('form_id', $row->form_id)
            ->where('form_response_id', $row->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * A payment has just been recorded on $row. When its hold had already lapsed and
     * another payer took the date, the money is kept (it always is) and the platform log
     * is warned, by ids only. The organisation and the payer hear it from the emails
     * settle() sends next (FormNotifier's lost date), and the admin board and the
     * responses screen show the row as a conflict: the organisation refunds or offers
     * another date.
     */
    public static function notePaidAfterLosingDate(FormResponse $row): void
    {
        try {
            $reservation = self::of($row);

            if ($reservation !== null && ! $reservation->isHolding()) {
                Log::warning('A form payment was recorded after its reserved date went to another payer; refund it or offer another date.', [
                    'masjid_id' => $row->masjid_id,
                    'form_id' => $row->form_id,
                    'form_response_id' => $row->getKey(),
                    'reservation_id' => $reservation->getKey(),
                ]);
            }
        } catch (\Throwable $e) {
            // Called from the webhook after the payment is recorded: a 500 here would make
            // Stripe retry an event whose work is already done.
            Log::error('Could not check a paid form response for a lost date.', [
                'form_response_id' => $row->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What the admin sees about one reservation, in one word:
     *
     *   reserved   paid, or on a form that takes no payment: the date is theirs
     *   held       unpaid, and still protecting its date (paying the office, or a card
     *              page that can still be paid)
     *   lapsed     an unpaid card registration past its hold: offered again
     *   cancelled  the registration was cancelled: offered again
     *   released   given up to another payer (released_at / release_reason say when, why)
     */
    public static function stateOf(FormDateReservation $reservation, ?FormResponse $row, ?CarbonInterface $now = null): string
    {
        if (! $reservation->isHolding()) {
            return 'released';
        }

        if ($row === null) {
            return 'lapsed';
        }

        $yields = $reservation->yieldsTo($row, $now ?? Date::now());

        if ($yields !== null) {
            return $yields;
        }

        return $row->isPaid() || ! $row->hasMoneyLeg() ? 'reserved' : 'held';
    }

    /**
     * The admin's view of a form's dates: every listed date (and any date reserved that
     * is no longer listed) with who holds it and in what state, plus the conflicts
     * (isConflict()): live registrations whose date went to someone else.
     *
     * @return array{dates: array<int,array<string,mixed>>, conflicts: array<int,array<string,mixed>>}
     */
    public static function board(Form $form, ?CarbonInterface $now = null): array
    {
        $now ??= Date::now();
        $today = self::today($form, $now);
        $listed = array_fill_keys($form->reservation()['dates'] ?? [], true);

        $all = FormDateReservation::withoutMasjidScope()
            ->where('form_id', $form->id)
            ->with('response')
            ->orderBy('id')
            ->get();

        $holding = [];

        foreach ($all as $reservation) {
            if ($reservation->isHolding()) {
                $holding[$reservation->holding_on] = $reservation;
            }
        }

        $dates = array_keys($listed + $holding);
        sort($dates, SORT_STRING);

        $rows = array_map(function (string $date) use ($holding, $listed, $today, $now): array {
            $reservation = $holding[$date] ?? null;

            return [
                'date' => $date,
                'label' => self::label($date),
                'listed' => isset($listed[$date]),
                'past' => $date < $today,
                'state' => $reservation === null ? 'open' : self::stateOf($reservation, $reservation->response, $now),
                'reservation' => $reservation === null ? null : self::describe($reservation),
            ];
        }, $dates);

        $conflicts = $all
            ->filter(fn (FormDateReservation $r) => self::isConflict($r))
            ->map(fn (FormDateReservation $r) => ['date' => $r->date(), 'label' => self::label($r->date())] + self::describe($r))
            ->values()
            ->all();

        return ['dates' => $rows, 'conflicts' => $conflicts];
    }

    /**
     * How many conflicts the form has (isConflict()), for the responses screen, which
     * shows the count on the reservations board while the board itself is folded away.
     */
    public static function conflictCount(Form $form): int
    {
        return FormDateReservation::withoutMasjidScope()
            ->where('form_id', $form->id)
            ->whereNull('holding_on')
            ->with('response')
            ->get()
            ->filter(fn (FormDateReservation $r) => self::isConflict($r))
            ->count();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A registration that is not cancelled but no longer holds the date it asked for, and
     * that someone must act on: it was PAID after the date went to another payer (refund
     * or rebook), or it was cancelled, lost its date to the next payer, and was then
     * restored by some way round reclaimForRestore(). An unpaid card registration whose
     * page ran out is not one: it simply cannot pay any more.
     */
    private static function isConflict(FormDateReservation $reservation): bool
    {
        $row = $reservation->response;

        return ! $reservation->isHolding()
            && $row !== null
            && ! $row->isCancelled()
            && ($row->isPaid() || $reservation->release_reason === FormDateReservation::RELEASED_CANCELLED);
    }

    /** @return array<string,mixed> */
    private static function describe(FormDateReservation $reservation): array
    {
        $row = $reservation->response;

        return [
            'id' => $reservation->id,
            'response_id' => $reservation->form_response_id,
            'respondent_name' => $row?->respondent_name,
            'price_label' => $row?->price_label,
            'payment_method' => $row?->payment_method,
            'payment_status' => $row?->payment_status,
            'held_until' => optional($reservation->held_until)->toIso8601String(),
            'released_at' => optional($reservation->released_at)->toIso8601String(),
            'release_reason' => $reservation->release_reason,
        ];
    }

    /** @return \Illuminate\Support\Collection<int,FormDateReservation> */
    private static function holding(Form $form)
    {
        return FormDateReservation::withoutMasjidScope()
            ->where('form_id', $form->id)
            ->whereNotNull('holding_on')
            ->with('response')
            ->get();
    }

    /** Today on the organisation's own clock, as the school calendar reads it. */
    private static function today(Form $form, CarbonInterface $now): string
    {
        $masjid = $form->relationLoaded('masjid') ? $form->masjid : Masjid::find($form->masjid_id);
        $zone = $masjid !== null ? FormStaffCodesController::timezoneFor($masjid)['name'] : 'UTC';

        return CarbonImmutable::instance($now)->setTimezone($zone)->toDateString();
    }

    /**
     * Whether a unique violation is the holding index. The driver's own message, as
     * FormSubmissionsController::isClientKeyViolation() reads it.
     */
    private static function isHoldingIndexViolation(UniqueConstraintViolationException $e): bool
    {
        $driver = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driver, 'form_date_resv_form_holding_unique')      // MySQL names the index
            || str_contains($driver, 'form_date_reservations.holding_on');      // SQLite names the columns
    }
}
