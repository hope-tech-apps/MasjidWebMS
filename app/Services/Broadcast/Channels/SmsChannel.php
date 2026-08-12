<?php

namespace App\Services\Broadcast\Channels;

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\ChannelResult;
use App\Services\Sms\SmsBodyComposer;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsNotConfiguredException;
use App\Services\Sms\SmsProviderFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The SMS channel (T-009) — the channel T-008's seam was shaped around, and
 * deliberately left empty until the obligations underneath it existed.
 *
 * It is a channel like the other four: an enum case, this class, one line in
 * BroadcastDispatcher::DRIVERS. Nothing about the composer, the request, the
 * endpoint or the storage schema changed to accommodate it. What DID have to
 * exist first is everything this driver refuses on.
 *
 * ## Three refusals, in order, before a single message is composed
 *
 * 1. **No registered sender for this tenant** — `masjid_sms_senders` absent, or
 *    present but not `approved`. There is no shared fallback number anywhere in
 *    this feature: putting unregistered traffic on one long code gets the number
 *    filtered and then the whole provider account suspended, taking SMS away
 *    from every organisation on the platform. The refusal names the missing
 *    thing (A2P 10DLC registration) so an admin can escalate it rather than
 *    retry it.
 * 2. **No provider configured on this deployment** — NullSmsProvider throws
 *    SmsNotConfiguredException. Unset credentials fail SOFT, exactly like GitHub
 *    dispatch and Google geocoding: nothing errors at boot, nothing errors on a
 *    request that did not ask to send, and the one that did gets a sentence
 *    saying SMS is not set up here.
 * 3. **Nobody in the audience may be texted** — a SKIP, not a failure (T-008's
 *    distinction), because "nobody has consented" is a fact about the
 *    organisation's consent coverage rather than an error. The note breaks the
 *    audience down so the admin can see it is 612 unconsented contacts and not a
 *    broken integration.
 *
 * The first two are FAILURES and not skips, deliberately. A skip renders as a
 * neutral "there was nothing to do"; but there WERE people to text and the
 * message did not go out. .claude/rules/broadcasts.md's original objection
 * applies with full force here: an admin who believes a snowstorm cancellation
 * went out by text when it did not is worse off than one who can see it did not.
 *
 * ## Per-recipient isolation, and why it makes re-dispatch safe
 *
 * Like EmailChannel, one bad number out of six hundred must not cost the other
 * five hundred and ninety-nine their message, so each send is individually
 * guarded and counted. And like EmailChannel, this driver only THROWS when EVERY
 * recipient failed — which is what makes T-008's idempotent re-dispatch honest
 * for a channel that costs money and cannot be recalled: a delivery row marked
 * `failed` is one where nothing was accepted by the provider, so re-dispatching
 * it cannot double-text anybody. A partial send is recorded as `sent` with the
 * failure count in its note, and the dispatcher will never re-attempt it.
 *
 * ## PII
 *
 * No phone number and no message body is written to a log line, an exception
 * message, or the delivery row. Failures are logged by contact id. The delivery
 * row carries counts and the provider's message id for the LAST accepted
 * message — the handle an operator needs in the provider console when a carrier
 * complaint arrives — and nothing that identifies a recipient.
 */
class SmsChannel implements BroadcastChannelDriver
{
    public function __construct(
        private readonly BroadcastAudienceResolver $audience,
        private readonly SmsProviderFactory $providers,
        private readonly SmsBodyComposer $bodies,
    ) {
    }

    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::SMS;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        $sender = $this->sender($masjid);

        if ($sender === null) {
            throw new RuntimeException(
                'This organization has no registered text-message sender, so nothing was sent. '
                . 'US carriers require each organization to register its own A2P 10DLC brand, campaign and '
                . 'sending number before it may send bulk text messages; there is deliberately no shared '
                . 'number to fall back on. Ask your platform operator to complete registration.'
            );
        }

        if ($reason = $sender->refusalReason()) {
            throw new RuntimeException($reason);
        }

        $provider = $this->providers->make();

        if (! $provider->isConfigured()) {
            // Fail SOFT and clearly: the exception is caught by the dispatcher,
            // recorded on this channel's delivery row, and leaves every other
            // channel of this broadcast untouched.
            throw SmsNotConfiguredException::make();
        }

        $audience = $this->audience->smsRecipients($broadcast);

        if ($audience->isEmpty()) {
            return ChannelResult::skipped($audience->skipNote());
        }

        // Composed ONCE. The sender identity and the opt-out language are added
        // here, by code, on every message — see SmsBodyComposer.
        $body = $this->bodies->compose($broadcast, $masjid, $sender);

        $sent = 0;
        $failed = 0;
        $lastMessageId = null;
        $lastError = null;

        foreach ($audience->recipients as $row) {
            try {
                $result = $provider->send(new SmsMessage(
                    to: $row['number'],
                    body: $body,
                    from: $sender->hasMessagingService() ? null : $sender->phone_number,
                    messagingServiceSid: $sender->messaging_service_sid,
                ));

                $lastMessageId = $result->providerMessageId;
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();

                // One number is a data problem, not an outage. Logged by contact
                // id — never the number, never the body.
                Log::warning('Broadcast SMS failed for one recipient.', [
                    'broadcast_id' => $broadcast->id,
                    'masjid_id' => $masjid->id,
                    'contact_id' => $row['contact']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sent === 0) {
            // Nothing was accepted by the provider — an outage or a
            // misconfiguration, and the one case where re-dispatching this
            // channel is both safe and useful.
            throw new RuntimeException(
                'The SMS provider accepted none of the ' . $failed . ' message(s). Last error: ' . $lastError
            );
        }

        $note = 'Handed ' . $sent . ' message(s) to the ' . $provider->name()
            . ' provider; carriers confirm delivery asynchronously.';

        if ($failed > 0) {
            $note .= ' ' . $failed . ' number(s) were rejected; see the log.';
        }

        if ($summary = $audience->exclusionSummary()) {
            $note .= ' ' . $summary;
        }

        return ChannelResult::sent(
            targetCount: $sent,
            reference: $lastMessageId,
            note: $note,
        );
    }

    /**
     * This tenant's sender identity.
     *
     * Read through the bound tenant scope (the dispatcher binds it before any
     * driver runs), so a masjid can never resolve another's number — the
     * borrowed-number failure this whole table exists to prevent is impossible
     * at the query level as well as at the policy level.
     */
    private function sender(Masjid $masjid): ?MasjidSmsSender
    {
        return MasjidSmsSender::query()->where('masjid_id', $masjid->id)->first();
    }
}
