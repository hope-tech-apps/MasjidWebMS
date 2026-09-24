<?php

namespace App\Mail;

use App\Support\MailGreeting;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Your school has set up a portal for you — here is the way in."
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE ONE FAMILY EMAIL THAT CARRIES A LINK, AND THAT IS A DECISION
 * ---------------------------------------------------------------------------
 *
 * `FamilyLoginCodeMail` says, in its template, that it deliberately contains no
 * link and no button: "the only thing this mail asks the reader to do is type
 * six digits back into an app or page they already opened, so a click target
 * would be a phishing pattern to train families into". That reasoning holds for
 * the ROUTINE act — a parent signing in, over and over, for years — and it is
 * untouched. Sign-in stays link-free.
 *
 * It does not hold for the FIRST act. An invite is sent once, by a named member
 * of staff, to a parent who does not yet know the portal exists and has no page
 * open to type anything into. Telling that parent to visit a URL they have to
 * type by hand is what produced the number this feature exists to fix: ten
 * family logins enabled at Al-Razi, five never used. A link is what the staff
 * invite already does (`AccountAccessMail`) and it is what a parent can act on.
 *
 * What keeps the trade honest:
 *
 *  - it is single-use and dies in seven days, so a forwarded or leaked copy has
 *    a short, closing window and never a permanent one;
 *  - it opens ONE family's own children and nothing else, and the office can end
 *    it from the admin console at any moment;
 *  - and the copy tells the reader who sent it and what it is for, so a real one
 *    is distinguishable from a forgery by something other than the link itself.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE BODY MUST NOT CARRY
 * ---------------------------------------------------------------------------
 *
 * No child's name, no class name, no marks, no messages — the same rule
 * `GroupUpdateNudgeMail` states: an inbox forwards, previews on a lock screen
 * and sits in a shared household account, and the school's disclosure rules live
 * in the portal where consent and identity are checked. The subject names the
 * organisation, which the parent already knows they are associated with because
 * the organisation is who chose to write to them.
 *
 * ---------------------------------------------------------------------------
 * NOT `ShouldQueue`, and for the same reason as the sign-in code
 * ---------------------------------------------------------------------------
 *
 * QUEUE_CONNECTION is `database`, so a queued mailable writes its public
 * properties into `jobs.payload` — and into `failed_jobs` on a relay outage.
 * `$url` carries the plaintext invite token. Queuing this would persist a
 * working key to a child's records in two tables that exist to be read by
 * operators. It is sent inline, from an authenticated admin request, and the
 * office is TOLD when it fails (see FamilyInviteService::issue — unlike the
 * sign-in code, whose failure has to be swallowed because that endpoint is
 * unauthenticated and an error path would be an existence oracle).
 */
class FamilyPortalInviteMail extends Mailable
{
    use SerializesModels;

    /** The lifetime in words for the reader ("7 days"), never "10080 minutes". */
    public string $expiresIn;

    public function __construct(
        public string $orgName,
        public string $url,
        public int $expiresInDays = 7,
        public ?string $recipientName = null,
        /**
         * Named orgEmail, not replyTo: Mailable already declares an untyped
         * $replyTo and redeclaring it with a type is fatal — the same note
         * FamilyLoginCodeMail, GroupUpdateNudgeMail and BroadcastMail carry.
         */
        public ?string $orgEmail = null,
    ) {
        // A stored contact name, and a stranger can plant a web address as a
        // contact's first name through the public registration form. See
        // MailGreeting. Cleaned here so the view cannot print the raw value.
        $this->recipientName = MailGreeting::safeName($recipientName);
        $this->expiresIn = AccountAccessMail::inWords($expiresInDays * 1440);
    }

    public function build(): self
    {
        // Names the organisation, because a parent who cannot tell which school
        // wrote to them will not click, and because the alternative — a generic
        // subject, which is what the sign-in nudges use — is aimed at hiding a
        // family's association with a school from a lock screen. That is not
        // achievable here: this mail exists to tell a parent about their
        // school's portal, and the body has to say which school it is before any
        // of it makes sense. Nothing about a CHILD appears in either line.
        $subject = 'Your '.($this->orgName ?: config('app.name')).' parent portal is ready';

        $mail = $this->subject($subject)
            // The SENDER NAME follows the school (matching the staff invite and
            // the group nudge), so the "From" line and the body agree; the
            // ADDRESS stays the configured, domain-verified sender.
            ->from(config('mail.from.address'), $this->orgName ?: config('mail.from.name', config('app.name')))
            ->view('emails.family-portal-invite', [
                'greeting' => MailGreeting::for($this->recipientName),
            ]);

        // So "reply to this email" reaches the office rather than a no-reply
        // address, which is the first thing a confused parent does.
        if ($this->orgEmail && filter_var($this->orgEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($this->orgEmail);
        }

        return $mail;
    }
}
