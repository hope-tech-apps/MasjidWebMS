<?php

namespace App\Mail;

use App\Models\ContactUsMessage;
use App\Models\Masjid;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactRequestReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Masjid $masjid,
        public ContactUsMessage $contactMessage,
        public string $replyBody
    ) {
    }

    public function envelope(): Envelope
    {
        $reason = $this->contactMessage->reason?->text ?? 'your inquiry';

        return new Envelope(
            subject: 'Re: ' . $reason . ' - ' . $this->masjid->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-request-reply',
            with: [
                'masjidName' => $this->masjid->name,
                'contacterName' => $this->contactMessage->contacter?->name ?? 'there',
                'originalMessage' => $this->contactMessage->message,
                'replyBody' => $this->replyBody,
            ],
        );
    }
}
