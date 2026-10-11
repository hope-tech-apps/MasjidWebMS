<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class DutyRotaMail extends Mailable
{
    public function __construct(public string $orgName, public ?string $orgEmail, public string $mailSubject, public string $bodyText) {}
    public function build(): self
    {
        $this->subject($this->mailSubject)->from(config('mail.from.address'),$this->orgName)->view('emails.duty-rota')->text('emails.duty-rota-text');
        if ($this->orgEmail && filter_var($this->orgEmail,FILTER_VALIDATE_EMAIL)) $this->replyTo($this->orgEmail);
        return $this;
    }
}
