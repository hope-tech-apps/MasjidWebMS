<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SectionType;
use App\Models\Masjid;
use App\Support\EmbedProviders;
use App\Support\TenantContext;

/**
 * Server-side validation for SectionType::EMBED content, shared by the four requests
 * that can write a section (Sections store/update, PageSections store/update).
 *
 * This lives here rather than in the admin SPA because the SPA is not the only way in:
 * the same endpoints back the Manara Assistant's tools and anything else holding an
 * admin token. A check that runs only in the browser guards the UI, not the data.
 *
 * The rule is narrow on purpose — it fires only when the section really is an embed,
 * and it validates only `provider` + `url`. Everything else in the content blob is
 * presentational and the renderer already tolerates it being absent or junk.
 */
trait ValidatesEmbedContent
{
    /**
     * Attach as a `content` closure rule. Silent for every other section type.
     */
    protected function validateEmbedContent(mixed $content, callable $fail): void
    {
        if ($this->resolvedSectionType() !== SectionType::EMBED) {
            return;
        }

        if (!is_array($content)) {
            $fail('An embedded widget needs a provider and a URL.');

            return;
        }

        $provider = is_string($content['provider'] ?? null) ? trim($content['provider']) : '';
        $url = is_string($content['url'] ?? null) ? trim($content['url']) : '';

        if ($provider === '' || $url === '') {
            $fail('An embedded widget needs both a provider and a URL.');

            return;
        }

        if (!in_array($provider, EmbedProviders::keys(), true)) {
            $fail("\"{$provider}\" is not a widget provider we support.");

            return;
        }

        $masjid = $this->embedMasjid();

        if (EmbedProviders::normalize($provider, $url, $masjid) !== null) {
            return;
        }

        // Say WHICH hosts would have been accepted. "Invalid URL" sends an admin round
        // and round a form that is rejecting them for a reason they cannot see — and
        // the commonest causes here (http:// pasted from an old page, or a URL from the
        // wrong provider in the dropdown) are invisible without the list.
        $hosts = EmbedProviders::hostsFor($provider, $masjid);

        $fail($hosts === []
            ? 'Add your website address under the masjid\'s settings before embedding a page from it.'
            : 'That URL cannot be embedded. It must be an https:// address on: ' . implode(', ', $hosts) . '.');
    }

    /**
     * The type this save will land on. On an update the request may legitimately omit
     * `section_type` (it is `sometimes`), so fall back to what the section already is —
     * otherwise an admin could edit an existing embed's URL to anything at all simply
     * by not resending the type.
     */
    private function resolvedSectionType(): ?SectionType
    {
        $input = $this->input('section_type');

        if (is_string($input) && $input !== '') {
            return SectionType::tryFrom($input);
        }

        $sectionId = $this->route('section_id');

        if ($sectionId === null) {
            return null;
        }

        $type = \App\Models\Section::whereKey($sectionId)->value('section_type');

        // The column is cast on the model, but value() bypasses casting on some paths.
        return $type instanceof SectionType ? $type : (is_string($type) ? SectionType::tryFrom($type) : null);
    }

    /**
     * The tenant this section belongs to — needed only by the `site_page` provider,
     * whose allowed host is the masjid's own registered website.
     *
     * Every section route carries {masjid_id}; TenantContext is the fallback for a
     * caller that reaches these requests another way.
     */
    private function embedMasjid(): ?Masjid
    {
        $masjidId = $this->route('masjid_id') ?? app(TenantContext::class)->get();

        return $masjidId === null ? null : Masjid::find((int) $masjidId);
    }
}
