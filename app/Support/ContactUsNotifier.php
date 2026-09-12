<?php

namespace App\Support;

use App\Mail\ContactUsMessageReceived;
use App\Models\ContactUsMessage;
use App\Models\Masjid;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Tells an organisation's office that a contact-us message arrived (PLAN T-042d).
 *
 * Both intake doors — the website (Api\V1\ContactUsController) and the mobile
 * app (Mobile\ContactUsController) — used to `ContactUsMessage::create()` and
 * return. Nothing was sent to anybody. The message existed only on a dashboard
 * screen that nobody is obliged to open, which is how a family's request for
 * help sits unread for a week.
 *
 * The shape is App\Support\FormNotifier's, deliberately, and for the same
 * reasons. An Event + Listener would be the wrong instrument here:
 * .claude/rules/events-listeners.md — discovery is on, so a hand-registered
 * listener fires twice.
 *
 * ## Four rules govern everything below
 *
 * 1. **Mail must never cost somebody their message.** The row is committed
 *    before this runs and every path is wrapped: a bad SMTP credential, a
 *    malformed recipient, a queue outage — all of it degrades to a log line and
 *    a 200. The caller is anonymous, has already been told their message was
 *    received, and that statement is true regardless of what happens to the
 *    email. .claude/rules/environments.md: "An integration with no credentials
 *    must no-op, not throw."
 *
 * 2. **The address list is SERVER-DERIVED, always.** Recipients come from the
 *    organisation's own stored contact address (`masjids.email`) and from
 *    nowhere else. Not from the request, not from a header, not from anything
 *    the anonymous sender typed. This endpoint is unauthenticated: if any part
 *    of the payload could steer delivery, the notification would be a way to
 *    make Manara mail arbitrary strangers a message of the sender's choosing,
 *    from the organisation's own domain. `notificationRecipients()` is the only
 *    place an address is chosen and it takes no caller input at all.
 *
 * 3. **It cannot be turned into a way to flood the office.** Both doors are
 *    throttled per IP+organisation (the `contact-us` and `contact` limiters in
 *    AppServiceProvider, 8 and 10 an hour), which stops one connection. That is
 *    not the whole story: a distributed flood would still put one email in the
 *    office inbox per accepted message. So there is a second, per-ORGANISATION
 *    ceiling here — see MAX_PER_HOUR. Past it the message is still accepted and
 *    still appears in the dashboard; only the nudge is dropped, and a warning
 *    says so once. Losing a notification is recoverable; a mailbox nobody can
 *    use any more is not, and an inbox provider throttling the domain would take
 *    every other Manara email down with it.
 *
 * 4. **The message body never reaches the log.** A contact-us message is a
 *    member of the public's free text — it is where "my husband has just died"
 *    and "I cannot pay my rent" actually live. Same reasoning as FormNotifier's
 *    treatment of free-text answers. Log lines here carry ids and counts only.
 */
class ContactUsNotifier
{
    /**
     * Ceiling on notification emails per organisation per hour. See rule 3.
     *
     * Sized above any plausible real day (an organisation that genuinely
     * receives thirty contact messages in one hour has bigger news than a
     * missing nudge) and well below the volume that gets a sending domain
     * rate-limited.
     */
    private const MAX_PER_HOUR = 30;

    /** A stored address is one address; this bounds it the way FormNotifier does. */
    private const MAX_RECIPIENTS = 20;

    /** How it arrived, for the office's triage. */
    public const SOURCE_WEBSITE = 'the website';

    public const SOURCE_MOBILE_APP = 'the mobile app';

