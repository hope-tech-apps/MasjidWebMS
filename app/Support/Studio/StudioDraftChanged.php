<?php

namespace App\Support\Studio;

use App\Models\StudioDraft;
use RuntimeException;

/**
 * A draft that changed after the provision read it: an autosave from another
 * tab (or this one's, still in flight), or a new logo, committed before the
 * provision took the draft's lock. Provisioning then would build the
 * organisation from answers the draft no longer holds, and the draft, marked
 * provisioned, would stop being the record of what was made. The endpoint
 * answers 409 with nothing written, and the SPA reloads the draft for another
 * look before Provision is pressed again.
 */
final class StudioDraftChanged extends RuntimeException
{
    public const MESSAGE = 'This draft changed while it was being provisioned, so nothing was created. '
        . 'Review the latest answers and press Provision again.';

    public function __construct(public readonly StudioDraft $draft)
    {
        parent::__construct("Studio draft {$draft->id} changed before it could be provisioned.");
    }
}
