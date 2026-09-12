<?php

namespace App\Services\Translation;

/**
 * The translation seam.
 *
 * One method, and the shape of it is the contract: a caller hands over an
 * associative array of strings and gets the SAME KEYS back with translated
 * values. The keys are the caller's own — a DOM id, a record slug, whatever the
 * parent portal used to address the paragraph on screen — and this layer never
 * interprets them. That is what lets the HTTP endpoint take TEXT rather than
 * record ids (see App\Http\Controllers\Family\TranslationsController) without
 * this service knowing anything about groups, posts or children.
 *
 * ## Why an interface for one implementation
 *
 * So the test suite can bind a fake and count calls. Every guarantee this
 * feature makes that is worth pinning — a second identical request is served
 * from the cache, two organisations do not share cache rows, a provider failure
 * is a clean 503 and never a half-translated payload — is a statement about how
 * many times the provider was called and with what. A suite that could only
 * assert on the response body would have to reach the network to test any of
 * them, and would then be testing Anthropic's uptime. `tests/Feature/
 * FamilyTranslationTest.php` binds a counting fake here and nowhere else.
 *
 * ## The contract
 *
 * - Keys are never invented, renamed or reordered. A key in the result is one
 *   the caller sent, carrying the translation of the text it sent with it.
 * - A key may be ABSENT, and absence is the ONLY way to say "not translated".
 *   An implementation is allowed to come back with less than it was asked for —
 *   a spend ceiling reached part way through is the reason that exists (see
 *   `translation.max_provider_calls`) — but it must never return a key carrying
 *   its own source text. A parent who does not read English cannot tell a
 *   translated string from an untranslated one, so a caller has to be able to,
 *   and a missing key is the one signal that cannot be misread. The caller
 *   renders the original it already has for those keys AND SAYS SO.
 * - Nothing at all is a THROW, not an empty array. A result with no keys in it
 *   is a request that achieved nothing, and a 200 for it would spin a button and
 *   change nothing on the screen.
 * - Every failure is a TranslationUnavailableException. Not configured, switched
 *   off, provider refused, provider returned nonsense — one type, because the
 *   caller's response to all four is identical and a bare RuntimeException
 *   reaching the renderer is a 500 with a stack trace where a parent expected a
 *   sentence.
 */
interface Translator
{
    /**
     * Translate every value in `$texts` into `$targetLang`.
     *
     * @param  array<string,string>  $texts  caller's key => source text
     * @return array<string,string>  the caller's keys => translated text; a key
     *                               that could not be translated is ABSENT, never
     *                               present carrying its source text
     *
     * @throws TranslationUnavailableException when NOTHING could be translated.
     */
    public function translate(array $texts, string $targetLang): array;

    /**
     * Could a translation happen at all right now — switched on, and holding a
     * credential?
     *
     * Asked so a surface can decline to OFFER what it cannot deliver.
     * `.claude/rules/environments.md` puts it as a rule for every outbound
     * integration: "An integration with no credentials must no-op, not throw…
     * Construct on any configuration, expose `isConfigured()`". The family
     * portal reads this into its response envelope, so a deployment with no
     * ANTHROPIC_API_KEY shows a parent no translate button rather than a button
     * whose only outcome is a 503 they cannot act on.
     *
     * It answers about CONFIGURATION, never about a particular string: a true
     * here is not a promise that the provider will answer, which is why
     * `translate()` still refuses on its own terms.
     */
    public function isConfigured(): bool;
}
