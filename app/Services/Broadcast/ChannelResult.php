<?php

namespace App\Services\Broadcast;

/**
 * What a channel driver reports back after it ran (T-008).
 *
 * Deliberately has NO "failed" constructor. A driver signals failure by
 * THROWING — that way a channel cannot half-fail silently, and an unexpected
 * exception from deep inside a vendor SDK is handled by exactly the same code
 * path as a deliberate one. The dispatcher catches, records, and moves to the
 * next channel; see BroadcastDispatcher for why it must never do more than that.
 */
final class ChannelResult
{
    private function __construct(
        public readonly string $status,
        public readonly int $targetCount,
        public readonly ?int $referenceId,
        public readonly ?string $reference,
        public readonly ?string $note,
    ) {
    }

    /**
     * The channel accepted the message.
     *
     * @param  int  $targetCount  Recipients addressed. Zero is legitimate on a
     *                            PULL channel (signage) and on the announcements
     *                            feed — neither is "sent to" anybody.
     * @param  int|null  $referenceId  Row this channel created in its own table.
     * @param  string|null  $reference  External provider id, when one exists.
     */
    public static function sent(
        int $targetCount = 0,
        ?int $referenceId = null,
        ?string $reference = null,
        ?string $note = null,
    ): self {
        return new self(\App\Models\BroadcastDelivery::STATUS_SENT, $targetCount, $referenceId, $reference, $note);
    }

    /**
     * There was nothing to deliver to. NOT a failure.
     *
     * A masjid whose congregation has never installed the app has no device to
     * push to; an audience in which nobody recorded an email address has no
     * inbox to reach. The admin still needs to SEE that, which is why it is a
     * recorded outcome with a mandatory reason rather than a silent success.
     */
    public static function skipped(string $note, ?int $referenceId = null): self
    {
        return new self(\App\Models\BroadcastDelivery::STATUS_SKIPPED, 0, $referenceId, null, $note);
    }
}
