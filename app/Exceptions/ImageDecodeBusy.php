<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Another large picture was being processed and the wait ran out
 * (App\Support\HeavyImageDecode). Nothing was stored; sending again in a moment
 * works. Deliberately not an HTTP exception: the caller decides the response.
 */
class ImageDecodeBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another large picture is being prepared right now. Nothing was sent; please try again in a moment.');
    }
}
