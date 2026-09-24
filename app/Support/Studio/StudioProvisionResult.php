<?php

namespace App\Support\Studio;

use App\Models\Masjid;

/**
 * What one committed provision-from-draft produced, for the 201 body: the
 * organisation, what the provisioner's optional steps did, and how the
 * after-commit steps went.
 *
 * `afterCommit` exists because those steps run after the organisation is real:
 * a failure there is reported, never turned into an error, since an error
 * invites a retry of something that already happened and the results screen
 * must not call an invite sent when it was not.
 */
final readonly class StudioProvisionResult
{
    /**
     * @param  array{invites_sent: int, invites_failed: int, warnings: list<string>}  $afterCommit
     */
    public function __construct(
        public Masjid $masjid,
        public ProvisionContext $context,
        public array $afterCommit,
    ) {}
}
