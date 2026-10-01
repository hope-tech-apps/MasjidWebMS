<?php

namespace App\Services\Shop;

use RuntimeException;

/**
 * Thrown by ProductWriter::update() when the save names a `lock_version` other than the product's
 * own, under the product's row lock: another editor (or a picture change) got there first, and
 * saving would put back what they changed. The controller answers 409 and nothing was written.
 */
final class ProductChangedElsewhere extends RuntimeException
{
    public const MESSAGE = 'This product was changed by someone else. Reload it and make your change again.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
