<?php

namespace App\Exceptions;

use App\Models\MasjidDomain;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * An organisation cannot be permanently deleted while Cloudflare still holds
 * something Studio recorded for one of its hosts (W2 S2).
 *
 * `masjid_domains.masjid_id` cascades in the database, and a cascade fires no
 * model event: force-deleting the organisation would delete the rows and leave
 * the CNAME, the Pages custom domain and any zone Studio created in Cloudflare
 * with nothing recording them. Raised by Masjid's `forceDeleting` hook, so the
 * delete never starts.
 *
 * Deliberately not an HTTP exception: nothing in the admin API force-deletes an
 * organisation (MasjidsController only trashes), and the one caller today is
 * the demo-school rollback, an operator command.
 */
class DomainsStillAttached extends RuntimeException
{
    /** @param  Collection<int, MasjidDomain>  $domains  the rows that carry Cloudflare state */
    public function __construct(
        public readonly int $masjidId,
        public readonly Collection $domains,
    ) {
        parent::__construct(self::describe($masjidId, $domains));
    }

    /** @param  Collection<int, MasjidDomain>  $domains */
    private static function describe(int $masjidId, Collection $domains): string
    {
        $lines = ["Organisation #{$masjidId} cannot be permanently deleted: Cloudflare still holds what Studio recorded for "
            . $domains->count() . ' of its web address(es). Remove those first.'];

        $studio = $domains->filter(fn (MasjidDomain $domain) => $domain->ownedByStudio());
        $other = $domains->reject(fn (MasjidDomain $domain) => $domain->ownedByStudio());

        if ($studio->isNotEmpty()) {
            $lines[] = "Studio attached {$studio->pluck('host')->implode(', ')}: run `php artisan domains:release {$masjidId}` to see what it would remove"
                . ' (a dry run), then again with --execute --operator=... --reason=... (and --force while the organisation is not trashed).'
                . ' It removes only what Studio created and lists the rest.';
        }

        foreach ($other as $domain) {
            $lines[] = "#{$domain->id} {$domain->host}:";

            foreach ($domain->removalSteps() as $step) {
                $lines[] = "  - {$step}";
            }
        }

        return implode("\n", $lines);
    }
}
