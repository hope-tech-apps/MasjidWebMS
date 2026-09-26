<?php

namespace App\Http\Requests\Admin\Broadcasts;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastChannel;
use App\Http\Requests\Admin\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\BaseFormRequest;
use App\Models\Masjid;
use App\Services\Broadcast\Newsletter\NewsletterBlocks;
use App\Services\Broadcast\Newsletter\NewsletterPicture;
use App\Services\Broadcast\Newsletter\NewsletterPreviewMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The composer's request boundary (T-008).
 *
 * ## Announcement rules are BORROWED, never restated
 *
 * When the announcement channel is selected, the payload is mapped onto
 * announcement fields and validated against
 * StoreAnnouncementRequest::rules() ITSELF. Nothing here re-types
 * "end_date after start_date" or "image required, max 25MB". Had it done so, the
 * day somebody relaxed the announcements screen the composer would have kept
 * rejecting what the screen accepted, and the two would have quietly disagreed
 * about what a valid announcement is. Borrowing means they cannot.
 *
 * A practical consequence, and it is the announcement's rule speaking rather
 * than a composer opinion: an image is REQUIRED to publish to the feed, and the
 * window's end must fall strictly after its start. Compose without the
 * announcement channel and neither applies.
 *
 * ## Push + a narrowed audience is REJECTED, not widened
 *
 * `mobile_app_users` carries no contact_id (App\Models\MobileAppUser), so a push
 * cannot be aimed at four named families. Accepting the combination and sending
 * to every device anyway would tell an admin they had sent something private
 * when they had broadcast it. The request refuses instead, and says why.
 *
 * ## The newsletter layout is EMAIL's, and optional
 *
 * `blocks` arrives as a JSON string (the SPA posts FormData, which cannot nest)
 * alongside `block_images[<key>]` files. Its rules live in NewsletterBlocks so
 * the preview endpoint applies the same ones. Title and body stay required
 * whatever the layout says: they are what the feed, push, the board and a text
 * message carry, and the email opens with them above the blocks.
 *
 * Blocks without the email channel are REFUSED rather than stored and ignored —
 * an admin who built a newsletter and ticked only push would otherwise be told
 * "sent" about a layout nobody received.
 *
 * With a layout, three more things are checked before anything is stored: the
 * "More details" link must be a web address (the newsletter prints nothing
 * else), every picture must be decodable within NewsletterPicture::MAX_PIXELS,
 * and the rendered email must fit under Gmail's clipping size, or the
 * unsubscribe footer would be hidden (NewsletterBlocks::MAX_EMAIL_BYTES).
 */
class StoreBroadcastRequest extends BaseFormRequest
{
    public const NEWSLETTER_LINK_ERROR = 'The link under your message must be a full web address, starting https://. '
        . 'The newsletter email shows no other kind of link.';

