<?php

namespace App\Support\Studio;

use App\Models\StudioDraft;
use RuntimeException;

/**
 * A draft that is no longer a draft: it was provisioned, by this request's twin
 * or by an earlier click. The provision endpoint answers 409 with the
 * organisation that exists, so the SPA shows it instead of offering a retry
 * that would create a second one.
 */
final class StudioDraftConflict extends RuntimeException
{
    public function __construct(public readonly StudioDraft $draft)
    {
        parent::__construct("Studio draft {$draft->id} is already {$draft->status}.");
    }
}
