<?php

namespace App\Support;

use App\Enums\SectionType;
use App\Models\ContactReason;
use App\Models\DonationLink;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\Section;

/**
 * Phase 1 content-unification.
 *
 * For the four "content" section types whose canonical data already lives in a
 * dedicated model the mobile apps read, this binder OVERRIDES the section's own
 * stored copy with the model's data at Web-V1 serialize time — so each piece of
 * content is edited in ONE place (the dedicated admin screen) and the website
 * renders the same source the apps do.
 *
 *   about_us       <- MasjidAbout.about   (+ mission as subtitle, aboutImage)
 *   mission_vision <- MasjidAbout.mission / .vision (+ mission/vision icons)
 *   donation       <- DonationLink.title / .message / image (+ link)
 *   contact_form   <- masjid contact block (phone/email/address) + ContactReason list
 *
 * Three more types resolve a REFERENCE they store into the thing it points at,
 * which is the same idea one step further — the section holds an id, the binder
 * inlines the public shape of the row:
 *
 *   form           <- the referenced Form's schema + its public wording
 *   embed          <- the re-validated frame src + its sandbox policy
 *   offering       <- the referenced Offering's PUBLIC payload (T-006g):
 *                     copy, ACTIVE fee plans in integer minor units, places
 *                     left, whether registration is open, intake questions.
 *                     App\Support\OfferingPublicPayload owns that shape and the
 *                     public GET /api/v1/offerings/{slug} calls the same
 *                     presenter, so the two can never drift.
 *
 * CRITICAL: the returned array preserves the EXACT `content` JSON shape the Nuxt
 * section components expect (see app/components/section/* in the site repo). Only
 * the model-owned fields are overridden; presentation-only fields the section
 * carries (button_text, show_map, headings, layout) are preserved from the
 * stored content. When the model is empty, the section's stored value is kept as
 * a graceful fallback so nothing renders blank.
 *
 * This is read-only: it never writes to the section or the models.
 */
class SectionContentBinder
{
    /**
     * Return the section content with the dedicated model injected for the four
     * entity-bound types; for every other type, return the stored content as-is.
     *
     * @param  array<string,mixed>  $content  The section's stored content (already decoded).
     */
    public static function bind(Section $section, array $content, ?int $masjidId): array
    {
        $masjidId = $masjidId ?? $section->masjid_id;

        // An explicit per-section bind directive (section.settings.bind) lets a
        // GENERIC section type (image_text_grid / grid_cards used as the About
        // story or the Mission/Vision cards) be sourced from MasjidAbout WITHOUT
        // changing presentation — only the model-owned body text is overridden.
        // Takes precedence over the section-type binding below.
        switch (self::settingBind($section)) {
            case 'about_text':
                return self::bindAboutText($content, $masjidId);
            case 'mission_vision_cards':
                return self::bindMissionVisionCards($content, $masjidId);
        }

        return match ($section->section_type) {
            SectionType::ABOUT_US       => self::bindAbout($content, $masjidId),
            SectionType::MISSION_VISION => self::bindMissionVision($content, $masjidId),
            SectionType::DONATION       => self::bindDonation($content, $masjidId),
            SectionType::CONTACT_FORM   => self::bindContact($content, $masjidId),
            SectionType::FORM           => self::bindForm($content, $masjidId),
            SectionType::EMBED          => self::bindEmbed($content, $masjidId),
            SectionType::OFFERING       => self::bindOffering($content, $masjidId),
            default                     => $content,
        };
    }

    /**
     * Resolve an embed section into something the renderer can draw directly: the
     * normalised frame URL plus the sandbox/allow policy for its provider.
     *
     * The URL is re-validated HERE, at serve time, not just at save time. Two reasons:
     *
     *  1. The allowlist is code, and code changes. Tightening EmbedProviders — or a
     *     masjid changing its website_link — must stop already-stored rows from
     *     rendering, rather than leaving whatever was legal on the day it was saved
     *     framed inside the masjid's site forever.
     *  2. It keeps the allowlist in ONE place. The site never has to carry its own copy
     *     of which hosts belong to which provider; it is handed a resolved src and the
     *     exact sandbox to use.
     *
     * Fails closed: a URL that no longer validates is blanked, and the renderer draws
     * nothing at all. A widget that silently disappears is recoverable; one that frames
     * an unvetted origin inside the masjid's own domain is not.
     */
    private static function bindEmbed(array $content, ?int $masjidId): array
    {
        $provider = is_string($content['provider'] ?? null) ? trim($content['provider']) : '';
        $url = is_string($content['url'] ?? null) ? trim($content['url']) : '';

        $masjid = $masjidId ? Masjid::find($masjidId) : null;
        $src = ($provider !== '' && $url !== '')
            ? EmbedProviders::normalize($provider, $url, $masjid)
            : null;

        if ($src === null) {
            return array_merge($content, ['url' => '', 'iframe' => null]);
        }

        $attributes = EmbedProviders::attributes($provider);

        return array_merge($content, [
            'url' => $src,
            'iframe' => [
                'src' => $src,
                'sandbox' => $attributes['sandbox'],
                'allow' => $attributes['allow'],
                // Only a suggestion: an explicit aspect/height in the content wins.
                'default_aspect' => $attributes['default_aspect'],
            ],
        ]);
    }

