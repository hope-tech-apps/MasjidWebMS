<?php

namespace App\Support;

use App\Models\Masjid;

/**
 * The allowlist behind SectionType::EMBED — third-party widgets a masjid can place
 * on a page (their Wix events calendar, a YouTube khutbah, a map of the car park).
 *
 * WHY AN ALLOWLIST AT ALL. An embed section renders somebody else's document inside
 * the masjid's own site. A free-text "paste any URL" box would hand every admin —
 * none of whom are security engineers — a way to put an arbitrary attacker-controlled
 * page in front of their congregation, wearing the masjid's domain and chrome. That is
 * a credential-phishing surface, and the congregation has no way to tell it apart from
 * the real thing. So the section stores a PROVIDER KEY plus a URL, the URL must belong
 * to that provider, and the renderer builds the iframe. Tenant HTML is never rendered.
 *
 * TWO KINDS OF ENTRY:
 *
 *  - Fleet providers (youtube, google_maps, google_form, wix_widget) have hosts fixed
 *    here in code. They are the same for every masjid.
 *
 *  - `site_page` has NO hosts of its own. It resolves against the masjid's OWN
 *    `website_link`, so a masjid may frame a page of its existing website and nothing
 *    else. This is the entry that actually unblocks MEC: their events calendar is a
 *    Wix widget we cannot scrape, but meccharlotte.org/calendar frames cleanly, and
 *    embedding it is per-tenant safe by construction — the worst a compromised admin
 *    can do is show a page they already control.
 *
 *    Hardcoding meccharlotte.org into a fleet-wide list would have been the quick
 *    version of this and would have grown a tenant domain per onboarding.
 *
 * VALIDATION IS SERVER-SIDE. normalize() is called from the section form requests, so
 * an off-allowlist URL cannot be stored at all. The Nuxt renderer re-checks the host
 * before it emits a frame, but that check is a second line, not the control: anything
 * that only runs in the browser is advisory.
 */
final class EmbedProviders
{
    /** The one provider whose hosts come from the tenant rather than from this file. */
    public const SITE_PAGE = 'site_page';

    /**
     * sandbox / allow are per-provider because they are a least-privilege decision, not
     * a constant: a map needs no autoplay, a form needs `allow-forms`, only YouTube
     * needs fullscreen and picture-in-picture.
     *
     * On `allow-same-origin` sitting next to `allow-scripts`: the well-known warning
     * about that pair is that framed content can reach up and remove its own sandbox —
     * but that is only true when the frame is SAME-ORIGIN WITH US. Every host below is
     * a third party, so the pair grants the widget its own origin (which YouTube and
     * Wix both need for storage) without granting it anything of ours. The renderer
     * enforces the premise rather than trusting it: if an embed URL ever resolves to
     * the site's own origin, it drops allow-same-origin.
     *
     * `default_aspect` is what the section falls back to when the admin sets no height —
     * a 16:9 video and a full calendar page want very different boxes.
     */
    private const PROVIDERS = [
        'youtube' => [
            'label' => 'YouTube video',
            'description' => 'A khutbah, lecture or livestream hosted on YouTube.',
            'hosts' => ['youtube.com', 'youtube-nocookie.com', 'youtu.be'],
            'sandbox' => 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation',
            'allow' => 'accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen',
            'default_aspect' => '16:9',
        ],
        'google_maps' => [
            'label' => 'Google Map',
            'description' => 'A map of the masjid, its car park, or an event venue.',
            'hosts' => ['google.com', 'maps.google.com', 'www.google.com'],
            // A map needs neither scripts-with-storage nor forms.
            'sandbox' => 'allow-scripts allow-popups allow-popups-to-escape-sandbox',
            'allow' => 'fullscreen',
            'default_aspect' => '4:3',
        ],
        'google_form' => [
            'label' => 'Google Form',
            'description' => 'A Google Form — surveys, sign-ups, volunteer rotas.',
            'hosts' => ['docs.google.com', 'forms.gle'],
            'sandbox' => 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
            'allow' => '',
            'default_aspect' => null, // forms are tall and variable; use an explicit height
        ],
        'wix_widget' => [
            'label' => 'Wix widget',
            'description' => 'A widget served from Wix — most often an events calendar.',
            // The hosts Wix actually serves embeddable widgets from. The masjid's own
            // Wix *site* is not here on purpose: that is what site_page is for.
            'hosts' => ['wixapps.net', 'wixsite.com', 'filesusr.com', 'parastorage.com', 'wix.com'],
            'sandbox' => 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
            'allow' => '',
            'default_aspect' => null,
        ],
        self::SITE_PAGE => [
            'label' => 'A page from our own website',
            'description' => "A page of this masjid's existing website — e.g. an events calendar that still lives there.",
            'hosts' => [], // resolved from the masjid's website_link at validation time
            'sandbox' => 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
            'allow' => '',
            'default_aspect' => null,
        ],
    ];