    /**
     * Composer field <- announcement field, for reporting a borrowed rule's
     * failure against the input the admin actually typed.
     *
     * @var array<string, string>
     */
    private const ANNOUNCEMENT_FIELD_MAP = [
        'title' => 'title',
        'summary' => 'body',
        'details' => 'body',
        'text' => 'body',
        'start_date' => 'starts_on',
        'end_date' => 'ends_on',
        'image' => 'image',
    ];

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Absent audience means everyone — normalised here rather than
            // downstream so validation and persistence cannot disagree
            // (.claude/rules/verticals.md makes the same argument for org_type).
            'audience' => $this->input('audience') ?: BroadcastAudience::EVERYONE->value,
            'channels' => array_values(array_unique((array) $this->input('channels', []))),
            'blocks' => self::decodeBlocks($this->input('blocks')),
        ]);
    }

    /**
     * The `blocks` input as an array, null when absent, or the raw value when it
     * is not a JSON list — which NewsletterBlocks::errors then refuses by name.
     * Shared with the preview request so both read the field the same way.
     */
    public static function decodeBlocks(mixed $raw): mixed
    {
        if ($raw === null || $raw === '' || $raw === '[]') {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : $raw;
        }

        return $raw;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'link' => 'nullable|url|max:2048',
            // The announcement channel tightens this (its own rules make an
            // image required and cap it at 25MB); this is the floor that applies
            // to a push/email/signage-only send.
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',

            'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d',

            'channels' => 'required|array|min:1',
            'channels.*' => ['string', Rule::in(BroadcastChannel::values())],

            'audience' => ['required', Rule::in(BroadcastAudience::values())],
            'contact_ids' => 'array',
            'contact_ids.*' => 'integer',

            // The service a `service` audience addresses. Constrained to THIS
            // masjid's services in the rule itself: `services` carries no
            // BelongsToMasjid trait, so nothing downstream would catch an id
            // belonging to another organisation.
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where(
                    fn ($q) => $q->where('masjid_id', $this->route('masjid_id'))->whereNull('deleted_at')
                ),
            ],

            // The tag a `tag` audience addresses. Constrained to THIS
            // organisation in the rule: the resolver would find nobody for a
            // foreign tag (ContactTag is tenant-scoped), but a broadcast that
            // silently addresses nobody is a worse answer than a 422 now.
            'tag_id' => [
                'nullable',
                'integer',
                Rule::exists('contact_tags', 'id')->where(
                    fn ($q) => $q->where('masjid_id', $this->route('masjid_id'))
                ),
            ],

            // Nullable = send now. A past value is treated as "now" rather than
            // rejected: an admin who spent ninety seconds on the form should not
            // lose it to a clock.
            'scheduled_at' => 'nullable|date',

            // The newsletter layout. Its shape is checked in withValidator by
            // NewsletterBlocks, which words each problem against the block the
            // admin can see; only the files are Laravel rules. SVG is not
            // accepted: it is a document that can carry script, not a picture.
            'blocks' => 'nullable',
            'block_images' => 'nullable|array|max:' . NewsletterBlocks::MAX_IMAGES,
            'block_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:' . NewsletterBlocks::MAX_IMAGE_KB,
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $channels = $this->selectedChannelValues();

            if (in_array(BroadcastChannel::ANNOUNCEMENT->value, $channels, true)) {
                $this->applyAnnouncementRules($validator);
            }

            // Push + a CHOSEN LIST of contacts is still refused, and the reason
            // has changed rather than gone away. Devices now carry a
            // `contact_id`, but only for the minority of handsets somebody has
            // actually signed into — the app is usable without an account, so
            // most rows are NULL forever. An admin who hand-picks fifty contacts
            // and reaches the six of them who happen to have signed in has been
            // told they sent something they did not send, which is the same
            // failure the original refusal existed to prevent, pointing the
            // other way.
            //
            // A SERVICE audience does not have that problem and is allowed: it
            // addresses people who opted in THROUGH the app, so having an
            // account on a device is intrinsic to the audience rather than an
            // accident that silently shrinks it.
            if (
                in_array(BroadcastChannel::PUSH->value, $channels, true)
                && $this->input('audience') === BroadcastAudience::TAG->value
            ) {
                // Same failure as a chosen list: a tag names people, and most
                // of them are signed in on no device.
                $validator->errors()->add(
                    'channels',
                    'Push cannot be sent to a tag: most registered devices are not signed in, '
                    . 'so the send would silently reach only a fraction of the people tagged. '
                    . 'Send push to everyone, or drop the push channel.'
                );
            }

            if (
                $this->input('audience') === BroadcastAudience::TAG->value
                && empty($this->input('tag_id'))
            ) {
                $validator->errors()->add('tag_id', 'Choose the tag this broadcast is for.');
            }

            if (
                in_array(BroadcastChannel::PUSH->value, $channels, true)
                && $this->input('audience') === BroadcastAudience::CONTACTS->value
            ) {
                $validator->errors()->add(
                    'channels',
                    'Push cannot be narrowed to a chosen list of contacts: most registered devices are not signed in, '
                    . 'so the send would silently reach only a fraction of the people you picked. '
                    . 'Send push to everyone, address a service instead, or drop the push channel.'
                );
            }

            if (
                $this->input('audience') === BroadcastAudience::SERVICE->value
                && empty($this->input('service_id'))
            ) {
                $validator->errors()->add('service_id', 'Choose the service this broadcast is for.');
            }

            if (
                $this->input('audience') === BroadcastAudience::CONTACTS->value
                && empty($this->input('contact_ids'))
            ) {
                $validator->errors()->add('contact_ids', 'Select at least one contact for a contacts audience.');
            }

            $blocks = $this->input('blocks');
            if ($blocks !== null) {
                if (! in_array(BroadcastChannel::EMAIL->value, $channels, true)) {
                    $validator->errors()->add(
                        'blocks',
                        'The newsletter layout is sent by email only. Tick the Email channel, or remove the blocks.'
                    );
                }

                $blockErrors = NewsletterBlocks::errors($blocks, array_map('strval', array_keys($this->blockImageFiles())));
                foreach ($blockErrors as $field => $message) {
                    $validator->errors()->add($field, $message);
                }

                $link = $this->input('link');
                if (
                    is_string($link) && $link !== ''
                    && ! $validator->errors()->has('link')
                    && NewsletterBlocks::webUrl($link) === null
                ) {
                    $validator->errors()->add('link', self::NEWSLETTER_LINK_ERROR);
                }

                // Pictures and size are checked on a layout that is otherwise
                // sendable (newsletterImages() reads the normalised blocks), so
                // the admin fixes the blocks first and these only once.
                if ($blockErrors === []) {
                    foreach ($this->newsletterImages() as $key => $file) {
                        if (($problem = NewsletterPicture::problem($file)) !== null) {
                            $validator->errors()->add("block_images.{$key}", $problem);
                        }
                    }

                    if (! $validator->errors()->hasAny(['title', 'body', 'link'])) {
                        $this->checkEmailSize($validator);
                    }
                }
            }
        });
    }

    /**
     * Refuse a newsletter whose email would be clipped by Gmail, which hides the
     * unsubscribe footer. Measured on the same draft email the preview shows
     * (NewsletterPreviewMail), so the size the admin was warned about there is
     * the size refused here.
     */
    private function checkEmailSize(ValidatorContract $validator): void
    {
        $masjid = Masjid::find($this->route('masjid_id'));

        $bytes = NewsletterPreviewMail::bytes(NewsletterPreviewMail::build(
            orgName: (string) $masjid?->name,
            title: (string) $this->input('title'),
            body: (string) $this->input('body'),
            link: $this->input('link') ?: null,
            withImage: $this->file('image') instanceof UploadedFile,
            blocks: (array) $this->newsletterBlocks(),
        ));

        if (($tooBig = NewsletterBlocks::emailSizeError($bytes)) !== null) {
            $validator->errors()->add('blocks', $tooBig);
        }
    }

    /**
     * The validated layout in its stored shape, or null for a broadcast
     * without one. Only meaningful after validation has passed.
     *
     * @return list<array<string, mixed>>|null
     */
    public function newsletterBlocks(): ?array
    {
        $blocks = $this->input('blocks');

        return is_array($blocks) && $blocks !== [] ? NewsletterBlocks::normalize($blocks) : null;
    }

    /**
     * The uploaded pictures the layout actually uses, keyed by upload key. A
     * file sent under a key no block names is not stored.
     *
     * @return array<string, UploadedFile>
     */
    public function newsletterImages(): array
    {
        $blocks = $this->newsletterBlocks();
        if ($blocks === null) {
            return [];
        }

        return array_intersect_key($this->blockImageFiles(), array_flip(NewsletterBlocks::imageKeys($blocks)));
    }

    /** @return array<string, UploadedFile> */
    private function blockImageFiles(): array
    {
        $files = $this->file('block_images');

        return is_array($files)
            ? array_filter($files, fn ($file) => $file instanceof UploadedFile)
            : [];
    }

    /** @return array<int, string> */
    public function selectedChannelValues(): array
    {
        return array_values(array_filter(
            (array) $this->input('channels', []),
            fn ($c) => is_string($c) && BroadcastChannel::tryFrom($c) !== null
        ));
    }

    /** @return array<int, BroadcastChannel> */
    public function selectedChannels(): array
    {
        return array_map(
            fn (string $c) => BroadcastChannel::from($c),
            $this->selectedChannelValues()
        );
    }

    /**
     * Run the announcement's OWN rules over the mapped payload and surface any
     * failure against the composer field the admin filled in.
     */
    private function applyAnnouncementRules(ValidatorContract $validator): void
    {
        $probe = Validator::make(
            $this->announcementAttributes(),
            (new StoreAnnouncementRequest())->rules()
        );

        if ($probe->passes()) {
            return;
        }

        foreach ($probe->errors()->messages() as $field => $messages) {
            $target = self::ANNOUNCEMENT_FIELD_MAP[$field] ?? $field;

            foreach ($messages as $message) {
                $validator->errors()->add(
                    $target,
                    'Announcements feed: ' . $message
                );
            }
        }
    }

    /**
     * The composer payload expressed as an announcement.
     *
     * Mirrors exactly what AnnouncementChannel will persist — including the one
     * body filling both `details` and `text` — so validation cannot pass on a
     * shape the driver will not write.
     *
     * @return array<string, mixed>
     */
    private function announcementAttributes(): array
    {
        $body = (string) $this->input('body', '');

        return [
            'title' => $this->input('title'),
            'summary' => Str::limit($body, 160),
            'details' => $body,
            'text' => $body,
            'start_date' => $this->input('starts_on'),
            'end_date' => $this->input('ends_on'),
            'image' => $this->file('image'),
        ];
    }
}
