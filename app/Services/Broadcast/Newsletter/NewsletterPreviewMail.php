<?php

namespace App\Services\Broadcast\Newsletter;

use App\Mail\BroadcastMail;

/**
 * The email a draft would send, before anything is uploaded or addressed.
 *
 * Two callers need it and must agree: the composer's live preview, which shows
 * it, and the send request, which measures it against Gmail's clipping size
 * (NewsletterBlocks::MAX_EMAIL_BYTES) before storing anything. Building it in
 * one place means the size the admin is warned about in the preview is the size
 * the send refuses.
 *
 * Pictures are addressed at a reserved `.invalid` host (RFC 2606: it never
 * resolves, so nothing leaves the admin's browser to fetch it); the SPA swaps in
 * its own copy of each file. The unsubscribe link is a placeholder too: the real
 * one is minted per recipient at delivery.
 */
final class NewsletterPreviewMail
{
    /** Mirrored by the SPA (core/helpers/newsletterBlocks.ts PREVIEW_ORIGIN). */
    public const ORIGIN = 'https://preview.invalid';

    /**
     * @param  list<array<string, mixed>>  $blocks  normalised blocks, image keys unresolved
     */
    public static function build(
        string $orgName,
        ?string $title,
        ?string $body,
        ?string $link,
        bool $withImage,
        array $blocks,
    ): BroadcastMail {
        $placeholders = [];
        foreach (NewsletterBlocks::imageKeys($blocks) as $key) {
            $placeholders[$key] = self::ORIGIN . '/newsletter-image/' . $key;
        }

        return new BroadcastMail(
            orgName: $orgName,
            title: $title ?: 'Your title',
            body: $body ?: 'Your message.',
            link: $link ?: null,
            imageUrl: $withImage ? self::ORIGIN . '/composer-image' : null,
            recipientName: null,
            orgEmail: null,
            unsubscribeUrl: self::ORIGIN . '/unsubscribe',
            unsubscribeOneClickUrl: null,
            blocks: NewsletterBlocks::withImageUrls($blocks, $placeholders),
        );
    }

    /** The size Gmail measures: bytes of the rendered HTML part. */
    public static function bytes(BroadcastMail $mail): int
    {
        return strlen($mail->render());
    }
}
