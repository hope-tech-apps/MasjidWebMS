<?php

namespace App\Support;

/**
 * The languages the parent portal speaks, and the facts about each one that
 * the server must know rather than guess.
 *
 * Added 2026-09-21 when the owner asked for Urdu, Pashto, Dari and Spanish
 * beside Arabic ("Add them, portal labels too"). Before that the whole list was
 * `['ar']` in config/translation.php and the prompt knew one name. A list of
 * five with no metadata would have let a tag reach the model as its own bare
 * code and left every caller to work out for itself which way the text runs,
 * so the facts live here, once, and config/translation.php only says which of
 * them this deployment offers.
 *
 * ---------------------------------------------------------------------------
 * DARI IS `fa-AF`, NOT `prs`
 * ---------------------------------------------------------------------------
 *
 * `prs` is Dari's ISO 639-3 code and it is what Windows calls its Dari locale
 * (`prs-AF`), so it is the obvious pick and it is the wrong one for a web page.
 * CLDR aliases it away: `Intl.getCanonicalLocales('prs')` answers `fa-AF` in
 * every current browser and in Node, so a page that stored `prs` would be
 * rewritten to `fa-AF` by the very date formatter it handed it to, and two
 * spellings of one language would reach the cache (`content_translations` is
 * keyed on the tag) as two languages — one paragraph paid for twice. `fa-AF` is
 * what browsers report for an Afghan Persian user, what `Intl.DisplayNames`
 * names "دری", and what the model reads unambiguously once the prompt spells it
 * out as Dari. The portal normalises a stored or browser-reported `prs` to
 * `fa-AF` on the client; the server accepts only `fa-AF`, so there is one tag
 * per language on the wire.
 *
 * ---------------------------------------------------------------------------
 * THE PROMPT NAME IS A PROMPT, NOT A LABEL
 * ---------------------------------------------------------------------------
 *
 * `prompt` is what AnthropicTranslator puts after "Translate faithfully into".
 * It carries the script and the variety because those are the two ways a
 * correct-looking translation goes wrong for these families: Urdu returned in
 * Roman letters, Dari returned as Iranian Farsi, Pashto in a script the parent
 * does not read. It is never shown to a parent.
 */
final class PortalLanguage
{
    /**
     * @var array<string,array{name:string,prompt:string,dir:'ltr'|'rtl'}>
     */
    public const LANGUAGES = [
        'ar' => [
            'name' => 'Arabic',
            'prompt' => 'Modern Standard Arabic',
            'dir' => 'rtl',
        ],
        'ur' => [
            'name' => 'Urdu',
            'prompt' => 'Urdu, written in Urdu (Perso-Arabic) script — never romanised',
            'dir' => 'rtl',
        ],
        'ps' => [
            'name' => 'Pashto',
            'prompt' => 'Pashto as written in Afghanistan, in Pashto (Perso-Arabic) script',
            'dir' => 'rtl',
        ],
        'fa-AF' => [
            'name' => 'Dari',
            'prompt' => 'Dari — the Persian of Afghanistan, in Perso-Arabic script, using Afghan rather than Iranian vocabulary',
            'dir' => 'rtl',
        ],
        'es' => [
            'name' => 'Spanish',
            'prompt' => 'neutral Latin American Spanish',
            'dir' => 'ltr',
        ],
    ];

    /**
     * The tags a parent may ask for on THIS deployment: config's list, filtered
     * to the ones this class describes.
     *
     * The filter is the point. An operator who adds `fr` to
     * config/translation.php without adding it here would otherwise open a tag
     * that reaches the model as a bare code and has no known direction; with
     * it, an undescribed tag is simply not offered, and the request's
     * `Rule::in` refuses it like any other stranger.
     *
     * @return array<int,string>
     */
    public static function allowed(): array
    {
        $configured = (array) config('translation.languages', ['ar']);

        return array_values(array_filter(
            $configured,
            static fn ($tag): bool => is_string($tag) && isset(self::LANGUAGES[$tag]),
        ));
    }

    public static function isKnown(string $tag): bool
    {
        return isset(self::LANGUAGES[$tag]);
    }

    /** "rtl" or "ltr". An unknown tag is LTR, which is also what the page does with it. */
    public static function dir(string $tag): string
    {
        return self::LANGUAGES[$tag]['dir'] ?? 'ltr';
    }

    public static function isRtl(string $tag): bool
    {
        return self::dir($tag) === 'rtl';
    }

    /**
     * The language as the prompt names it. An unknown tag is returned as
     * itself, which cannot happen through the endpoint (allowed() filters to
     * known tags) and is unambiguous enough if a future caller skips that.
     */
    public static function promptName(string $tag): string
    {
        return self::LANGUAGES[$tag]['prompt'] ?? $tag;
    }
}
