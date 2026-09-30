<?php

namespace App\Mail;

use App\Support\MailGreeting;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Your weekly report is ready" — and nothing about what is in it.
 *
 * The Friday points report (T-003.3) reaches a family as a NOTICE plus a link to the
 * printable report in the portal (owner, B5, 2026-09-29: "your child's weekly report is
 * ready", nothing about the child in the email). The reason is the one
 * GroupUpdateNudgeMail gives, and it holds harder here: a child's points are exactly the
 * thing a lock screen, a forwarded message and a shared household inbox must not carry.
 * So this email has no child's name, no figure, no skill and no count of anything; the
 * subject is generic and identical for every school, family and teacher; and the body
 * names only the school and the class, as the update nudge already does.
 *
 * The same class serves the CLASS SUMMARY that goes to a teacher (`audience` =
 * 'teacher'): also a notice with a link, because a teacher's inbox is not a safe place
 * for a class's numbers either.
 *
 * Its own mailable rather than another `kind` of GroupUpdateNudgeMail: that one is
 * fed by SendGroupNotificationJob, whose sign-in URL is the family's whatever the
 * recipient (a teacher would be sent to the wrong door), and this sweep needs a
 * per-audience link. Deliberately NOT ShouldQueue for the reason the nudge is not:
 * it is sent from inside the sweep, which owns the per-recipient failure isolation.
 * Only scalars cross the wire.
 */
class WeeklyPointsReportMail extends Mailable
{
    use SerializesModels;

    public const AUDIENCE_FAMILY = 'family';
    public const AUDIENCE_TEACHER = 'teacher';

    public function __construct(
        public string $orgName,
        public string $groupLabel,
        /** 'family' (a guardian) or 'teacher' (a member of the class's staff). */
        public string $audience,
        /** Where the button goes: the family sign-in that lands on the report, or the teacher's Points tab. */
        public string $url,
        public ?string $recipientName = null,
        /** Named orgEmail, not replyTo: Mailable declares an untyped $replyTo (see GroupUpdateNudgeMail). */
        public ?string $orgEmail = null,
    ) {
        // A stored name can be a planted web address (MailGreeting says how); cleaned
        // here so the view can never print the raw value.
        $this->recipientName = MailGreeting::safeName($recipientName);
    }

    public function build(): self
    {
        // Generic and identical for every tenant and recipient: a subject shows on a
        // lock screen and in a shared inbox list.
        $subject = $this->audience === self::AUDIENCE_TEACHER
            ? 'Your weekly class summary is ready'
            : 'Your weekly report is ready';

        $mail = $this->subject($subject)
            ->from(config('mail.from.address'), $this->orgName ?: config('mail.from.name', config('app.name')))
            ->view('emails.weekly-points-report', [
                'greeting' => MailGreeting::for($this->recipientName),
                'isTeacher' => $this->audience === self::AUDIENCE_TEACHER,
            ]);

        if ($this->orgEmail && filter_var($this->orgEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($this->orgEmail);
        }

        return $mail;
    }
}
