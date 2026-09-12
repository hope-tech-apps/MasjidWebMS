<?php

namespace App\Services\Translation;

use PDOException;
use RuntimeException;
use Throwable;

/**
 * Translation could not be performed. Its own type, for the reason
 * SmsNotConfiguredException has one: the caller needs to distinguish "this
 * feature refused" from "this request broke the application", and a bare
 * RuntimeException reaching bootstrap/app.php's renderer is a 500 with a
 * sanitised message that tells a parent nothing.
 *
 * TWO MESSAGES, ON PURPOSE. `getMessage()` is written for the operator and goes
 * to the log; `publicMessage()` is written for a parent and goes in the 503
 * body. Errors::publicMessage() cannot do this job here — it either leaks the
 * technical message (debug) or replaces it with "An error occurred while
 * processing your request." (production), and neither is a sentence a mother
 * reading her son's class story should be shown. Keeping the pair on the
 * exception is what stops the controller from having to guess which failure it
 * caught in order to phrase it.
 *
 * Every public sentence below is ENGLISH, deliberately, on a feature whose whole
 * purpose is that the reader may not read English. There is nowhere to put an
 * Arabic one: this platform has no i18n framework, the client already holds the
 * button's label in whatever language it drew it in, and a translated error
 * would have to be produced by the very service that just failed. The client is
 * expected to show its own localised message on a 503 and to treat this string
 * as the reason, not the copy.
 */
class TranslationUnavailableException extends RuntimeException
{
    private function __construct(string $operatorMessage, private string $publicMessage, ?Throwable $previous = null)
    {
        parent::__construct($operatorMessage, 0, $previous);
    }

    /** The sentence that is safe to put in front of a parent. */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    /**
     * TRANSLATION_ENABLED is false. An operator turned the spend off; nothing is
     * broken and retrying will not help, so the parent is told to stop trying
     * rather than invited to try again.
     */
    public static function disabled(): self
    {
        return new self(
            'Translation is switched off on this deployment (translation.enabled=false).',
            'Translation is switched off at the moment.'
        );
    }

    /**
     * No ANTHROPIC_API_KEY. Distinct from disabled() in the LOG — one is a
     * decision, the other is an unfinished deployment — and identical to a
     * parent, who cannot act on either.
     */
    public static function notConfigured(): self
    {
        return new self(
            'No Anthropic API key is configured (services.anthropic.key is empty), '
            . 'so the translation service cannot be reached.',
            'Translation is not available on this site yet.'
        );
    }

    /**
     * The provider refused, timed out, or answered with something that was not a
     * translation. Transient as far as anyone here can tell, so this is the one
     * message that invites a retry.
     *
     * THE UNDERLYING MESSAGE IS NOT COPIED INTO THIS ONE, and that is a privacy
     * decision rather than a tidiness one. This exception is reported — the
     * controller calls report($e) — and the only thing the translation service
     * ever hands anybody is a teacher's words about a child. An exception message
     * composed by someone else is a string that MAY quote what it failed on:
     * `Illuminate\Database\QueryException` provably does (it interpolates its
     * bindings into the SQL, and on this feature's tables a binding is a
     * translation), and no SDK owes us a promise about the rest. The class name
     * and the code carry the half an operator acts on — a rate limit, an
     * overload, a connection refusal are each a distinct class — and cannot
     * carry a character of anybody's text.
     *
     * The previous exception is still ATTACHED, because its file and line are
     * where a genuine bug would be, and losing them would turn every unexpected
     * error in this feature into an untraceable log line. A PDOException is the
     * one kind not attached: its message is the statement with the values
     * substituted in, and the reporter prints a previous exception's message.
     */
    public static function providerFailed(Throwable $previous): self
    {
        $code = (string) $previous->getCode();
        $suffix = ($code === '' || $code === '0') ? '' : " (code {$code})";

        return new self(
            'The translation provider failed: ' . $previous::class . $suffix,
            'We could not translate this just now. Please try again in a moment.',
            $previous instanceof PDOException ? null : $previous,
        );
    }

    /**
     * The provider answered, but not with a usable translation for every string —
     * after the per-item retry. Kept apart from providerFailed() because the
     * operator fix is different: this one is a prompt or a model problem, not an
     * outage.
     */
    public static function incompleteResult(string $detail): self
    {
        return new self(
            'The translation provider returned an unusable result: ' . $detail,
            'We could not translate this just now. Please try again in a moment.'
        );
    }
}
