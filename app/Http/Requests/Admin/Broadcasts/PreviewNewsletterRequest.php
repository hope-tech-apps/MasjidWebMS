<?php

namespace App\Http\Requests\Admin\Broadcasts;

use App\Http\Requests\BaseFormRequest;
use App\Services\Broadcast\Newsletter\NewsletterBlocks;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * What the composer's live preview posts while the admin is still typing.
 *
 * Deliberately looser than StoreBroadcastRequest in exactly two ways, both
 * because the admin is mid-edit rather than sending: title and body may be
 * empty, and pictures are not uploaded yet (the preview names them by key and
 * the SPA shows its own local copy). Everything else — every block rule, every
 * address check — is the SAME NewsletterBlocks::errors() the send runs, so the
 * preview cannot show a layout the send would refuse, and its 422 doubles as
 * live feedback on what still needs fixing.
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
            'link' => 'nullable|url|max:2048',
            'blocks' => 'nullable',
            // Only whether the composer image is attached; the file itself
            // stays in the browser.
            'with_image' => 'nullable|in:0,1',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            foreach (NewsletterBlocks::errors($this->input('blocks'), null) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function newsletterBlocks(): array
    {
        $blocks = $this->input('blocks');

        return is_array($blocks) && $blocks !== [] ? NewsletterBlocks::normalize($blocks) : [];
    }
}
