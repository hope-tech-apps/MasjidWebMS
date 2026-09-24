<?php

namespace App\Support\Studio;

use App\Models\Masjid;

/**
 * The facts a client actually gave, and nothing else: the only values a
 * starter page may copy (docs/manara-studio.md D8, docs/manara-studio-w1.md S4).
 *
 * Every value is the client's own text, trimmed and otherwise verbatim. The two
 * derivations (`phone_tel`, `email_mailto`) are the only transformations, and
 * they are documented link schemes, not words. A social link counts only when it
 * is an absolute http(s) URL: the provision request accepts any string there
 * (ProvisionMasjidRequest validates social fields as strings, not URLs), and a
 * button whose href is "@ourmasjid" would be a broken link the system made.
 *
 * Organisation prose (about, mission, vision) and the donation link are held
 * only as whether they exist. A starter section never copies them: the binder
 * draws them at serve time from the rows the client's answers create, and these
 * flags are how a plan knows whether that binding will have anything to show.
 *
 * `vibe` is never read (R12): it is internal and stays in the draft.
 */
final readonly class StarterFacts
{
    public const DEFAULT_LOCALE = 'en';

    /** The keys a `{fact: K}` template leaf may name. */
    public const KEYS = [
        'name', 'description', 'email', 'email_mailto', 'phone', 'phone_tel', 'address',
        'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url',
    ];

    /** Social fact => masjid_social_media_links.type, as OrganisationProvisioner writes it. */
    private const SOCIAL_TYPES = [
        'facebook_url' => 'Facebook',
        'instagram_url' => 'Instagram',
        'youtube_url' => 'YouTube',
        'whatsapp_url' => 'WhatsApp_URL',
    ];

    /**
     * @param  array<string, string>  $social  only the valid http(s) links, keyed by fact
     */
    public function __construct(
        public string $locale,
        public string $name,
        public string $description,
        public string $email,
        public string $phone,
        public string $address,
        public array $social,
        public bool $hasAbout,
        public bool $hasMissionOrVision,
        public bool $hasDonationLink,
    ) {}

    /**
     * From a Studio draft's answers, flattened: the identity keys plus the
     * content section's `about`, `mission` and `vision`, and `locale`.
     *
     * @param  array<string, mixed>  $draftFacts
     */
    public static function fromArray(array $draftFacts): self
    {
        $text = fn (string $key): string => self::text($draftFacts[$key] ?? null);

        $social = [];
        foreach (array_keys(self::SOCIAL_TYPES) as $key) {
            $social[$key] = $text($key);
        }

        return new self(
            locale: self::locale($draftFacts['locale'] ?? null),
            name: $text('name'),
            description: $text('description'),
            email: $text('email'),
            phone: $text('phone'),
            address: $text('address'),
            social: self::validLinks($social),
            hasAbout: $text('about') !== '',
            hasMissionOrVision: $text('mission') !== '' || $text('vision') !== '',
            hasDonationLink: $text('donation_link') !== '',
        );
    }

    /**
     * From an organisation's own rows: masjids, masjid_abouts, donation_links
     * and masjid_social_media_links. The writer (S8) calls this on the org it
     * has just provisioned, so the plan it writes and the preview it showed
     * read the same facts from the same answers.
     */
    public static function fromMasjid(Masjid $m, string $locale = self::DEFAULT_LOCALE): self
    {
        $m->loadMissing(['masjidAbout', 'donationLink', 'socialMediaLinks']);

        $social = [];
        foreach (self::SOCIAL_TYPES as $key => $type) {
            $row = $m->socialMediaLinks->firstWhere('type', $type);
            $social[$key] = self::text($row?->value);
        }

        return new self(
            locale: self::locale($locale),
            name: self::text($m->name),
            description: self::text($m->description),
            email: self::text($m->email),
            phone: self::text($m->phone),
            address: self::text($m->address),
            social: self::validLinks($social),
            hasAbout: self::text($m->masjidAbout?->about) !== '',
            hasMissionOrVision: self::text($m->masjidAbout?->mission) !== '' || self::text($m->masjidAbout?->vision) !== '',
            hasDonationLink: self::text($m->donationLink?->link) !== '',
        );
    }

    /**
     * One fact by key, '' when the client did not give it.
     *
     * @throws \InvalidArgumentException for a key outside KEYS, so a typo in a
     *                                   template is a failure, never a blank
     */
    public function get(string $key): string
    {
        return match ($key) {
            'name' => $this->name,
            'description' => $this->description,
            'email' => $this->email,
            'email_mailto' => $this->email === '' ? '' : 'mailto:' . $this->email,
            'phone' => $this->phone,
            'phone_tel' => self::telOf($this->phone),
            'address' => $this->address,
            'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url' => $this->social[$key] ?? '',
            default => throw new \InvalidArgumentException("\"{$key}\" is not a starter fact."),
        };
    }

    /**
     * Whether a bound placeholder's source row will have something to show.
     *
     * @throws \InvalidArgumentException for an unknown source
     */
    public function hasBound(string $source): bool
    {
        return match ($source) {
            'masjid_about.about' => $this->hasAbout,
            'masjid_about.mission_vision' => $this->hasMissionOrVision,
            'donation_link.link' => $this->hasDonationLink,
            default => throw new \InvalidArgumentException("\"{$source}\" is not a bound source."),
        };
    }

    /** The phone as a tel: link: its digits and plus signs, '' when it has no digit. */
    private static function telOf(string $phone): string
    {
        $dialable = (string) preg_replace('/[^0-9+]/', '', $phone);

        return preg_match('/\d/', $dialable) ? 'tel:' . $dialable : '';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    /**
     * Only labels that exist may be used; W1 has `en` (R15), and an unknown
     * locale reads as it rather than publishing keys with no words behind them.
     */
    private static function locale(mixed $locale): string
    {
        $available = array_keys((array) config('studio_layouts.labels', []));

        return is_string($locale) && in_array($locale, $available, true) ? $locale : self::DEFAULT_LOCALE;
    }

    /**
     * @param  array<string, string>  $links
     * @return array<string, string>
     */
    private static function validLinks(array $links): array
    {
        return array_filter($links, function (string $url): bool {
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return false;
            }

            return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
        });
    }
}
