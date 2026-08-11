<?php

namespace App\Http\Requests\Admin\Broadcasts;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastChannel;
use App\Http\Requests\Admin\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\BaseFormRequest;
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
 */
class StoreBroadcastRequest extends BaseFormRequest
{
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
        ]);
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

            // Nullable = send now. A past value is treated as "now" rather than
            // rejected: an admin who spent ninety seconds on the form should not
            // lose it to a clock.
            'scheduled_at' => 'nullable|date',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $channels = $this->selectedChannelValues();

            if (in_array(BroadcastChannel::ANNOUNCEMENT->value, $channels, true)) {
                $this->applyAnnouncementRules($validator);
            }

            if (
                in_array(BroadcastChannel::PUSH->value, $channels, true)
                && $this->input('audience') === BroadcastAudience::CONTACTS->value
            ) {
                $validator->errors()->add(
                    'channels',
                    'Push cannot be narrowed to selected contacts: registered devices carry no link to a contact record. '
                    . 'Send push to everyone, or drop the push channel from this broadcast.'
                );
            }

            if (
                $this->input('audience') === BroadcastAudience::CONTACTS->value
                && empty($this->input('contact_ids'))
            ) {
                $validator->errors()->add('contact_ids', 'Select at least one contact for a contacts audience.');
            }
        });
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
