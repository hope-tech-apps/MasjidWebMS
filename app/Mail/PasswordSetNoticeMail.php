<?php

namespace App\Mail;

use App\Support\MailGreeting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your password was set": one short notice to a contact's `login_email` every
 * time a password is written for it (owner, 2026-09-17: "Yes, send it" — "One
 * short email to the account's address after any password is set").
 *
 * Contacts only: app members and parent-portal families, whose one password
 * FamilyPasswordService::set() writes. Staff passwords are not this mail.
 * FamilyPasswordService::set() is its only sender (see PasswordSetNotice),
 * after the write commits.
 *
 * ---------------------------------------------------------------------------
 * IT CARRIES NOTHING THAT OPENS ANYTHING
 * ---------------------------------------------------------------------------
 *
 * No password, no code, no token, and no link of any kind. The one value in
 * it that a stranger can influence is the first name, and MailGreeting leaves
 * that out unless it looks like a name, so it cannot carry a web address
 * either. A notice that
 * somebody changed your password is the most-forged email there is; one with
 * no click target gives a phisher no template worth copying and gives whoever
 * reads the inbox no one-click way to change the account. What to do if it was
 * not you is written as words: choose a new password the way the person
 * already knows, and tell the organisation.
 *
 * The properties are scalars. No model is passed in, so no `contacts` row (and
 * its staff `notes`) can reach a mail payload.
 *
 * ---------------------------------------------------------------------------
 * SENT INLINE, NOT `ShouldQueue` — deliberately, and for a different reason
 * than FamilyLoginCodeMail
 * ---------------------------------------------------------------------------
 *
 * There is no secret here to keep out of `jobs.payload`, so that is not the
 * reason. The reasons are:
 *
 *  - It is a security notice. Its value is arriving while the person can still
 *    act. Queued, it waits on the `database` worker, and a worker that is down
 *    or backed up turns "someone just changed your password" into news from
 *    hours ago. TwoFactorResetMail is unqueued for the same reason.
 *  - A queued mail that fails is kept in `failed_jobs`, with the family's
 *    address and first name in it, for as long as nobody prunes the table.
 *    Inline, a failure leaves one log line with ids and an exception class.
 *  - The cost is one mail API call, once, on a request the person is already
 *    waiting on and that has already paid for a bcrypt hash.
 *
 * The price is no retry: if the relay is down at that moment, the notice is
 * lost and a warning is logged. The password change itself is never affected.
 */
class PasswordSetNoticeMail extends Mailable
{
    public const SUBJECT = 'Your password was set';

    public function __construct(
        public string $orgName,
        /** The login address the password now belongs to, shown so a shared inbox can tell whose it is. */
        public string $loginEmail,
        /** Already formatted in the organisation's timezone, with the zone named. */
        public string $setAt,
        public ?string $recipientName = null,
        /** @see FamilyLoginCodeMail::$orgEmail for why this is not called replyTo. */
        public ?string $orgEmail = null,
        /** The person has proved this address to the app, so "Forgot password?" in the app is real for them. */
        public bool $usesApp = false,
        /** The office has a live family login for them, so the family portal is real for them. */
        public bool $usesFamilyPortal = false,
    ) {
        // A first name is not always the reader's own words: see MailGreeting.
        // Cleaned here, once, so no part of this mail can print the raw value.
        $this->recipientName = MailGreeting::safeName($recipientName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->orgName ?: config('mail.from.name')),
            // Identical for every organisation and every case, like the sign-in
            // code's subject. The From name above IS the organisation, so a lock
            // screen or inbox list that shows the sender still names it; this
            // only keeps the subject from naming it a second time. "set" rather
            // than "changed" because it is true both for a new
            // account's first password and for a replacement, and because the
            // wording must not say whether a password existed before: on an
            // address the app has just linked, that earlier password may have
            // been chosen under somebody else's address.
            subject: self::SUBJECT,
            replyTo: $this->orgEmail && filter_var($this->orgEmail, FILTER_VALIDATE_EMAIL)
                ? [$this->orgEmail]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-set-notice',
            text: 'emails.password-set-notice-text',
            with: [
                'orgName' => $this->orgName,
                'loginEmail' => $this->loginEmail,
                'setAt' => $this->setAt,
                'ifItWasNotYou' => $this->ifItWasNotYou(),
                'greeting' => MailGreeting::for($this->recipientName),
            ],
        );
    }

    /**
     * What to do if the reader did not set it. Built here, once, so the HTML
     * and text parts cannot say different things.
     *
     * It names a way back only when this person has used it. Not every
     * organisation has an app and not every one has a family portal, so
     * "use the app" to a parent whose school has none would be a claim about
     * the organisation that nobody made. `usesApp` is true only when this
     * person has proved the address to this organisation's app, and
     * `usesFamilyPortal` only when the office has a live family login for
     * them. `usesApp` does not prove their installed build has "Forgot
     * password?": Android builds before feat/r1-owner-feedback have no
     * password sign-in. So every case ends with "contact {org}", which works
     * for everyone. The control names are the ones the current screens show.
     */
    public function ifItWasNotYou(): string
    {
        $org = $this->orgName;

        return match (true) {
            $this->usesApp && $this->usesFamilyPortal => 'If it was not you, choose a new password now: use “Forgot password?” in the app, '
                . 'or sign in to the family portal with an emailed code and choose “Change my password”. '
                . "Then contact {$org}.",
            $this->usesApp => 'If it was not you, use “Forgot password?” in the app to choose a new password now, '
                . "then contact {$org}.",
            $this->usesFamilyPortal => 'If it was not you, sign in to the family portal with an emailed code, '
                . "choose “Change my password”, then contact {$org}.",
            default => "If it was not you, contact {$org}.",
        };
    }
}
