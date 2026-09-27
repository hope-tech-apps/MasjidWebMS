<?php

namespace App\Services\Domains;

use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareRemover;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Takes a Studio web address off Cloudflare and out of Manara (W2 S3).
 *
 * Studio removes exactly what it created and nothing else:
 *
 *  1. The row becomes `detaching`, which is outside MasjidDomain::SERVED, so
 *     the by-host lookup, CORS and payment returns stop admitting the host at
 *     once (the save forgets the CORS cache key).
 *  2. The Pages custom domain, then the DNS record, each through
 *     CloudflareRemover: removed only when Studio's own POST created it
 *     (`cf_*_created`) and a fresh read shows it unchanged. An object Studio
 *     only adopted, or one somebody has changed since, is left alone and named
 *     in the result's manual steps. A zone is never removed; one Studio created
 *     is named too.
 *  3. When every object Studio created is gone, the row is deleted through
 *     Eloquent, so its model event forgets the CORS key again.
 *
 * If a removal stops part-way (a refused token, an outage) the row stays
 * `detaching` with `last_error` and `waiting_on`, still unserved, and
 * `domains:reconcile` tries again. Each id is forgotten as soon as its object
 * is gone, so a retry never re-sends a delete that already went through.
 *
 * Imported and adopted rows are refused before anything happens: they are how
 * a live organisation was reached before Studio (R28), and S6 is their tool.
 *
 * It runs under the row's attacher lock, like every other writer of the row's
 * Cloudflare state, on the row re-read inside it.
 */
class DomainDetacher
{
    public function __construct(
        private readonly CloudflareRemover $remover,
        private readonly CloudflareService $cloudflare,
    ) {
    }

    public function detach(MasjidDomain $row, ?int $actor = null): DetachResult
    {
        $host = $row->host;

        if (! $row->ownedByStudio()) {
            return $this->refused($row);
        }

        $lock = DomainAttacher::lockFor($row->id);

        if (! $lock->get()) {
            return new DetachResult(DetachResult::BUSY, $host, error: "Studio is already working on {$host}. Try again in a minute or two.");
        }

        try {
            try {
                $row->refresh();
            } catch (ModelNotFoundException) {
                return new DetachResult(DetachResult::DETACHED, $host);
            }

            if (! $row->ownedByStudio()) {
                return $this->refused($row);
            }

            // Without the token Studio could stop serving the host but never
            // remove what it made, so a detach that has something to remove
            // does not start (one already under way keeps waiting on the token).
            if (! $this->cloudflare->isConfigured()
                && $row->status !== MasjidDomain::STATUS_DETACHING
                && $this->hasStudioObjects($row)) {
                return new DetachResult(DetachResult::REFUSED, $host,
                    error: "CLOUDFLARE_STUDIO_TOKEN is not set, so Studio cannot remove what it created in Cloudflare for {$host}. Nothing was changed.");
            }

            return $this->run($row, $actor);
        } finally {
            $lock->release();
        }
    }

