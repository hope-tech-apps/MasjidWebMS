<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A move of a student to another class that the server will not make.
 *
 * It carries the sentence the office reads and the status it is answered with:
 * 422 when the request itself is wrong (not a student, not a class, a date that
 * cannot be), 409 when the rosters are in a state the move must not touch. The
 * preview returns the same sentence with `can_move: false`, so what the office
 * read before the tap is what it is told after it.
 *
 * `getMessage()` is the first sentence a screen prints. A refusal about
 * guardians can name several of them, so the sentence may be several lines
 * joined by a newline: one per guardian, then what to do.
 *
 * Nothing has been written when this is thrown: the move runs in one
 * transaction and every refusal is raised before, or instead of, a commit.
 */
class RosterMoveRefused extends RuntimeException
{
    /** The roster changed between the read and the lock, or two saves met. */
    public const CHANGED = 'This roster changed while the move was being saved. Nothing was moved. Look again and retry.';

    /**
     * What the server decided under its locks differs from what the dialog
     * showed: another case, another first day, or another joining day.
     */
    public const LOOK_AGAIN = 'This changed while you were looking. Nothing was moved. Read what will happen below, then move again.';

    /**
     * @param  array{id:int,name:string}|null  $openGroup  the class whose roster
     *         the office has to open to clear this refusal, when there is one.
     *         The dialog offers it as a button, so every refusal can be acted on
     *         from the screen it appears on.
     */
    public function __construct(
        string $sentence,
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        private readonly ?array $openGroup = null,
    ) {
        parent::__construct($sentence);
    }

    public static function conflict(string $sentence, ?array $openGroup = null): self
    {
        return new self($sentence, Response::HTTP_CONFLICT, $openGroup);
    }

    public static function lookAgain(): self
    {
        return self::conflict(self::LOOK_AGAIN);
    }

    public static function changed(): self
    {
        return self::conflict(self::CHANGED);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array{id:int,name:string}|null */
    public function openGroup(): ?array
    {
        return $this->openGroup;
    }
}
