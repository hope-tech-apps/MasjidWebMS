<?php

namespace App\Console\Commands;

use App\Models\Registration;
use App\Services\Registrations\RegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The expired-checkout reaper (T-006f, docs/t006-registration-billing-design.md).
 *
 * Reserve-at-pending means a paid registration HOLDS a real seat from the
 * instant of intake — `registration_count` is already incremented while the
 * donor is still on Stripe's hosted page. That hold is bounded by
 * `checkout_expires_at`, and `checkout.session.expired` normally gives it back.
 * This command exists for the case where THAT EVENT NEVER ARRIVES: a webhook
 * endpoint that was down long enough to exhaust Stripe's retries, a Session
 * created but never opened, a delivery lost in transit, clock skew. Without a
 * sweep, one abandoned tab holds a seat forever and the offering silently
 * shrinks.
 *
 * IT RELEASES NOTHING ITSELF. Every seat goes back through
 * RegistrationService::releaseSeat() — the ONE seat-release seam
 * (.claude/rules/registration-billing-data.md), which is pending-only,
 * idempotent, and decrements `registration_count` under the same
 * lockForUpdate discipline intake increments it with. A second writer of that
 * guarded counter is exactly the bug this slice is supposed to prevent, so
 * there is deliberately no decrement in this file.
 *
 * CONVERGENCE WITH THE WEBHOOK, in both orders:
 *  - webhook first — `checkout.session.expired` already cancelled the seat and
 *    nulled the window, so the row no longer matches this sweep's filter at
 *    all; and a payment that landed instead moved the row to paid/confirmed
 *    with `checkout_expires_at` nulled by the settlement, which the filter also
 *    excludes.
 *  - reaper first — the late `checkout.session.expired` re-enters releaseSeat,
 *    finds a non-pending seat, and returns without touching the counter.
 * Either way `registration_count` moves exactly once. The filter is belt, the
 * seam's pending-only guard is braces, and the row lock inside the seam is what
 * makes the two safe to race.
 *
 * THE GRACE MARGIN is the whole reason this is not simply
 * `where checkout_expires_at < now()`. A donor can pay in the last second of
 * their window; Stripe then has to deliver `checkout.session.completed` /
 * `payment_intent.succeeded`, and delivery can lag or need a retry. Reaping in
 * that gap would cancel a seat somebody has already paid for — recoverable
 * only by a human refunding from the org's own Stripe dashboard (the org is
 * merchant of record). Holding a dead seat 15 minutes longer costs a slightly
 * later waitlist opening. The costs are wildly asymmetric, so the margin is
 * generous: `services.stripe.registration_reaper_grace_minutes`, default 15
 * (see config/services.php for the full justification). `--grace=` overrides it
 * for a one-off run.
 *
 * Runs UNBOUND (no tenant on a console request), so the sweep crosses
 * organizations EXPLICITLY via withoutMasjidScope() rather than relying on the
 * absence of a binding — see .claude/rules/tenant-scoping.md. `--masjid=`
 * narrows it to one organization, and a narrowed run can never touch another
 * tenant's registration.
 *
 * SCHEDULED EVERY FIFTEEN MINUTES in routes/console.php. It used to say "not
 * scheduled by default; the cadence is an operator decision", which left a
 * backstop that never ran — and a backstop that never runs is not a backstop,
 * it is a comment. `--grace=` and `--masjid=` still exist for a one-off run.
 *
 * The cadence is bounded below by the grace margin, not by taste: sweeping more
 * often than the margin cannot reclaim anything sooner, because nothing is
 * eligible until `checkout_expires_at + grace` has passed anyway. Fifteen
 * minutes matches the default margin, so a genuinely abandoned seat comes back
 * within about half an hour of expiring instead of never.
 */
class ReapExpiredCheckouts extends Command
{
    /**
     * Minutes past `checkout_expires_at` before a held seat may be swept.
     * Config-tunable; see the class docblock and config/services.php.
     */
    public const DEFAULT_GRACE_MINUTES = 15;

