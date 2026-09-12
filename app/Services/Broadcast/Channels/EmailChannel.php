<?php

namespace App\Services\Broadcast\Channels;

use App\Enums\BroadcastChannel;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\ChannelResult;
use App\Services\Broadcast\EmailSuppressionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * The email channel (T-008).
 *
 * Uses the application's existing mail path unchanged — a `Mailable` sent
 * through `Mail::to(...)`, delivered by whatever mailer config/mail.php names
 * (Resend in production). No new transport, no new credentials.
 *
 * ## Failure is isolated TWICE
 *
 * Between channels, by the dispatcher: a mail outage cannot un-send a push.
 * And WITHIN this channel, per recipient: one malformed address out of six
 * hundred must not cost the other five hundred and ninety-nine their message.
 * Each send is individually guarded and counted, so `target_count` reports
 * inboxes actually addressed rather than rows in the directory. The channel only
 * reports failure when EVERY recipient failed, which is the signature of a real
 * outage rather than one bad row — the same judgement FormNotifier makes about
 * never letting mail cost a registration.
 *
 * ## Recipients come from the CRM, which is why the endpoint gates this channel
 *
 * Email is the only channel that reads `contacts`. The composer endpoint
 * therefore refuses a send that selects email unless the masjid's CRM is enabled
 * and the caller holds `view contacts` — checked UP FRONT, before anything goes
 * out, because an authorization answer must be all-or-nothing rather than
 * something the admin discovers from a delivery row after the push has landed.
 *
 * ## Every message carries a working unsubscribe link (T-042c)
 *
 * A per-recipient link is minted HERE, before the send, and handed to the
 * Mailable as two plain strings: the GET page for the footer and the POST for
 * the `List-Unsubscribe` header (RFC 8058 one-click, which Gmail and Yahoo have
 * required of bulk senders since February 2024). It is minted here and never
 * inside `BroadcastMail`, because that Mailable is `ShouldQueue`: a serialized
 * job that builds its own URL builds it from whatever host the worker believes
 * it is on.
 *
 * The link is not this class's compliance mechanism, only its delivery of one.
 * The opt-out is HONOURED in `BroadcastAudienceResolver::emailAudience()`, which
 * is why a suppressed person never appears in the loop below and never appears
 * in the count reported to the admin.
 */
class EmailChannel implements BroadcastChannelDriver
{
    public function __construct(
        private readonly BroadcastAudienceResolver $audience,
        private readonly EmailSuppressionService $suppression,
    ) {
    }

    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::EMAIL;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        $audience = $this->audience->emailAudience($broadcast);

        if ($audience->isEmpty()) {
            return ChannelResult::skipped($audience->skipNote());
        }

        $sent = 0;
        $failed = 0;
        $lastError = null;

        foreach ($audience->recipients as $contact) {
            try {
                // Per recipient, because it carries the address it was sent to.
                $unsubscribe = $this->suppression->urls(
                    (int) $masjid->id,
                    (string) $contact->email,
                    (int) $broadcast->id,
                );

                Mail::to($contact->email)->send(new BroadcastMail(
                    orgName: (string) $masjid->name,
                    title: (string) $broadcast->title,
                    body: (string) $broadcast->body,
                    link: $broadcast->link,
                    imageUrl: $broadcast->imageUrl(),
                    recipientName: trim((string) $contact->first_name) ?: null,
                    orgEmail: $masjid->email,
                    unsubscribeUrl: $unsubscribe['page'],
                    unsubscribeOneClickUrl: $unsubscribe['one_click'],
                ));

                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();

                // One address is a data problem, not an outage. Logged so it is
                // recoverable, swallowed so it is not contagious.
                Log::warning('Broadcast email failed for one recipient.', [
                    'broadcast_id' => $broadcast->id,
                    'masjid_id' => $masjid->id,
                    'contact_id' => $contact->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sent === 0) {
            // Every single recipient failed — that is an outage, and the
            // dispatcher must record this channel as failed. It still does not
            // touch any other channel's outcome.
            throw new RuntimeException(
                'Email delivery failed for all ' . $failed . ' recipient(s). Last error: ' . $lastError
            );
        }

        $note = 'Sent to ' . $sent . ' recipient(s).';
        if ($failed > 0) {
            $note .= ' ' . $failed . ' address(es) could not be delivered to; see the log.';
        }
        // Without this line a shrinking count reads as lost data rather than as
        // the organisation's own unsubscribe rate.
        if ($audience->suppressed > 0) {
            $note .= ' ' . $audience->exclusionSummary();
        }

        return ChannelResult::sent(targetCount: $sent, note: $note);
    }
}
