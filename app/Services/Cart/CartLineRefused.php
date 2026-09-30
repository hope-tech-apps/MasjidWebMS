<?php

namespace App\Services\Cart;

use RuntimeException;

/**
 * A line that may not go into a basket, in the words the public endpoint answers with
 * (CartLineAdder). The sibling of CartCheckoutRefused, for the same reason: a bare
 * RuntimeException also covers a database error, whose message carries SQL, so the
 * controller shows exactly these messages and nothing else.
 *
 * Three shapes, because the doors answer three ways:
 *
 *   - fields():   a field bag, the `{status: 'failed', data: {field: [...]}}` 422 the form door
 *                 gives an answer that does not validate, keyed the way that door keys it;
 *   - refused():  one sentence, 422: the line could not be sold (`gone`, with the source's own
 *                 reason), a form that takes a file, a basket that is full;
 *   - conflict(): one sentence, 409: the client's own idempotency key was already used for a
 *                 different line.
 */
final class CartLineRefused extends RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     */
    private function __construct(string $message, private readonly int $answerStatus, private readonly ?array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, list<string>>  $errors  field => messages
     */
    public static function fields(array $errors): self
    {
        return new self('The line could not be added.', 422, $errors);
    }

    public static function refused(string $sentence): self
    {
        return new self($sentence, 422, null);
    }

    public static function conflict(string $sentence): self
    {
        return new self($sentence, 409, null);
    }

    /** The HTTP status to answer with. */
    public function answerStatus(): int
    {
        return $this->answerStatus;
    }

    /**
     * The field bag, or null when the refusal is one sentence.
     *
     * @return array<string, list<string>>|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }
}