    protected $signature = 'registrations:reap-expired
                            {--grace= : Minutes past checkout_expires_at before a seat may be swept (default: config)}
                            {--masjid= : Limit the sweep to one organization}
                            {--dry-run : Report what would be released without releasing anything}';

    protected $description = 'Release seats held by pending registrations whose Stripe Checkout window expired without payment (the backstop for a checkout.session.expired that never arrived).';

    public function __construct(private RegistrationService $registrations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $graceOption = $this->option('grace');
        // A negative grace would sweep INTO the future — clamped to 0, which is
        // "the instant the window closed" and the earliest defensible deadline.
        $grace = $graceOption === null ? $this->graceMinutes() : max(0, (int) $graceOption);
        $masjidId = $this->option('masjid');

        // `!== null`, NOT truthiness. `--masjid=0` is a string "0", which is
        // FALSY in PHP, so `when($masjidId, …)` skipped the narrowing entirely
        // and swept EVERY tenant — on a command that force-deletes minors'
        // records. Verified against two seeded tenants before the fix: both had
        // their due rows deleted. `--masjid=abc` was always safe (truthy, casts
        // to 0, matches nothing), which is precisely what made `0` the sharp
        // edge: the one spelling that silently widens instead of narrowing.
        $narrowToMasjid = $masjidId !== null;
        $dryRun = (bool) $this->option('dry-run');

        $deadline = now()->subMinutes($grace);

        $query = Registration::withoutMasjidScope()
            ->checkoutExpiredBefore($deadline)
            ->when($narrowToMasjid, fn ($q) => $q->where('masjid_id', (int) $masjidId))
            ->orderBy('id');

        $swept = 0;
        $released = 0;
        $settled = 0;

        // Chunked by id so a large sweep never holds every stale hold in
        // memory, and so rows dropping OUT of the filter underneath the cursor
        // (releaseSeat cancels them) cannot make it skip — chunkById pages on
        // `id > last`, never on offset.
        $query->chunkById(200, function ($due) use (&$swept, &$released, &$settled, $dryRun) {
            foreach ($due as $registration) {
                $swept++;

                if ($dryRun) {
                    // The filter IS releaseSeat's precondition (pending +
                    // awaiting), so every swept row is one the real run would
                    // release. Nothing is written.
                    $released++;

                    continue;
                }

                $result = $this->registrations->releaseSeat($registration);

                // Counted from the OUTCOME, not from who caused it: a webhook
                // that settled or cancelled this row between the query and the
                // release lands here as `settled`/`released` respectively. The
                // counter moved exactly once either way — that convergence is
                // the design, not a race to report.
                if ($result->status === Registration::STATUS_CANCELLED) {
                    $released++;
                } else {
                    $settled++;
                }
            }
        });

        $this->info(sprintf(
            '%s %d expired checkout(s): %d seat(s) released%s (grace %d min)%s.',
            $dryRun ? 'Would reap' : 'Reaped',
            $swept,
            $released,
            $settled > 0 ? sprintf(', %d settled by the payment path', $settled) : '',
            $grace,
            $masjidId ? " for masjid {$masjidId}" : ''
        ));

        // Now that this runs on a schedule, stdout goes nowhere: `schedule:run`
        // discards it. A reaper that quietly stopped finding stale holds looks
        // identical to one with nothing to do, and the symptom — an offering
        // that slowly runs out of seats nobody took — surfaces weeks later as a
        // complaint. Always emitted, zeros included.
        Log::info('Expired-checkout reap completed.', [
            'dry_run' => $dryRun,
            'grace_minutes' => $grace,
            'deadline' => $deadline->toIso8601String(),
            'masjid_id' => $masjidId !== null ? (int) $masjidId : null,
            'swept' => $swept,
            'released' => $released,
            'settled' => $settled,
        ]);

        return self::SUCCESS;
    }

    private function graceMinutes(): int
    {
        return max(0, (int) config(
            'services.stripe.registration_reaper_grace_minutes',
            self::DEFAULT_GRACE_MINUTES
        ));
    }
}
