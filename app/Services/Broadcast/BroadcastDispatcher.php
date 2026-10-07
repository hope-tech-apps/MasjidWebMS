<?php

namespace App\Services\Broadcast;

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use App\Services\Broadcast\Channels\AnnouncementChannel;
use App\Services\Broadcast\Channels\EmailChannel;
use App\Services\Broadcast\Channels\PushChannel;
use App\Services\Broadcast\Channels\SignageChannel;
use App\Services\Broadcast\Channels\SmsChannel;
use App\Support\Errors;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fans one composed broadcast out to its selected channels (T-008).
 *
 * ## THE RULE: a failing channel never rolls back a successful one
 *
 * This is the single most important behaviour in the slice, so it is stated
 * where the code lives rather than only in a doc.
 *
 * The fan-out is deliberately NOT wrapped in a database transaction, and a
 * driver that throws is caught, recorded, and left behind while the loop
 * continues. The reason is that these channels are not undoable. A push
 * notification accepted by OneSignal is on ten thousand lock screens; an email
 * accepted by the relay is in somebody's inbox; an announcement is already being
 * served from the feed. If a later channel then failed and we "rolled back",
 * the only thing that would actually roll back is OUR RECORD of what happened —
 * leaving a database that claims nothing was sent while congregants are reading
 * the message. The admin, seeing a clean failure, sends it again. That is a
 * worse outcome than any partial send, and it is exactly the failure mode a
 * naive `DB::transaction(fn () => $this->fanOut(...))` produces.
 *
 * So: each delivery row is its own committed statement, and a partial send is a
 * FIRST-CLASS, VISIBLE state (`broadcasts.status = partial`) rather than an
 * error. Retrying is then a per-channel decision an admin can make with the
 * delivery rows in front of them, instead of a guess.
 *
 * The one thing that IS atomic is the composition — creating the broadcast and
 * its pending delivery rows — because that happens before anything has left the
 * building and a half-written record helps nobody. See BroadcastComposer.
 *
 * ## Ordering
 *
 * Channels run in DRIVERS order, which is deliberate: the announcements feed and
 * the signage board are local, cheap and reversible-ish, so they go first and a
 * flaky third-party channel cannot delay the surfaces a congregant might already
 * be looking at. Nothing depends on the order, though — a driver may not read a
 * sibling's outcome (BroadcastChannelDriver).
 *
 * ## Tenant binding
 *
 * The dispatcher binds TenantContext to the broadcast's masjid for the whole
 * fan-out, the ImpactMetrics idiom. On the admin route it is already bound and
 * this is a no-op; from a queued job it is essential, because
 * .claude/rules/tenant-scoping.md guarantees every job starts UNBOUND and a
 * driver would otherwise resolve an audience across every tenant. A DIFFERENT
 * tenant already being bound is a programmer error and throws rather than being
 * silently overridden.
 */
class BroadcastDispatcher
{
    /**
     * Channel -> driver class. Registering a driver here is the entire cost of
     * adding a channel (see BroadcastChannelDriver for the seam). SMS proved
     * that in T-009: this line and an enum case were the whole composer-side
     * change — everything else it needed was consent infrastructure that had to
     * exist beneath the channel, not inside it (.claude/rules/broadcasts.md).
     *
     * @var array<string, class-string<BroadcastChannelDriver>>
     */
    public const DRIVERS = [
        'announcement' => AnnouncementChannel::class,
        'signage' => SignageChannel::class,
        'push' => PushChannel::class,
        'email' => EmailChannel::class,
        // Last on purpose (T-009): it is the only channel that can refuse for a
        // reason outside this request — an unregistered A2P 10DLC sender or an
        // unconfigured provider — and the surfaces a congregant may already be
        // looking at should not wait behind it.
        'sms' => SmsChannel::class,
    ];

    /** 15 minutes: 3 × the job timeout, and 2.5 × the database queue lease. */
    public const STALE_AFTER_SECONDS = 900;

    /** @var array<int, string> Process-local ownership of the OS locks below. */
    private static array $activeSends = [];

