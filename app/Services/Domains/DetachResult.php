<?php

namespace App\Services\Domains;

/**
 * What one DomainDetacher::detach() call came to (W2 S3).
 *
 *  - `detached`: every object Studio created is gone from Cloudflare and the
 *    row is deleted. `manual_steps` names anything Studio did not create and
 *    so left alone.
 *  - `pending`: it stopped part-way (a refused token, an outage). The row
 *    stays `detaching`, is no longer served, and `domains:reconcile` retries.
 *  - `refused`: nothing was done: the row is imported or adopted, and never
 *    Studio's to detach.
 *  - `busy`: another writer holds the row; nothing was done.
 */
final class DetachResult
{
    public const DETACHED = 'detached';
    public const PENDING = 'pending';
    public const REFUSED = 'refused';
    public const BUSY = 'busy';

    /**
     * @param  list<string>  $removed  what was removed from Cloudflare, in words
     * @param  list<string>  $manualSteps  what is left for a person
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $host,
        public readonly array $removed = [],
        public readonly array $manualSteps = [],
        public readonly ?string $error = null,
    ) {
    }

    /** @return array{outcome: string, host: string, removed: list<string>, manual_steps: list<string>, error: ?string} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'host' => $this->host,
            'removed' => $this->removed,
            'manual_steps' => $this->manualSteps,
            'error' => $this->error,
        ];
    }
}
