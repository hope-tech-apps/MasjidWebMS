<?php

namespace App\Support\Guides;

use RuntimeException;

/** Reports only a rule and relative filename, never guide contents. */
class GuideValidationException extends RuntimeException
{
    public function __construct(string $rule, string $file)
    {
        // A hostile manifest may put control characters or markup in a filename.
        $file = preg_replace('/[^a-zA-Z0-9_.\/ -]/', '?', substr($file, 0, 200));
        parent::__construct("Guide rule {$rule}: {$file}");
    }
}