    /**
     * Deliver every channel this broadcast selected.
     *
     * Safe to call twice: a delivery already marked `sent` or `skipped` is left
     * alone, so a retry after a partial send re-attempts only what failed and
     * can never double-send a channel that already went out.
     */
    public function dispatch(Broadcast $broadcast, ?string $claimToken = null): Broadcast
    {
        $masjid = Masjid::findOrFail($broadcast->masjid_id);
        $claimToken ??= (string) Str::uuid();

        return $this->withTenant((int) $broadcast->masjid_id, function () use ($broadcast, $masjid, $claimToken): Broadcast {
            $result = $this->withSendLock($broadcast->id, $claimToken, false, function () use ($broadcast, $masjid, $claimToken): Broadcast {
                // Commit the claim BEFORE any driver. Cancellation and recovery
                // use this same parent lock; the fan-out never holds a transaction.
                $claimed = DB::transaction(function () use (&$broadcast, $claimToken): bool {
                    $broadcast = Broadcast::query()->lockForUpdate()->findOrFail($broadcast->id);
                    $deliveries = $broadcast->deliveries()->lockForUpdate()->get();

                    if (in_array($broadcast->status, [Broadcast::STATUS_CANCELLED, Broadcast::STATUS_SENDING, Broadcast::STATUS_INTERRUPTED], true)
                        || $broadcast->send_recovered_at !== null
                        || $deliveries->contains(fn ($delivery) => in_array($delivery->status, [
                            BroadcastDelivery::STATUS_SENDING, BroadcastDelivery::STATUS_INTERRUPTED, BroadcastDelivery::STATUS_NOT_SENT,
                        ], true))) {
                        return false;
                    }

                    $broadcast->forceFill([
                        'status' => Broadcast::STATUS_SENDING,
                        'sending_started_at' => Carbon::now(),
                        'send_claim_token' => $claimToken,
                    ])->save();

                    return true;
                });

                if (! $claimed) {
                    return $broadcast->load('deliveries');
                }

                $pending = $broadcast->deliveries()
                    ->whereIn('status', [BroadcastDelivery::STATUS_PENDING, BroadcastDelivery::STATUS_FAILED])
                    ->get()->keyBy('channel');

                foreach (array_keys(self::DRIVERS) as $channelValue) {
                    $delivery = $pending->get($channelValue);
                    if (! $delivery) {
                        continue;
                    }

                    $started = DB::transaction(function () use ($broadcast, $delivery, $claimToken): bool {
                        $fresh = Broadcast::query()->lockForUpdate()->findOrFail($broadcast->id);
                        if ($fresh->status !== Broadcast::STATUS_SENDING || $fresh->send_claim_token !== $claimToken) {
                            return false;
                        }
                        // This committed state survives a kill inside the driver,
                        // even when the external send's result never reaches us.
                        $delivery->forceFill(['status' => BroadcastDelivery::STATUS_SENDING])->save();

                        return true;
                    });
                    if (! $started) {
                        break;
                    }
                    $this->deliverOne($broadcast, $masjid, $delivery);
                }

                DB::transaction(function () use ($broadcast, $claimToken): void {
                    $fresh = Broadcast::query()->lockForUpdate()->findOrFail($broadcast->id);
                    if ($fresh->status === Broadcast::STATUS_SENDING && $fresh->send_claim_token === $claimToken) {
                        $fresh->forceFill([
                            'status' => $this->rollupStatus($fresh),
                            'dispatched_at' => Carbon::now(),
                        ])->save();
                    }
                });

                return $broadcast->fresh()->load('deliveries');
            });

            return $result ?? $broadcast->fresh()->load('deliveries');
        });
    }

