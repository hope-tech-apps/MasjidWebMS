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
 * page ("Return to payment") renews it, under the response's lock (renewForPage()).
 *
 * After that the hold has LAPSED. It is not deleted by a clock: the date is offered
 * again, and only the next payer who asks for it releases it (reason `lapsed`). Until
 * then a late payment still finds its date. A payment that lands after its date was
 * taken is recorded (money is never refused) and shown to the admin as a conflict
 * (board(), and a warning log from notePaidAfterLosingDate()): the organisation refunds
 * or rebooks by hand.
 *
 * A family paying the office, a staff-code cash entry and a paid row never lapse. A row
 * an admin cancels stops protecting its date at once, and is released (reason
 * `cancelled`) when the next payer asks for it; restoring the registration before then
 * gives the date straight back. Deleting a response deletes its reservation.
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

    /** Refused under the lock: another payer holds the date. */
    public const TAKEN = 'That date has just been reserved by someone else. Please choose another date.';

    /** "Return to payment" on a registration whose lapsed hold another payer has since taken. */
    public const LOST = 'The date you chose was released because payment was not completed in time, and someone else has since reserved it. Please register again and choose another date.';

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
        $reservation = self::of($row);

        if ($reservation === null) {
            return;
        }

        if (! $reservation->isHolding()) {
            throw new FormCheckoutRefused(self::LOST);
        }

        $reservation->forceFill(['held_until' => self::cardHoldUntil($now ?? Date::now())])->save();
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
     * another payer took the date, the money is kept (it always is) and the operator is
     * warned, by ids only: the organisation refunds or offers another date. The admin
     * board shows the same row as a conflict.
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
     * is no longer listed) with who holds it and in what state, plus the conflicts:
     * registrations that were PAID after their date went to someone else.
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
            ->filter(fn (FormDateReservation $r) => ! $r->isHolding() && $r->response !== null && $r->response->isPaid() && ! $r->response->isCancelled())
            ->map(fn (FormDateReservation $r) => ['date' => $r->date(), 'label' => self::label($r->date())] + self::describe($r))
            ->values()
            ->all();

        return ['dates' => $rows, 'conflicts' => $conflicts];
    }

    // ---------------------------------------------------------------- helpers

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
