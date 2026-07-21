<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells Hope Tech Inc that a masjid admin asked the assistant for something it
 * could not do.
 *
 * Queued on purpose: the assistant is mid-conversation when this fires, and the
 * admin should not wait on SMTP. The authoritative record is the
 * assistant_feature_requests row — this mail is the nudge, not the system of
 * record, so a delivery failure loses a notification and nothing else.
 *
 * Primitives only, so the queued payload can't drag a tenant-scoped model
 * through serialization.
 */
class AssistantFeatureRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $requestId,
        public string $masjidName,
        public int $masjidId,
        public string $requestedBy,
        public string $requestedByEmail,
        public string $category,
        public string $summary,
        public ?string $details = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Masjid Assistant] ' . $this->categoryLabel() . ' — ' . $this->masjidName,
            replyTo: [$this->requestedByEmail],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.assistant-feature-request',
            with: [
                'requestId' => $this->requestId,
                'masjidName' => $this->masjidName,
                'masjidId' => $this->masjidId,
                'requestedBy' => $this->requestedBy,
                'requestedByEmail' => $this->requestedByEmail,
                'categoryLabel' => $this->categoryLabel(),
                'summary' => $this->summary,
                'details' => $this->details,
            ],
        );
    }

    /** The enum value is for the database; this is for a human reading their inbox. */
    public function categoryLabel(): string
    {
        return match ($this->category) {
            'missing_capability' => 'Feature request',
            'feature_not_enabled' => 'Enablement request',
            'insufficient_permission' => 'Permission request',
            default => 'Request',
        };
    }
}