    /**
     * Recover only a still-sending claim. A callback names its own claim; the
     * sweep supplies a minimum age instead. Neither path ever runs a driver.
     */
    public function settleInterrupted(int $broadcastId, ?string $claimToken = null, ?Carbon $staleBefore = null): bool
    {
        return $this->withSendLock($broadcastId, $claimToken, true, function () use ($broadcastId, $claimToken, $staleBefore): bool {
            return DB::transaction(function () use ($broadcastId, $claimToken, $staleBefore): bool {
                // System recovery may run unbound, or after a tenant was archived.
                $broadcast = Broadcast::withoutMasjidScope()->lockForUpdate()->find($broadcastId);
                if (! $broadcast || $broadcast->status !== Broadcast::STATUS_SENDING) {
                    return false;
                }
                if ($broadcast->sending_started_at !== null && ! is_file(storage_path('framework/broadcast-send-locks/' . $broadcastId . '.lock'))) {
                    // A new claim always created this inode before claiming. Its
                    // disappearance removes our liveness proof: fail closed.
                    return false;
                }
                if ($claimToken !== null && $broadcast->send_claim_token !== $claimToken) {
                    return false;
                }
                $startedAt = $broadcast->sending_started_at ?? $broadcast->updated_at;
                if ($staleBefore !== null && (! $startedAt || ! $startedAt->lt($staleBefore))) {
                    return false;
                }
                if ($claimToken === null && $staleBefore === null) {
                    return false;
                }

                $deliveries = BroadcastDelivery::withoutMasjidScope()
                    ->where('broadcast_id', $broadcast->id)->lockForUpdate()->get();
                foreach ($deliveries as $delivery) {
                    if ($delivery->status === BroadcastDelivery::STATUS_SENDING
                        || ($broadcast->sending_started_at === null && $delivery->status === BroadcastDelivery::STATUS_PENDING)) {
                        $delivery->forceFill([
                            'status' => BroadcastDelivery::STATUS_INTERRUPTED,
                            'note' => 'Sending stopped before the outcome was recorded. This channel may have sent some or all of the message. Check it before composing a replacement.',
                        ])->save();
                    } elseif ($delivery->status === BroadcastDelivery::STATUS_PENDING) {
                        $delivery->forceFill([
                            'status' => BroadcastDelivery::STATUS_NOT_SENT,
                            'note' => 'This channel did not start before sending stopped.',
                        ])->save();
                    }
                }
                $broadcast->forceFill(['send_recovered_at' => Carbon::now()]);
                $broadcast->forceFill([
                    'status' => $this->rollupStatus($broadcast),
                    // Even an all-finished failed/partial rollup must not reopen
                    // a claim recovered after its worker ended.
                    'send_recovered_at' => $broadcast->send_recovered_at,
                    'dispatched_at' => Carbon::now(),
                ])->save();

                return true;
            });
        }) ?? false;
    }

