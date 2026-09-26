<?php

namespace App\Http\Requests\Admin\Broadcasts;

use App\Http\Requests\BaseFormRequest;
use App\Services\Broadcast\Newsletter\NewsletterBlocks;

/**
 * What the composer's live preview posts while the admin is still typing.
 *
 * LENIENT, because the admin is mid-edit rather than sending: title and body
 * may be empty, pictures are not uploaded yet (the preview names them by key
 * and the SPA shows its own local copy), and an unfinished layout or link is
 * not a 422. Every block starts empty, so a preview that refused incomplete
 * blocks would stop updating for most of the time a newsletter is being built.
 * The controller instead renders the blocks that are complete and returns the
 * SAME NewsletterBlocks::errors() the send runs, as a list beside the email, so
 * the preview still says exactly what the send would refuse.
 *
 * Only a request that is not a draft at all (a title longer than the column,
 * a field of the wrong type) is refused here.
 */
class PreviewNewsletterRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'blocks' => StoreBroadcastRequest::decodeBlocks($this->input('blocks')),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'body' => 'nullable|string',
            // Checked by the controller as a web address and reported with the
            // block errors; a link still being typed must not freeze the preview.
            'link' => 'nullable|string|max:2048',
            'blocks' => 'nullable',
            // Only whether the composer image is attached; the file itself
            // stays in the browser.
            'with_image' => 'nullable|in:0,1',
        ];
    }

    /**
     * Everything the send would refuse in this layout, keyed like Laravel's
     * errors (`blocks.3.alt`) — shown beside the preview, never a 422.
     *
     * @return array<string, string>
     */
    public function newsletterErrors(): array
    {
        $errors = NewsletterBlocks::errors($this->input('blocks'), null);

        $link = $this->input('link');
        if (
            $this->input('blocks') !== null
            && is_string($link) && trim($link) !== ''
            && NewsletterBlocks::webUrl($link) === null
        ) {
            $errors['link'] = StoreBroadcastRequest::NEWSLETTER_LINK_ERROR;
        }

        return $errors;
    }

    /**
     * The blocks that are finished, in their stored shape: what the preview
     * renders while the rest are still being filled in.
     *
     * @return list<array<string, mixed>>
     */
    public function newsletterBlocks(): array
    {
        return NewsletterBlocks::normalize(NewsletterBlocks::complete($this->input('blocks')));
    }
}