    /**
     * Inline the referenced form's schema into the section content.
     *
     * The section stores only `form_id`; the renderer needs the whole definition. Doing
     * it here means the site fetches a page ONCE and has everything it needs to draw the
     * form — no second request, and no window in which the page has rendered but the
     * form has not.
     *
     * Scoped by masjid: a section can only ever inline a form belonging to the same
     * masjid, so a mis-set form_id surfaces as a missing form rather than as another
     * tenant's questions appearing on someone else's website.
     *
     * `form` is null when the id is unset, points at nothing, belongs to another masjid,
     * or the form is soft-deleted. The renderer treats all four the same way: render
     * nothing rather than an empty shell that collects submissions into a void.
     */
    private static function bindForm(array $content, ?int $masjidId): array
    {
        $content['form'] = null;

        $formId = $content['form_id'] ?? null;

        if (! $formId || ! $masjidId) {
            return $content;
        }

        $form = Form::query()
            ->where('masjid_id', $masjidId)
            ->whereKey($formId)
            ->first();

        if (! $form) {
            return $content;
        }

        // Public shape ONLY. `settings` carries notification recipients and the identity
        // map, which are operational detail and must not be published; the renderer gets
        // just the parts it draws with.
        $settings = $form->settings ?? [];

        $content['form'] = [
            'id' => $form->id,
            'slug' => $form->slug,
            'name' => $form->name,
            'description' => $form->description,
            'schema' => $form->schema,
            'accepting' => $form->acceptsSubmissions(),
            'closed_reason' => $form->closedReason(),
            'settings' => [
                'submitButtonLabel' => $settings['submitButtonLabel'] ?? 'Submit',
                'successTitle' => $settings['successTitle'] ?? null,
                'successBody' => $settings['successBody'] ?? null,
                'successNextSteps' => $settings['successNextSteps'] ?? [],
                'intro' => $settings['intro'] ?? null,
                // feeRule() resolves the tier in force today, and carries the whole
                // schedule so the page can show what the price becomes and when.
                'fee' => $form->feeRule(),
            ],
        ];

        return $content;
    }

    /**
     * Inline the referenced offering's PUBLIC payload into the section content
     * (T-006g).
     *
     * The section stores only `offering_id`; the renderer needs the copy, the
     * fee plans, the places left, whether registration is open at all, and the
     * intake questions. Resolving it here means the site fetches a page ONCE and
     * can draw the whole registration block — no second request, and no window
     * in which the page has rendered but the price has not. Exactly what
     * bindForm() does for a `form` section, for exactly the same reasons.
     *
     * THE SHAPE IS NOT DEFINED HERE. App\Support\OfferingPublicPayload owns
     * every decision about what an anonymous visitor may see, and the public
     * GET /api/v1/offerings/{slug} endpoint calls the same presenter — so a
     * field tightened in one place is tightened in both. A hand-rolled second
     * copy here is how a private field ends up on a published page.
     *
     * Scoped by masjid, so a mis-set offering_id surfaces as a missing offering
     * rather than as another tenant's program (and its prices) appearing on
     * someone else's website.
     *
     * `offering` is null when the id is unset, points at nothing, belongs to
     * another masjid, is soft-deleted, or is switched off. The renderer treats
     * all five the same way: draw nothing, rather than an empty shell with a
     * Register button that every submission would be refused by.
     */
    private static function bindOffering(array $content, ?int $masjidId): array
    {
        $content['offering'] = null;

        $offeringId = $content['offering_id'] ?? null;

        if (! $offeringId || ! $masjidId) {
            return $content;
        }

        $content['offering'] = OfferingPublicPayload::forId($masjidId, (int) $offeringId);

        return $content;
    }

    /**
     * about_us shape (DynamicSection.vue inline About block reads):
     *   { title, subtitle, text, image_url, button_text }
     * Model: MasjidAbout.about -> text; MasjidAbout.mission -> subtitle; aboutImage -> image_url.
     * title + button_text are presentation-only and kept from stored content.
     */
    protected static function bindAbout(array $content, ?int $masjidId): array
    {
        $about = self::loadAbout($masjidId);

        if ($about) {
            if (self::filled($about->about)) {
                $content['text'] = $about->about;
            }
            if (self::filled($about->mission)) {
                $content['subtitle'] = $about->mission;
            }
            $imageUrl = $about->aboutImage?->getUrl();
            if (self::filled($imageUrl)) {
                $content['image_url'] = $imageUrl;
            }
        }

        return $content;
    }