    /**
     * The application, queue and scheduler run on one host. A non-expiring OS
     * lock protects live synchronous sends too; SIGKILL releases it without a
     * callback. Keep these files outside cache/data and never unlink a lock:
     * replacing its inode would let another process send while it is held.
     */
    private function withSendLock(int $broadcastId, ?string $claimToken, bool $recovering, callable $callback): mixed
    {
        if (isset(self::$activeSends[$broadcastId])) {
            return $recovering && $claimToken !== null && self::$activeSends[$broadcastId] === $claimToken ? $callback() : null;
        }
        $directory = storage_path('framework/broadcast-send-locks');
        $file = $directory . '/' . $broadcastId . '.lock';
        if ($recovering && ! is_file($file)) {
            // Legacy claims predate process locks. Recovery never creates lock
            // files as the scheduler's user (which may differ from PHP-FPM).
            return $callback();
        }
        if (! $recovering && ! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the broadcast send lock directory.');
        }
        // flock() accepts a read-only descriptor: cron can inspect a lock file
        // created by www-data without rewriting it or changing its ownership.
        $handle = fopen($file, $recovering ? 'r' : 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open the broadcast send lock.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }
            self::$activeSends[$broadcastId] = $claimToken ?? '';
            try {
                return $callback();
            } finally {
                unset(self::$activeSends[$broadcastId]);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Run ONE channel, and absorb anything it throws.
     *
     * The try/catch is the isolation boundary. It is intentionally as wide as
     * `Throwable` — a TypeError from a vendor SDK ends this channel and only
     * this channel, exactly like a deliberate RuntimeException would.
     */
    private function deliverOne(Broadcast $broadcast, Masjid $masjid, BroadcastDelivery $delivery): void
    {
        $channel = BroadcastChannel::tryFrom((string) $delivery->channel);

        if (! $channel || ! isset(self::DRIVERS[$channel->value])) {
            // A channel value with no driver is a deployment mistake (a row
            // written by a newer version). Record it and keep going rather than
            // aborting the sends that DO have drivers.
            $delivery->forceFill([
                'status' => BroadcastDelivery::STATUS_FAILED,
                'error' => 'No delivery driver is registered for channel "' . $delivery->channel . '".',
            ])->save();

            return;
        }

        try {
            /** @var BroadcastChannelDriver $driver */
            $driver = app(self::DRIVERS[$channel->value]);

            $result = $driver->deliver($broadcast, $masjid);

            $delivery->newQuery()->whereKey($delivery->id)->where('status', BroadcastDelivery::STATUS_SENDING)->update([
                'status' => $result->status,
                'target_count' => $result->targetCount,
                'reference_id' => $result->referenceId,
                'reference' => $result->reference,
                'note' => $result->note,
                'error' => null,
                'delivered_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Broadcast channel failed; other channels are unaffected.', [
                'broadcast_id' => $broadcast->id,
                'masjid_id' => $broadcast->masjid_id,
                'channel' => $delivery->channel,
                'error' => $e->getMessage(),
            ]);

            // Errors::publicMessage keeps provider internals out of a column the
            // admin SPA renders, while still logging the full exception.
            $delivery->newQuery()->whereKey($delivery->id)->where('status', BroadcastDelivery::STATUS_SENDING)->update([
                'status' => BroadcastDelivery::STATUS_FAILED,
                'error' => Errors::publicMessage($e, 'This channel could not deliver the message.'),
                'delivered_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Roll the delivery rows up into the one word a list screen shows.
     *
     * Unknown and not-started outcomes are terminal interruption, never success.
     * `skipped` counts as neither success nor failure: a broadcast whose only
     * outcome was "nobody to send to" is `sent` (nothing went wrong), while any
     * mix of success and failure is `partial` — the state that exists precisely
     * because channels are not rolled back together.
     */
    private function rollupStatus(Broadcast $broadcast): string
    {
        $statuses = BroadcastDelivery::withoutMasjidScope()->where('broadcast_id', $broadcast->id)->pluck('status');

        if ($statuses->contains(fn ($status) => in_array($status, [
            BroadcastDelivery::STATUS_SENDING, BroadcastDelivery::STATUS_INTERRUPTED, BroadcastDelivery::STATUS_NOT_SENT,
        ], true))) {
            return Broadcast::STATUS_INTERRUPTED;
        }

        if ($statuses->isEmpty()) {
            return $broadcast->send_recovered_at !== null ? Broadcast::STATUS_INTERRUPTED : Broadcast::STATUS_PENDING;
        }

        $failed = $statuses->filter(fn ($s) => $s === BroadcastDelivery::STATUS_FAILED)->count();
        $succeeded = $statuses->filter(
            fn ($s) => $s === BroadcastDelivery::STATUS_SENT || $s === BroadcastDelivery::STATUS_SKIPPED
        )->count();

        if ($failed === 0) {
            return $broadcast->send_recovered_at !== null ? Broadcast::STATUS_INTERRUPTED : Broadcast::STATUS_SENT;
        }

        if ($succeeded > 0) {
            return Broadcast::STATUS_PARTIAL;
        }

        return $broadcast->send_recovered_at !== null ? Broadcast::STATUS_INTERRUPTED : Broadcast::STATUS_FAILED;
    }

    /**
     * Bind the tenant for the duration of the fan-out and restore it after.
     *
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function withTenant(int $masjidId, callable $callback): mixed
    {
        $tenant = app(TenantContext::class);
        $previous = $tenant->get();

        if ($previous !== null && $previous !== $masjidId) {
            throw new RuntimeException(
                'Refusing to dispatch broadcast for masjid ' . $masjidId
                . ' while tenant ' . $previous . ' is bound.'
            );
        }

        $tenant->set($masjidId);

        try {
            return $callback();
        } finally {
            $previous === null ? $tenant->forgetTenant() : $tenant->set($previous);
        }
    }
}
