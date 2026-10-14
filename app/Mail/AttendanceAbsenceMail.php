<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Plain school-origin attendance notice through the configured school mail path and reply address. */
class AttendanceAbsenceMail extends Mailable
{
    public function __construct(public string $orgName, public ?string $orgEmail, public string $mailSubject, public string $bodyText) {}

    public function build(): self
    {
        $this->subject($this->mailSubject)->from(config('mail.from.address'), $this->orgName)->view('emails.attendance-absence')->text('emails.attendance-absence-text');
        if ($this->orgEmail && filter_var($this->orgEmail, FILTER_VALIDATE_EMAIL)) $this->replyTo($this->orgEmail);
        return $this;
    }
}
