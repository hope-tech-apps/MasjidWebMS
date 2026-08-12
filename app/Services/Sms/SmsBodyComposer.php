<?php

namespace App\Services\Sms;

use App\Models\Broadcast;
use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use Illuminate\Support\Str;

/**
 * Builds the outbound message body — including the parts carrier rules make
 * mandatory (T-009).
 *
 * ## Sender identity and opt-out language are CODE, not documentation
 *
 * CTIA messaging principles and every US carrier's A2P rules require a bulk
 * message to identify who sent it and to tell the recipient how to stop. Those
 * are the two lines a "we'll remember to type it" policy loses first — the third
 * time an admin sends a snowstorm cancellation at 5am, in a hurry, from a phone.
 * So neither is optional and neither is the admin's responsibility: the identity
 * is prepended and the opt-out sentence is appended here, on every message, and
 * an admin cannot compose them away.
 *
 * They are also protected from truncation. The message is built as
 *
 *     {sender}: {title}
 *     {body}
 *     {link}
 *     {opt-out}
 *
 * and when the whole thing exceeds the budget it is the ADMIN'S TEXT that gets
 * shortened, never the identity, the link or the opt-out line. A message that
 * loses "Reply STOP to unsubscribe" to a character count is a compliance failure
 * wearing a formatting bug's clothes.
 *
 * ## The budget
 *
 * `services.sms.max_body_length` (default 480 characters ~ three GSM-7 segments)
 * is a COST and readability ceiling, not a protocol limit — the provider will
 * happily concatenate more and bill for each one. Three segments is a deliberate
 * default: enough for a real announcement, short of the point where a
 * congregation-wide send costs more than the announcement is worth.
 *
 * ## The sender label
 *
 * `masjid_sms_senders.sender_label` when the operator recorded one — an
 * organisation's carrier-registered brand name can differ from its display name,
 * and the registered one is what must appear. Falls back to the masjid's name.
 */
class SmsBodyComposer
{
    /**
     * Compose the full outbound body for one broadcast.
     */
    public function compose(Broadcast $broadcast, Masjid $masjid, ?MasjidSmsSender $sender = null): string
    {
        $identity = $this->senderIdentity($masjid, $sender);
        $optOut = trim((string) config('services.sms.opt_out_language', 'Reply STOP to unsubscribe.'));
        $budget = max(160, (int) config('services.sms.max_body_length', 480));

        $link = trim((string) $broadcast->link);
        $title = $this->collapse((string) $broadcast->title);
        $body = $this->collapse((string) $broadcast->body);

        // Everything that may NOT be truncated, plus the separators around the
        // admin's text. Computed first so the remainder is what the admin's
        // words are allowed to occupy.
        $prefix = $identity . ': ';
        $suffix = ($link !== '' ? "\n" . $link : '') . "\n" . $optOut;

        $available = $budget - mb_strlen($prefix) - mb_strlen($suffix);

        // A pathological configuration (a very long org name and a very long
        // link) can leave nothing for the message. The identity and the opt-out
        // still go out — an unhelpfully short message is recoverable, an
        // unidentified one with no way to opt out is not.
        if ($available < 1) {
            return $prefix . $optOut;
        }

        $content = $title;

        if ($body !== '' && $body !== $title) {
            $content = $title === '' ? $body : $title . "\n" . $body;
        }

        if (mb_strlen($content) > $available) {
            // Str::limit APPENDS its ellipsis beyond the limit rather than
            // inside it, so the limit passed here leaves room for the one
            // character — otherwise a truncated message would overrun the
            // budget by exactly the ellipsis, which is how a "Reply STOP" line
            // ends up one character over a segment boundary.
            $content = $available > 1
                ? Str::limit($content, $available - 1, '…')
                : mb_substr($content, 0, $available);
        }

        return $prefix . $content . $suffix;
    }

    /**
     * The identity that appears in the message. Registered label first, the
     * organisation's own name second.
     */
    public function senderIdentity(Masjid $masjid, ?MasjidSmsSender $sender = null): string
    {
        $label = trim((string) ($sender?->sender_label ?? ''));

        if ($label !== '') {
            return $label;
        }

        return trim((string) $masjid->name) ?: 'Your organization';
    }

    /** Newlines and runs of whitespace cost segments; collapse them. */
    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