    private function run(MasjidDomain $row, ?int $actor): DetachResult
    {
        if ($row->status !== MasjidDomain::STATUS_DETACHING) {
            $row->forceFill([
                'status' => MasjidDomain::STATUS_DETACHING,
                'waiting_on' => null,
                'last_error' => null,
                'stage_started_at' => null,
                'next_check_at' => null,
            ])->save();
        }

        $removed = [];
        $manual = [];

        // The redirect rule first (W2 S5): while it stands the host still
        // answers 301, and its ref is Studio's proof that Studio wrote it.
        if ($row->cf_redirect_rule_id !== null) {
            $result = $this->remover->removeRedirectRule($row);

            if ($result->is(CloudflareResult::OK, CloudflareResult::ABSENT)) {
                $row->forceFill(['cf_redirect_rule_id' => null])->save();
                $removed[] = $result->is(CloudflareResult::OK)
                    ? "the redirect rule for {$row->host} in the {$row->zone_apex} zone"
                    : "the redirect rule for {$row->host} (already gone)";
            } elseif ($result->is(CloudflareResult::CONFLICT)) {
                $manual[] = $result->error . " Check the {$row->zone_apex} zone's Redirect Rules, and delete it there if nobody needs it.";
            } else {
                return $this->stopped($row, $result, $removed, $manual);
            }
        }

        if ($row->cf_pages_domain_id !== null) {
            if (! $row->cf_pages_domain_created) {
                $manual[] = "Studio did not create the Pages custom domain for {$row->host}, so it left it. " . $row->pagesDomainRemovalStep();
            } else {
                $result = $this->remover->removePagesDomain($row);

                if ($result->is(CloudflareResult::OK, CloudflareResult::ABSENT)) {
                    $row->forceFill(['cf_pages_domain_id' => null, 'cf_pages_domain_created' => false])->save();
                    $removed[] = $result->is(CloudflareResult::OK)
                        ? "the {$row->host} custom domain on the " . config('cloudflare.pages_project') . ' Pages project'
                        : "the {$row->host} custom domain (already gone)";
                } elseif ($result->is(CloudflareResult::CONFLICT)) {
                    $manual[] = $result->error . ' Check it in Cloudflare, and remove it there if nobody needs it: ' . $row->pagesDomainRemovalStep();
                } else {
                    return $this->stopped($row, $result, $removed, $manual);
                }
            }
        }

        if ($row->cf_dns_record_id !== null) {
            if (! $row->cf_dns_record_created) {
                $manual[] = "Studio did not create the DNS record for {$row->host}, so it left it. " . $row->dnsRecordRemovalStep();
            } else {
                $result = $this->removeRecord($row);

                if ($result->is(CloudflareResult::OK, CloudflareResult::ABSENT)) {
                    $row->forceFill(['cf_dns_record_id' => null, 'cf_dns_record_created' => false])->save();
                    $removed[] = $result->is(CloudflareResult::OK)
                        ? "the {$row->host} DNS record in the {$row->zone_apex} zone"
                        : "the {$row->host} DNS record (already gone)";
                } elseif ($result->is(CloudflareResult::CONFLICT)) {
                    $manual[] = $result->error . ' Check it in Cloudflare, and delete it there if nobody needs it: ' . $row->dnsRecordRemovalStep();
                } else {
                    return $this->stopped($row, $result, $removed, $manual);
                }
            }
        }

        if ($row->cf_zone_created) {
            $manual[] = $row->zoneRemovalStep();
        }

        $row->delete();

        Log::warning('Studio detached a web address.', [
            'masjid_domain_id' => $row->id,
            'masjid_id' => (int) $row->masjid_id,
            'host' => $row->host,
            'actor_user_id' => $actor,
            'removed' => $removed,
            'manual_steps' => $manual,
        ]);

        return new DetachResult(DetachResult::DETACHED, $row->host, $removed, $manual);
    }

    /**
     * The row's DNS record, held to the shape Studio gave it: a CNAME to the
     * Pages target for a serving host; for a redirect host the proxied
     * placeholder A record (W2 S5), or, for one `domains:collapse-alias` turned
     * from serving into redirect, the CNAME it already had.
     */
    private function removeRecord(MasjidDomain $row): CloudflareResult
    {
        $cname = ['CNAME', (string) config('cloudflare.pages_target')];

        if (! $row->isRedirect()) {
            return $this->remover->removeDnsRecord($row, ...$cname);
        }

        $result = $this->remover->removeDnsRecord($row, 'A', (string) config('cloudflare.redirect_placeholder_address'));

        if ($result->is(CloudflareResult::CONFLICT)
            && ($result->data['reason'] ?? null) === 'changed'
            && ($result->data['seen']['type'] ?? null) === 'CNAME') {
            return $this->remover->removeDnsRecord($row, ...$cname);
        }

        return $result;
    }

    /**
     * Keep the row `detaching`, say why, and leave the retry to reconcile.
     *
     * @param  list<string>  $removed
     * @param  list<string>  $manual
     */
    private function stopped(MasjidDomain $row, CloudflareResult $result, array $removed, array $manual): DetachResult
    {
        $waitingOn = match ($result->outcome) {
            CloudflareResult::UNAUTHORIZED => 'token_scope',
            CloudflareResult::NOT_CONFIGURED => 'token',
            default => null,
        };

        $row->forceFill([
            'waiting_on' => $waitingOn,
            'last_error' => $result->error,
            'next_check_at' => $waitingOn === 'token_scope' ? now()->addMinutes(30) : now()->addMinutes(5),
        ])->save();

        return new DetachResult(DetachResult::PENDING, $row->host, $removed, $manual, $result->error);
    }

    /** Whether Cloudflare holds anything for the row that Studio itself made. */
    private function hasStudioObjects(MasjidDomain $row): bool
    {
        return $row->cf_redirect_rule_id !== null
            || ($row->cf_pages_domain_id !== null && $row->cf_pages_domain_created)
            || ($row->cf_dns_record_id !== null && $row->cf_dns_record_created);
    }

    private function refused(MasjidDomain $row): DetachResult
    {
        return new DetachResult(
            DetachResult::REFUSED,
            $row->host,
            manualSteps: $row->removalSteps(),
            error: "{$row->host} came from the live host map, so Studio does not detach it.",
        );
    }
}
