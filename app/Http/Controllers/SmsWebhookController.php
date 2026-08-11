<?php

namespace App\Http\Controllers;

use App\Models\MasjidSmsSender;
use App\Models\SmsSuppression;
use App\Services\Sms\PhoneNumber;
use App\Services\Sms\SmsConsentService;
use App\Services\Sms\TwilioSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound SMS webhook — STOP and START, honoured immediately and permanently
 * (T-009).
 *
 * Under the TCPA an unhonoured opt-out is a per-message statutory liability for
 * the organisation, not a support ticket, and "immediately" is measured in the
 * seconds before the next send — not in the hours before someone reads a queue.
 * So this endpoint writes the suppression synchronously, before it answers.
 *
 * ## Signature is the ONLY gate, and it fails CLOSED
 *
 * Registered outside auth and throttle, exactly like the Stripe webhook
 * (routes/api.php): the provider is not a logged-in user. Every request is
 * HMAC-verified against the account auth token, and an UNSET token verifies
 * nothing rather than waving anything through.
 *
 * That posture matters more here than it does for payments. An unverified
 * endpoint accepting opt-in keywords would let an attacker RE-SUBSCRIBE numbers
 * that had opted out — turning our own compliance machinery into the violation.
 * Rejecting everything when unconfigured is the only safe default.
 *
 * ## Idempotent by construction, so provider retries are free
 *
 * Providers retry on any non-2xx and sometimes on a timeout. There is no event
 * table here because there is nothing to deduplicate: suppressing an already
 * suppressed number updates the same row (the unique index over
 * (masjid_id, phone_e164) guarantees one), and releasing an already released one
 * is equally harmless. A dedup table would add a failure mode without removing
 * one.
 *
 * ## Which organisation the message was meant for
 *
 * The `To` number — the tenant's own registered 10DLC number — identifies the
 * organisation. Resolution runs UNBOUND (this route never touches the tenant
 * middleware), which is what lets one endpoint serve every masjid, and the
 * resolved masjid_id is then written explicitly. An unknown `To` is acked and
 * logged rather than retried: it means the number is no longer ours, and a
 * deregistered sender cannot send either, so no message can follow the STOP we
 * were unable to file.
 *
 * ## What is answered, and what is NOT sent
 *
 * An empty TwiML document. The carrier-mandated STOP and HELP auto-replies are
 * the provider's Advanced Opt-Out feature on the Messaging Service — configured
 * once by the operator, applied to every number in the pool, and impossible for
 * us to get subtly wrong per-message. This application therefore never composes
 * a reply here, which is also why no code path reachable by a test can emit one.
 *
 * ## PII
 *
 * No phone number and no message body is logged. The keyword is, because it is
 * the evidence; everything else is a masjid id and a row id.
 */
class SmsWebhookController extends Controller
{
    public function __construct(
        private readonly TwilioSignatureVerifier $signatures,
        private readonly SmsConsentService $consent,
    ) {
    }

    public function handle(Request $request)
    {
        $params = $request->post();

        $verified = $this->signatures->verify(
            $this->signatures->urlFor($request->fullUrl()),
            is_array($params) ? $params : [],
            $request->header('X-Twilio-Signature'),
        );

        if (! $verified) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $from = PhoneNumber::e164((string) $request->input('From'));
        $to = PhoneNumber::e164((string) $request->input('To'));
        $keyword = $this->keyword((string) $request->input('Body'));

        // A number we cannot normalise is a number we could never have texted,
        // so there is nothing to suppress. Acked so the provider stops retrying.
        if ($from === null || $to === null) {
            return $this->ack();
        }

        $sender = MasjidSmsSender::withoutMasjidScope()
            ->where('phone_number', $to)
            ->first();

        if (! $sender) {
            // Not our number any more. Nothing to record, and nothing can be
            // sent from a sender that no longer exists.
            Log::warning('Inbound SMS for a number with no registered sender; ignored.', [
                'keyword' => $keyword,
            ]);

            return $this->ack();
        }

        $masjidId = (int) $sender->masjid_id;

        if (in_array($keyword, SmsSuppression::STOP_KEYWORDS, true)) {
            $suppression = $this->consent->suppress(
                masjidId: $masjidId,
                e164: $from,
                reason: SmsSuppression::REASON_STOP_KEYWORD,
                keyword: $keyword,
                providerMessageId: $request->input('MessageSid'),
            );

            Log::info('SMS opt-out recorded.', [
                'masjid_id' => $masjidId,
                'suppression_id' => $suppression->id,
                'keyword' => $keyword,
            ]);

            return $this->ack();
        }

        if (in_array($keyword, SmsSuppression::START_KEYWORDS, true)) {
            $this->consent->release($masjidId, $from, $keyword);

            Log::info('SMS opt-in restored by the subscriber.', [
                'masjid_id' => $masjidId,
                'keyword' => $keyword,
            ]);

            return $this->ack();
        }

        // HELP and everything else: answered provider-side, recorded nowhere.
        // An inbound message is not consent and must never be treated as one.
        return $this->ack();
    }

    /**
     * The control keyword, or null.
     *
     * Matched on the WHOLE trimmed body, case-insensitively — the way carriers
     * match it. A substring match would suppress a number for writing "please
     * don't stop the announcements", and would miss nothing real: the control
     * words are a fixed vocabulary the subscriber's handset is prompted with.
     */
    private function keyword(string $body): ?string
    {
        $word = strtoupper(trim($body));

        if ($word === '') {
            return null;
        }

        $known = array_merge(
            SmsSuppression::STOP_KEYWORDS,
            SmsSuppression::START_KEYWORDS,
            SmsSuppression::HELP_KEYWORDS,
        );

        return in_array($word, $known, true) ? $word : null;
    }

    /**
     * An empty TwiML document: "received, reply nothing". The mandated
     * auto-replies are the provider's Advanced Opt-Out, not ours.
     */
    private function ack()
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', Response::HTTP_OK)
            ->header('Content-Type', 'text/xml');
    }
}
