<?php

namespace App\Support;

use App\Enums\SectionType;
use App\Models\ContactReason;
use App\Models\DonationLink;
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
            default                     => $content,
        };
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
