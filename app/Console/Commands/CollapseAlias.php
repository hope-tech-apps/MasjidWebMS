<?php

namespace App\Console\Commands;

use App\Models\MasjidDomain;
use App\Models\MasjidDomainChange;
use App\Services\Cloudflare\CloudflareRemover;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use App\Services\Domains\DomainAttacher;
use App\Services\Domains\DomainProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Turn one host of a two-host client into a redirect, freeing its Pages slot
 * (W2 S5; step 1 of docs/runbooks/pages-domain-ceiling.md).
 *
 * The host named must be a Studio `serving` row whose apex/www sibling is also
 * a serving host of the same organisation: the sibling is the canonical host
 * it will redirect to. Nothing a visitor sees changes: the host answers 301 to
 * its sibling instead of serving the same site.
 *
 * With --execute, in this order, under the row's attacher lock:
 *
 *  1. add the Single Redirect rule (appended; the zone's rules survive);
 *  2. probe the host until it answers 301 to the sibling (a few tries, for the
 *     rule to reach the edge). If it never does, the rule is taken out again
 *     and NOTHING else changes;
 *  3. only then make the row a `redirect` row, so the lookup and CORS stop
 *     answering for a host that no longer reaches the renderer;
 *  4. remove the host's Pages custom domain through CloudflareRemover, only if
 *     Studio's own POST created it; otherwise it is named for a person.
 *
 * Its DNS record stays: a proxied CNAME is all a redirect needs. Only a zone
 * Studio created or one in `cloudflare.redirect_zones` is written to, and a
 * live client attached before Studio (imported rows) is never touched.
 *
 * A DRY RUN unless --execute: it says what it would do and sends nothing.
 */
class CollapseAlias extends Command
{
    protected $signature = 'domains:collapse-alias
        {domain_id : The masjid_domains id of the host that should redirect}
        {--execute : Do it; without it nothing is sent or changed}
        {--operator= : Who is running it, for the ledger (required with --execute)}
        {--reason= : Why, for the ledger (required with --execute)}
        {--json : Print the result as JSON}';

    protected $description = 'Make one host of an apex/www pair redirect to the other, freeing its Pages custom-domain slot. A dry run unless --execute.';

    /** How many times, and how far apart, the 301 is looked for before giving up. */
    public const VERIFY_ATTEMPTS = 6;

    public const VERIFY_EVERY_SECONDS = 5;

    public function handle(CloudflareService $cloudflare, CloudflareRemover $remover, DomainProbe $probe): int
    {
        $row = MasjidDomain::find((int) $this->argument('domain_id'));

        if ($row === null) {
            return $this->finish(['outcome' => 'refused', 'reason' => 'No web address #' . $this->argument('domain_id') . '.'], self::FAILURE);
        }

        $sibling = $this->sibling($row);
        $refusal = $this->refusal($row, $sibling);

        if ($refusal !== null) {
            return $this->finish(['id' => $row->id, 'host' => $row->host, 'outcome' => 'refused', 'reason' => $refusal], self::FAILURE);
        }

        $plan = [
            'id' => $row->id,
            'host' => $row->host,
            'redirects_to' => $sibling->host,
            'removes_pages_domain' => $row->cf_pages_domain_id !== null && $row->cf_pages_domain_created,
        ];

        if (! $this->option('execute')) {
            return $this->finish($plan + ['outcome' => 'would_collapse'], self::SUCCESS);
        }

        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));

        if ($operator === '' || $reason === '') {
            return $this->finish($plan + ['outcome' => 'refused', 'reason' => '--execute needs --operator and --reason: they go into the ledger with the change. Nothing was changed.'], self::FAILURE);
        }

        if (! $cloudflare->isConfigured()) {
            return $this->finish($plan + ['outcome' => 'refused', 'reason' => 'CLOUDFLARE_STUDIO_TOKEN is not set. Nothing was changed.'], self::FAILURE);
        }

        $lock = DomainAttacher::lockFor($row->id);

        if (! $lock->get()) {
            return $this->finish($plan + ['outcome' => 'refused', 'reason' => "Studio is working on {$row->host} right now. Try again in a minute or two."], self::FAILURE);
        }

        try {
            return $this->collapse($row->refresh(), $sibling, $plan, $cloudflare, $remover, $probe, $operator, $reason);
        } finally {
            $lock->release();
        }
    }

    /** @param  array<string, mixed>  $plan */
    private function collapse(MasjidDomain $row, MasjidDomain $sibling, array $plan, CloudflareService $cloudflare, CloudflareRemover $remover, DomainProbe $probe, string $operator, string $reason): int
    {
        $rule = $cloudflare->ensureRedirectRule((string) $row->cf_zone_id, $row->redirectRuleRef(), $row->host, $sibling->host);

        if (! $rule->is(CloudflareResult::CREATED, CloudflareResult::ADOPTED)) {
            $why = $rule->is(CloudflareResult::UNAUTHORIZED)
                ? 'Cloudflare refused the token for redirect rules: give CLOUDFLARE_STUDIO_TOKEN Zone › Single Redirect: Edit (Dynamic URL Redirects Write).'
                : 'Cloudflare did not add the redirect rule: ' . $rule->error;

            return $this->finish($plan + ['outcome' => 'failed', 'reason' => $why . ' Nothing was changed.'], self::FAILURE);
        }

        $row->forceFill(['cf_redirect_rule_id' => (string) $rule->data['id']])->save();
        $seen = '';

        for ($attempt = 1; $attempt <= self::VERIFY_ATTEMPTS; $attempt++) {
            $result = $probe->redirects($row, $sibling->host);
            $seen = $result['seen'];

            if ($result['matched']) {
                break;
            }

            if ($attempt < self::VERIFY_ATTEMPTS) {
                Sleep::for(self::VERIFY_EVERY_SECONDS)->seconds();
            }
        }

        if (! ($result['matched'] ?? false)) {
            // Put it back as it was: the rule goes, and the row still serves.
            // An adopted rule carries this row's own ref, so it is Studio's
            // too (an earlier attempt's), and goes the same way; the id is
            // forgotten only once the rule is gone, so detach can still find it.
            $undo = $remover->removeRedirectRule($row);

            if ($undo->is(CloudflareResult::OK, CloudflareResult::ABSENT)) {
                $row->forceFill(['cf_redirect_rule_id' => null])->save();
            } else {
                // The rule stands on a row that still serves (review follow-up
                // 4). Parked for domains:reconcile, which takes it out until it
                // is gone (DomainDetacher::removeStrayRedirectRule).
                $row->forceFill([
                    'waiting_on' => 'rule_cleanup',
                    'last_error' => 'A redirect rule from a collapse that did not verify is still in Cloudflare: ' . $undo->error,
                    'next_check_at' => now()->addMinutes(30),
                ])->save();

                Log::warning('A collapse did not verify and its redirect rule could not be taken out; reconcile will retry.', [
                    'masjid_domain_id' => $row->id,
                    'host' => $row->host,
                    'error' => $undo->error,
                ]);
            }

            return $this->finish($plan + [
                'outcome' => 'failed',
                'reason' => "{$row->host} did not answer 301 to {$sibling->host} (last: {$seen}). "
                    . ($undo->ok ? 'The rule was taken out again and nothing else changed.' : 'The rule could not be taken out again: ' . $undo->error),
            ], self::FAILURE);
        }

        $before = $row->ledgerShape();

        DB::transaction(function () use ($row, $sibling, $before, $operator, $reason) {
            $row->forceFill([
                'role' => MasjidDomain::ROLE_REDIRECT,
                'redirect_to_id' => $sibling->id,
                'status' => MasjidDomain::STATUS_MANUAL,
                'verified_by' => MasjidDomain::VERIFIED_BY_PROBE,
                'verified_at' => now(),
                'serving_confirmed_at' => null,
                'serving_last_seen_at' => null,
                'serving_missed_since' => null,
                'serving_miss_count' => 0,
                'waiting_on' => null,
                'last_error' => null,
                'next_check_at' => null,
            ])->save();

            $row->recordChange(MasjidDomainChange::ACTION_COLLAPSE, $before, $operator, $reason);
        });

        $manual = [];
        $removed = [];

        if ($row->cf_pages_domain_id !== null && $row->cf_pages_domain_created) {
            $pages = $remover->removePagesDomain($row);

            if ($pages->is(CloudflareResult::OK, CloudflareResult::ABSENT)) {
                $row->forceFill(['cf_pages_domain_id' => null, 'cf_pages_domain_created' => false])->save();
                $removed[] = "the {$row->host} custom domain on the " . config('cloudflare.pages_project') . ' Pages project';
            } else {
                $manual[] = ($pages->error ?? 'Cloudflare did not remove the custom domain.') . ' ' . $row->pagesDomainRemovalStep();
            }
        } elseif ($row->cf_pages_domain_id !== null) {
            $manual[] = "Studio did not create the Pages custom domain for {$row->host}, so it left it: " . $row->pagesDomainRemovalStep();
        }

        Log::warning('Studio collapsed a web address into a redirect.', [
            'masjid_domain_id' => $row->id,
            'host' => $row->host,
            'redirects_to' => $sibling->host,
            'removed' => $removed,
            'manual_steps' => $manual,
        ]);

        return $this->finish($plan + ['outcome' => 'collapsed', 'removed' => $removed, 'manual_steps' => $manual], self::SUCCESS);
    }

    /** The other host of the row's apex/www pair, when the organisation holds it. */
    private function sibling(MasjidDomain $row): ?MasjidDomain
    {
        $apex = (string) $row->zone_apex;
        $other = match ($row->host) {
            $apex => 'www.' . $apex,
            'www.' . $apex => $apex,
            default => null,
        };

        return $other === null ? null : MasjidDomain::query()
            ->where('masjid_id', $row->masjid_id)
            ->where('host', $other)
            ->first();
    }

    private function refusal(MasjidDomain $row, ?MasjidDomain $sibling): ?string
    {
        return match (true) {
            ! $row->ownedByStudio() => "{$row->host} came from the live host map; Studio does not change it.",
            $row->isRedirect() => "{$row->host} already redirects.",
            $row->kind !== MasjidDomain::KIND_CUSTOM => "{$row->host} is a managed subdomain; it has no apex/www sibling.",
            $sibling === null => "{$row->host} has no apex/www sibling recorded for this organisation to redirect to.",
            $sibling->isRedirect() || ! in_array($sibling->status, MasjidDomain::TRUSTED, true) || $sibling->serving_confirmed_at === null
                => "{$sibling->host} is not confirmed serving, so {$row->host} cannot be pointed at it yet.",
            blank($row->cf_zone_id) => "Studio has no zone recorded for {$row->host}.",
            ! $row->redirectZoneAllowed() => "Studio adds redirect rules only in a zone it created or one listed in cloudflare.redirect_zones; {$row->zone_apex} is neither.",
            default => null,
        };
    }

    /** @param  array<string, mixed>  $out */
    private function finish(array $out, int $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $code;
        }

        $line = ($out['host'] ?? '') . ': ' . $out['outcome'] . (isset($out['redirects_to']) ? " (to {$out['redirects_to']})" : '');
        $code === self::SUCCESS ? $this->info($line) : $this->error($line);

        foreach ((array) ($out['removed'] ?? []) as $object) {
            $this->line("  removed {$object}");
        }

        foreach ((array) ($out['manual_steps'] ?? []) as $step) {
            $this->line("  by hand: {$step}");
        }

        if (isset($out['reason'])) {
            $this->line('  ' . $out['reason']);
        }

        if (($out['outcome'] ?? '') === 'would_collapse') {
            $this->line('  DRY RUN: nothing was sent or changed. Run again with --execute.');
        }

        return $code;
    }
}