    /** Provider keys, for the `in:` validation rule and the admin dropdown. */
    public static function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * The admin editor's provider list. `site_page` is offered only once the masjid has
     * a website_link to resolve against — offering it before then would render a
     * dropdown entry that rejects every URL the admin could type into it.
     */
    public static function options(?Masjid $masjid = null): array
    {
        $out = [];

        foreach (self::PROVIDERS as $key => $p) {
            if ($key === self::SITE_PAGE && self::siteHost($masjid) === null) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $p['label'],
                'description' => $p['description'],
                'hosts' => self::hostsFor($key, $masjid),
            ];
        }

        return $out;
    }

    /** The iframe attributes for a provider, for the renderer and the API resource. */
    public static function attributes(string $provider): array
    {
        $p = self::PROVIDERS[$provider] ?? null;

        if ($p === null) {
            // Unknown provider: the most restrictive frame we can emit. Reached only if
            // a row predates a provider being removed, since normalize() rejects
            // unknown providers on the way in.
            return ['sandbox' => 'allow-scripts', 'allow' => '', 'default_aspect' => null];
        }

        return [
            'sandbox' => $p['sandbox'],
            'allow' => $p['allow'],
            'default_aspect' => $p['default_aspect'],
        ];
    }

    /** Hosts a provider may serve from, including the per-tenant `site_page` case. */
    public static function hostsFor(string $provider, ?Masjid $masjid = null): array
    {
        if ($provider === self::SITE_PAGE) {
            $host = self::siteHost($masjid);

            if ($host === null) {
                return [];
            }

            // `www.` is an alias for the same site, and which of the two a masjid typed
            // into its settings is arbitrary. Without this, a tenant registered as
            // www.meccharlotte.org cannot embed meccharlotte.org/calendar — the same
            // page, refused on a technicality nobody outside this file can see.
            //
            // Deliberately just the leading `www.` label, NOT the parent domain: for a
            // masjid on a shared host, `mec.wixsite.com` must never widen to
            // `wixsite.com` and let every other Wix site through.
            $alias = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;

            return [$host, $alias];
        }

        return self::PROVIDERS[$provider]['hosts'] ?? [];
    }

    /**
     * Validate a (provider, url) pair and return the URL to store, or null to reject.
     *
     * The return value is REBUILT from the parsed parts rather than handed back as
     * typed, so anything that survives is in a shape the renderer can rely on. Rejects:
     *
     *   - unknown providers
     *   - any scheme but https (an http frame on an https page is blocked as mixed
     *     content anyway — it would render as an empty box, not as a warning)
     *   - userinfo, i.e. https://youtube.com@evil.example/. The host is `evil.example`;
     *     a naive `str_contains($url, 'youtube.com')` check reads it as YouTube. This is
     *     the classic allowlist bypass and the reason this function parses rather
     *     than matches.
     *   - an explicit port — no allowlisted provider uses one
     *   - hosts outside the provider's list, matched as the host itself or a subdomain
     *     of it (never as a substring: `notyoutube.com` must not pass for `youtube.com`)
     *   - non-ASCII hostnames, which can render as a homograph of an allowlisted one
     */
    public static function normalize(string $provider, string $url, ?Masjid $masjid = null): ?string
    {
        if (!array_key_exists($provider, self::PROVIDERS)) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        // Credentials in the authority are only ever an attempt to make the host look
        // like something it is not.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = self::canonicalHost($parts['host']);
        if ($host === null) {
            return null;
        }

        $allowed = self::hostsFor($provider, $masjid);
        if ($allowed === [] || !self::hostMatches($host, $allowed)) {
            return null;
        }

        // Rebuild. Path/query are preserved verbatim (a Google Maps embed is almost all
        // query string, and a Wix widget URL carries signed parameters); the fragment is
        // dropped because it never addresses anything inside a frame.
        return 'https://' . $host . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * The registered website of a masjid, reduced to a hostname. Null when the masjid
     * has no website on file or what is on file does not parse — in which case
     * `site_page` simply is not available to that tenant.
     */
    public static function siteHost(?Masjid $masjid): ?string
    {
        $link = trim((string) ($masjid?->website_link ?? ''));
        if ($link === '') {
            return null;
        }

        // Admins type "meccharlotte.org" as often as they type the full URL, and
        // parse_url reads a bare domain as a PATH, not a host.
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $link)) {
            $link = 'https://' . $link;
        }

        $host = parse_url($link, PHP_URL_HOST);

        return is_string($host) ? self::canonicalHost($host) : null;
    }

    /** Lowercased, trailing-dot-stripped, and ASCII-only, or null if it is not a host. */
    private static function canonicalHost(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        // Hostnames only — no IPs, no punycode homographs, no empty labels.
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * Exact host, or a subdomain of an allowed host. The leading-dot test is what stops
     * `evilyoutube.com` and `youtube.com.evil.example` from passing for `youtube.com`.
     */
    private static function hostMatches(string $host, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            $candidate = strtolower($candidate);

            if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
                return true;
            }
        }

        return false;
    }
}
