<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SectionType;
use App\Models\Masjid;

/**
 * The grant rule and the content rule for SectionType::SHOP, shared by the four
 * requests that can write a section (Sections store/update, PageSections
 * store/update), beside ValidatesEmbedContent and ValidatesVideoSection and for the
 * same reason: the SPA is not the only way in, so the rule lives where every writer
 * passes through.
 *
 * Two rules, attached to two attributes:
 *
 *  - `section_type` (validateGrantedSectionType): a type that requiresGrant() can be
 *    created, or a section changed to it, only for an organisation that HAS the grant.
 *    The organisation is the one in the URL, never the viewer, and a SuperAdmin is not
 *    exempt: the public shop API answers the dark 404 for an organisation without the
 *    grant, so a section built for it would draw nothing for anyone.
 *    A section that is ALREADY of the type is left alone, so an existing shop section
 *    stays editable after the grant is switched off, exactly as it stays readable and
 *    deletable (those two never reach a request at all).
 *  - `content` (validateShopContent): the four keys of a shop section and nothing else.
 *    Silent for every other section type.
 *
 * Uses the two lookups of ValidatesEmbedContent, declared abstract below so the
 * dependency is stated rather than assumed.
 */
trait ValidatesShopSection
{
    /** The only keys a shop section holds; SectionType::SHOP->defaultContent() is the same four. */
    private const SHOP_CONTENT_KEYS = ['heading', 'category', 'max_items', 'show_view_all'];

    abstract private function resolvedSectionType(): ?SectionType;

    abstract private function storedSectionType(): ?SectionType;

    abstract private function embedMasjid(): ?Masjid;

    /**
     * Attach as a `section_type` closure rule. Silent for every type no grant governs.
     *
     * The sentence is in the catalogue's own label and wording, as the `capability:` gate
     * (EnsureOrgCapability) refuses with: "{label} is not switched on for this
     * organisation."
     */
    protected function validateGrantedSectionType(mixed $sectionType, callable $fail): void
    {
        $type = is_string($sectionType) ? SectionType::tryFrom($sectionType) : null;
        $grant = $type?->requiresGrant();

        if ($grant === null) {
            return;
        }

        // Already this type: not a creation and not a change to it.
        if ($this->storedSectionType() === $type) {
            return;
        }

        // Fail closed: an organisation that cannot be read has no grant.
        if ($this->embedMasjid()?->hasCapability($grant) === true) {
            return;
        }

        $fail(config("capabilities.{$grant}.label", $grant)
            . " is not switched on for this organisation, so it cannot have a {$type->label()} section.");
    }

    /**
     * Attach as a `content` closure rule. Silent for every other section type.
     *
     * Strict about types, because the renderer reads these as they are stored: a
     * `max_items` of "8" or a `show_view_all` of "yes" is a mistake to be told about,
     * not a value to coerce. An absent key is fine (the renderer reads it as the
     * default); an unknown key is refused rather than stored, so a typo cannot sit in
     * a published row doing nothing.
     */
    protected function validateShopContent(mixed $content, callable $fail): void
    {
        if ($this->resolvedSectionType() !== SectionType::SHOP || ! is_array($content)) {
            return;
        }

        $unknown = array_diff(array_map('strval', array_keys($content)), self::SHOP_CONTENT_KEYS);

        if ($unknown !== []) {
            $fail('A shop section holds only a heading, a category, how many products to show and whether to link to the shop. Remove: ' . implode(', ', $unknown) . '.');
        }

        if (array_key_exists('heading', $content) && $content['heading'] !== null
            && (! is_string($content['heading']) || mb_strlen($content['heading']) > 120)) {
            $fail('A shop heading must be text of at most 120 characters.');
        }

        if (array_key_exists('category', $content) && $content['category'] !== null
            && (! is_string($content['category']) || mb_strlen($content['category']) > 60)) {
            $fail('A shop category must be text of at most 60 characters, or empty for every category.');
        }

        if (array_key_exists('max_items', $content)
            && (! is_int($content['max_items']) || $content['max_items'] < 1 || $content['max_items'] > 24)) {
            $fail('How many products to show must be a whole number from 1 to 24.');
        }

        if (array_key_exists('show_view_all', $content) && ! is_bool($content['show_view_all'])) {
            $fail('Whether to link to the full shop must be on or off.');
        }
    }
}