    /**
     * Fire the staff notification for a message that has already been committed.
     *
     * Deliberately returns void and swallows everything: the caller has already
     * accepted the message, and nothing that happens in here may change that.
     *
     * `$masjid` is the tenant the CONTROLLER resolved — the header-checked
     * organisation on the website door, the route's organisation on the mobile
     * one. It is passed in rather than walked back out of the message's
     * relations so that the notification can only ever go to the organisation
     * the message was actually filed against. A null masjid (a row deleted
     * between the resolve and here) is a log line, not an exception.
     */
    public static function received(ContactUsMessage $message, ?Masjid $masjid, string $source): void
    {
        try {
            if ($masjid === null) {
                Log::warning('Contact-us message has no organisation to notify.', [
                    'contact_us_message_id' => $message->id,
                ]);

                return;
            }

            $recipients = self::notificationRecipients($masjid);

            if ($recipients === []) {
                // A configuration mistake, and invisible from the outside: the
                // form works, the row lands, and nobody is ever told. Worth a
                // log line rather than silence — the same call FormNotifier
                // makes for a form with no coordinators.
                Log::warning('Contact-us message has no notification recipients.', [
                    'contact_us_message_id' => $message->id,
                    'masjid_id' => $masjid->id,
                ]);

                return;
            }

            if (self::overHourlyCeiling($masjid)) {
                return;
            }

            Mail::to($recipients)->send(new ContactUsMessageReceived(
                messageId: $message->id,
                masjidName: $masjid->name ?? 'your organization',
                reason: self::reasonText($message),
                senderName: self::senderName($message),
                senderEmail: self::senderEmail($message),
                senderPhone: $message->contacter?->phone,
                body: (string) $message->message,
                receivedAt: self::receivedAt($message, $masjid),
                adminUrl: self::adminUrl(),
                source: $source,
            ));
        } catch (Throwable $e) {
            // Rule 1. Note what is NOT here: no request payload, no message
            // body, no sender details. Exception metadata only.
            Log::error('Contact-us notification failed.', [
                'contact_us_message_id' => $message->id,
                'masjid_id' => $masjid?->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Who hears about a contact message.
     *
     * The organisation's own stored contact address, and nothing else. This is
     * the address `FormNotifier::coordinatorRecipients()` falls back to, and
     * using the same one is the point: the office already publishes it, already
     * watches it, and it needs no new setting to configure, forget to
     * configure, or mis-configure. There is deliberately no per-request,
     * per-reason or client-supplied override — see rule 2.
     *
     * Public so a test can assert the derivation without going through the mail
     * fake, and so the reason an address was or was not chosen stays in one
     * readable place.
     *
     * @return array<int,string>
     */
    public static function notificationRecipients(Masjid $masjid): array
    {
        $stored = $masjid->email;

        if (! is_string($stored)) {
            return [];
        }

        $email = strtolower(trim($stored));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? array_slice([$email], 0, self::MAX_RECIPIENTS)
            : [];
    }

    /**
     * Whether this organisation has already had its hour's worth of nudges.
     *
     * Reads and counts in one place. The limiter is keyed on the organisation,
     * NOT on the sender: the harm being bounded is what lands in one office's
     * inbox, and a flood is by definition many senders. The warning fires once,
     * on the send that trips it, so a locked bucket is findable in a log that
     * runs at `warning` on production without printing a line per dropped
     * message.
     */
    private static function overHourlyCeiling(Masjid $masjid): bool
    {
        $key = 'contact-us-notify:' . $masjid->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            return true;
        }

        if (RateLimiter::hit($key, 3600) === self::MAX_PER_HOUR) {
            Log::warning('Contact-us notifications are capped for this hour.', [
                'masjid_id' => $masjid->id,
                'max_per_hour' => self::MAX_PER_HOUR,
            ]);
        }

        return false;
    }

    /**
     * The reason as the office should read it.
     *
     * NOTE that `contact_us_reasons` is a GLOBAL table with no `masjid_id`, and
     * the unauthenticated intake creates a row for any unseen `reason_text` — so
     * this string can be attacker-chosen free text shared across every tenant.
     * It goes into a subject line, so it is trimmed and length-bounded here
     * rather than trusted. (Fixing the global table is not this task; do not
     * surface these as if they were per-organisation.)
     */
    private static function reasonText(ContactUsMessage $message): string
    {
        $text = trim((string) ($message->reason?->text ?? ''));

        return $text === '' ? 'General enquiry' : mb_substr($text, 0, 120);
    }

    private static function senderName(ContactUsMessage $message): string
    {
        $name = trim((string) ($message->contacter?->name ?? ''));

        return $name === '' ? 'Someone' : mb_substr($name, 0, 120);
    }

    /** Null unless it actually parses — the Mailable puts this in Reply-To. */
    private static function senderEmail(ContactUsMessage $message): ?string
    {
        $email = trim((string) ($message->contacter?->email ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * When it arrived, on the ORGANISATION'S clock.
     *
     * An office reading "3:12 AM" for a message sent at 11:12 PM local time
     * mis-prioritises it. `masjids.timezone` defaults to 'UTC', which means
     * "never set" rather than a real choice, and a name PHP does not know is no
     * better than none — the same reading FormStaffCodesController::timezoneFor
     * takes.
     */
    private static function receivedAt(ContactUsMessage $message, Masjid $masjid): string
    {
        $at = $message->created_at ?? now();
        $name = trim((string) $masjid->timezone);

        if ($name !== '' && strcasecmp($name, 'UTC') !== 0) {
            try {
                return $at->copy()->setTimezone(new DateTimeZone($name))->format('D j M Y, g:i A T');
            } catch (Throwable) {
                // falls through to UTC
            }
        }

        return $at->copy()->utc()->format('D j M Y, g:i A \U\T\C');
    }

    /** Deep link to the inbox this message landed in. */
    private static function adminUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/masjid/contact-requests';
    }
}
