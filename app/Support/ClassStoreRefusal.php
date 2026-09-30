<?php

namespace App\Support;

use RuntimeException;

/**
 * A class-store operation that was understood and refused (T-003.4): not enough bucks, a
 * prize out of stock, a prize that is not this class's to sell.
 *
 * `reason` is a stable machine word the SPA can branch on; the message is the sentence a
 * teacher reads. The controllers turn one into a 422 (or the status it carries) and write
 * nothing, because ClassStore throws it from INSIDE its transaction, so a refused
 * redemption rolls back whole.
 */
final class ClassStoreRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