    /**
     * mission_vision shape (admin editor + MissionVisionSectionContent TS type):
     *   { heading, layout, items: [ { type, title, content, icon_url }, ... ] }
     * We rebuild items from the model: a Mission card and a Vision card. Field
     * names mirror the editor exactly (`type`, `title`, `content`, `icon_url`) so
     * the stored JSON shape is preserved. heading + layout are presentation-only
     * and kept from the stored content.
     */
    protected static function bindMissionVision(array $content, ?int $masjidId): array
    {
        $about = self::loadAbout($masjidId);

        if (!$about) {
            return $content;
        }

        $hasMission = self::filled($about->mission);
        $hasVision  = self::filled($about->vision);

        // Only override the stored items when the model actually has content,
        // so an empty MasjidAbout doesn't wipe a section the admin authored.
        if (!$hasMission && !$hasVision) {
            return $content;
        }

        $items = [];

        if ($hasMission) {
            $items[] = [
                'type'     => 'mission',
                'title'    => 'Our Mission',
                'content'  => $about->mission,
                'icon_url' => $about->missionIcon?->getUrl(),
            ];
        }

        if ($hasVision) {
            $items[] = [
                'type'     => 'vision',
                'title'    => 'Our Vision',
                'content'  => $about->vision,
                'icon_url' => $about->visionIcon?->getUrl(),
            ];
        }

        $content['items'] = $items;

        return $content;
    }

    /**
     * donation shape (Donate.vue reads): { title, subtitle, image_url, button_text }
     * Donate.vue fetches the LINK itself from the mobile donation-link endpoint, so
     * `link` is presentation-irrelevant to the current site — but we surface it for
     * forward-compat. Model: DonationLink.title -> title; .message -> subtitle;
     * image -> image_url; .link -> link. button_text is presentation-only.
     */
    protected static function bindDonation(array $content, ?int $masjidId): array
    {
        $donation = self::loadDonation($masjidId);

        if ($donation) {
            if (self::filled($donation->title)) {
                $content['title'] = $donation->title;
            }
            if (self::filled($donation->message)) {
                $content['subtitle'] = $donation->message;
            }
            if (self::filled($donation->link)) {
                $content['link'] = $donation->link;
            }
            $imageUrl = $donation->image?->getUrl();
            if (self::filled($imageUrl)) {
                $content['image_url'] = $imageUrl;
            }
        }

        return $content;
    }

    /**
     * contact_form shape (ContactUs.vue / IsContactUs.vue read):
     *   { title, subtitle, button_text, show_map }
     * Those presentation fields are kept. We additionally inject the masjid
     * contact block and the active ContactReason list so the site can render
     * real contact details + a reasons dropdown sourced from the model.
     */
    protected static function bindContact(array $content, ?int $masjidId): array
    {
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        if ($masjid) {
            $content['contact'] = [
                'phone'   => $masjid->phone,
                'email'   => $masjid->email,
                'address' => $masjid->address,
            ];
        }

        $content['reasons'] = ContactReason::query()
            ->where('masjid_id', $masjidId)
            ->where('is_active', true)
            ->orderBy('order')
            ->pluck('name')
            ->values()
            ->all();

        return $content;
    }

    protected static function settingBind(Section $section): ?string
    {
        $settings = $section->settings;
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        return is_array($settings) ? ($settings['bind'] ?? null) : null;
    }

    /**
     * A generic image_text_grid section used as the About "story": only the body
     * `text` is sourced from MasjidAbout.about. The section's title/subtitle/image
     * (heading + logo) are presentation and kept, so the layout is unchanged —
     * this only removes the duplicate copy of the About prose.
     */
    protected static function bindAboutText(array $content, ?int $masjidId): array
    {
        $about = self::loadAbout($masjidId);

        if ($about && self::filled($about->about)) {
            $content['text'] = $about->about;
        }

        return $content;
    }

    /**
     * A generic grid_cards section used for Mission/Vision: each card's body
     * `text` is sourced from the model by matching the card's title keyword.
     * Card order, titles and icons are kept; only the prose is unified.
     */
    protected static function bindMissionVisionCards(array $content, ?int $masjidId): array
    {
        $about = self::loadAbout($masjidId);

        if (!$about || empty($content['items']) || !is_array($content['items'])) {
            return $content;
        }

        foreach ($content['items'] as &$item) {
            $title = strtolower((string) ($item['title'] ?? ''));
            if (str_contains($title, 'mission') && self::filled($about->mission)) {
                $item['text'] = $about->mission;
            } elseif (str_contains($title, 'vision') && self::filled($about->vision)) {
                $item['text'] = $about->vision;
            }
        }
        unset($item);

        return $content;
    }

    protected static function loadAbout(?int $masjidId): ?MasjidAbout
    {
        if (!$masjidId) {
            return null;
        }

        return MasjidAbout::with(['aboutImage', 'missionIcon', 'visionIcon'])
            ->where('masjid_id', $masjidId)
            ->first();
    }

    protected static function loadDonation(?int $masjidId): ?DonationLink
    {
        if (!$masjidId) {
            return null;
        }

        return DonationLink::with('image')
            ->where('masjid_id', $masjidId)
            ->first();
    }

    protected static function filled($value): bool
    {
        return $value !== null && $value !== '';
    }
}
