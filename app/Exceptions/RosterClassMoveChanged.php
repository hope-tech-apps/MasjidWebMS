<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * A whole-class move that was not started, because the roster is no longer
 * what the office was shown.
 *
 * The request names every student with what the dialog showed for them. Before
 * anything is locked or written, each is decided again (App\Support\
 * RosterClassMove, the pre-flight). One difference, for one student, refuses
 * the whole request: the office confirmed a list, and a list that is partly
 * something else is not the one it confirmed.
 *
 * It is the single move's refusal (409, a sentence) and carries one thing
 * more: the students that differ, each as the preview would now list them, so
 * the dialog can mark them where the office is reading.
 */
class RosterClassMoveChanged extends RosterMoveRefused
{
    public const SENTENCE = 'This roster changed while you were looking. Nothing was moved. Look again.';

    /**
     * @param  list<array<string, mixed>>  $students  each as a row of the class preview
     */
    public function __construct(private readonly array $students)
    {
        parent::__construct(self::SENTENCE, Response::HTTP_CONFLICT);
    }

    /** @return list<array<string, mixed>> */
    public function students(): array
    {
        return $this->students;
    }
}
