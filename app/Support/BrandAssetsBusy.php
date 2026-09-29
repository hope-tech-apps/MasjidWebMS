<?php

namespace App\Support;

use RuntimeException;

/**
 * Another regeneration for the same organisation holds the lock and did not
 * finish inside the wait (BrandAssets::regenerate). The route answers 409; the
 * upload hook skips with a warning.
 */
final class BrandAssetsBusy extends RuntimeException
{
    public const MESSAGE = 'The brand images are already being made. Try again in a moment.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
