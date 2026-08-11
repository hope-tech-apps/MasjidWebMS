<?php

namespace App\Services\Broadcast\Channels;

use App\Enums\BroadcastChannel;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\ChannelResult;
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
 */
class EmailChannel implements BroadcastChannelDriver
{
    public function __construct(private readonly BroadcastAudienceResolver $audience)
    {
    }

    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::EMAIL;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        $recipients = $this->audience->emailRecipients($broadcast);

        if ($recipients->isEmpty()) {
            return ChannelResult::skipped(
                'Nobody in the selected audience has an email address on file, so there was nothing to send.'
            );
        }

        $sent = 0;
        $failed = 0;
        $lastError = null;

        foreach ($recipients as $contact) {
            try {
                Mail::to($contact->email)->send(new BroadcastMail(
                    orgName: (string) $masjid->name,
                    title: (string) $broadcast->title,
                    body: (string) $broadcast->body,
                    link: $broadcast->link,
                    imageUrl: $broadcast->imageUrl(),
                    recipientName: trim((string) $contact->first_name) ?: null,
                    orgEmail: $masjid->email,
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

        return ChannelResult::sent(targetCount: $sent, note: $note);
    }
}
