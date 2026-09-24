<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Domains\DomainProbe;
use App\Support\HostName;
use App\Support\WritableHost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record the renderer's live host map in `masjid_domains` (Manara Studio W1, S3).
 *
 *   php artisan domains:import-host-map '{"mec.manara.hopetechapps.com":13}' \
 *       --apex=www.burlingtonmasjid.com:burlingtonmasjid.com
 *
 * `map` is the same JSON object as the renderer's NUXT_TENANT_HOSTS, given
 * inline or as the path of a file holding it. As in the renderer
 * (renderer:shared/tenant.ts toRecord), each value is a bare id or an object
 * carrying one (`{"id": "13", "name": ...}`), which is how the in-git map is
 * written; only the id is read.
 *
 * Every host is recorded (R4), so Studio can never hand a live client's host to
 * another organisation. What each becomes is decided by asking the host itself
 * (DomainProbe): a host that answers with its own organisation's id is `manual`
 * and seen serving; anything else is `reserved`, which is never served, never
 * CORS-admitted and never advanced. Localhost, IP literals, Cloudflare's own
 * `*.pages.dev`/`*.workers.dev` names and anything that is not a host are
 * skipped: they are development entries, not an organisation's domain.
 *
 * This writes PRODUCTION data, so it is safe by construction, like
 * schools:import-roster:
 *
 *  - DRY RUN BY DEFAULT. Nothing is written without --execute, and --dry-run
 *    always wins. The dry run probes for real (a GET per host), so the plan it
 *    prints is the outcome an --execute run would write.
 *  - THE WHOLE PLAN FIRST. The target environment and database, then one line
 *    per host, are printed before anything is written.
 *  - ALL OR NOTHING. Any refusal (an id with no live organisation, a custom
 *    host with no --apex, a host already held by a different organisation, one
 *    host mapped to two ids) refuses the whole run, and the writes happen in
 *    one transaction.
 *  - IDEMPOTENT AND ADDITIVE. It only creates rows. A host already recorded
 *    for the same organisation is left exactly as it is, and one recorded for a
 *    different organisation is never re-pointed. A re-run creates nothing.
 *  - READ BACK. Every created row is re-read inside the transaction and
 *    compared with the plan; a mismatch rolls the whole run back. The created
 *    ids are printed, and every row carries `source = imported`.
 */
class ImportHostMap extends Command
{
    protected $signature = 'domains:import-host-map
        {map : The host => organisation id JSON object (NUXT_TENANT_HOSTS: a bare id or {"id": ...} per host), inline or a file path}
        {--apex=* : HOST:APEX, the zone of a custom host; required for each one}
        {--dry-run : Print the plan and write nothing (the default without --execute)}
        {--execute : Write the planned rows}';

    protected $description = 'Record the renderer\'s live host map in masjid_domains (dry run unless --execute).';

    private const ACTION_CREATE = 'create';
    private const ACTION_EXISTS = 'exists';
    private const ACTION_SKIP = 'skip';
    private const ACTION_REFUSE = 'refuse';

    public function handle(DomainProbe $probe): int
    {
        try {
            $map = $this->readMap((string) $this->argument('map'));
            $apexes = $this->readApexes((array) $this->option('apex'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $write = (bool) $this->option('execute') && ! $this->option('dry-run');

        $connection = DB::connection();
        $this->line(sprintf(
            'Target: APP_ENV=%s, database "%s" on %s (%s).',
            app()->environment(),
            $connection->getDatabaseName(),
            (string) ($connection->getConfig('host') ?? 'local'),
            $connection->getDriverName(),
        ));
        $this->line($write ? 'Mode: EXECUTE. The rows marked "create" will be written.' : 'Mode: dry run. Nothing will be written.');
        $this->newLine();

        $plan = $this->plan($map, $apexes, $probe);

        $this->table(
            ['host', 'masjid', 'action', 'status', 'kind', 'zone', 'why'],
            array_map(fn (array $row) => [
                $row['host'], $row['masjid_id'] ?? '', $row['action'], $row['status'] ?? '',
                $row['kind'] ?? '', $row['zone_apex'] ?? '', $row['why'],
            ], $plan),
        );

        $refused = array_filter($plan, fn (array $row) => $row['action'] === self::ACTION_REFUSE);
        if ($refused !== []) {
            $this->error(count($refused) . ' host(s) refused. Nothing was written; fix the map or the --apex options and run again.');

            return self::FAILURE;
        }

        $creates = array_values(array_filter($plan, fn (array $row) => $row['action'] === self::ACTION_CREATE));

        if (! $write) {
            $this->info(count($creates) . ' row(s) would be created. Dry run: nothing written. Re-run with --execute to write them.');

            return self::SUCCESS;
        }

        if ($creates === []) {
            $this->info('Nothing to create: every host is already recorded or skipped.');

            return self::SUCCESS;
        }

        $ids = DB::transaction(function () use ($creates) {
            $ids = [];

            foreach ($creates as $row) {
                $domain = MasjidDomain::create([
                    'masjid_id' => $row['masjid_id'],
                    'host' => $row['host'],
                    'kind' => $row['kind'],
                    'zone_apex' => $row['zone_apex'],
                    'status' => $row['status'],
                    'source' => MasjidDomain::SOURCE_IMPORTED,
                    'verified_by' => $row['verified_by'],
                    'verified_at' => $row['verified_at'],
                    'serving_confirmed_at' => $row['serving_confirmed_at'],
                    'last_checked_at' => $row['last_checked_at'],
                ]);

                $stored = MasjidDomain::query()->find($domain->id);

                if ($stored === null
                    || $stored->host !== $row['host']
                    || (int) $stored->masjid_id !== (int) $row['masjid_id']
                    || $stored->status !== $row['status']
                    || $stored->source !== MasjidDomain::SOURCE_IMPORTED) {
                    throw new RuntimeException("Read-back of {$row['host']} did not match the plan; nothing was written.");
                }

                $ids[] = $stored->id;
            }

            return $ids;
        });

        $this->info(count($ids) . ' row(s) created and read back: masjid_domains id ' . implode(', ', $ids) . '.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int> raw host => masjid id
     */
    private function readMap(string $argument): array
    {
        $json = str_starts_with(ltrim($argument), '{') ? $argument : @file_get_contents($argument);

        if (! is_string($json)) {
            throw new RuntimeException("The map is neither a JSON object nor a readable file: {$argument}");
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('The map must be a JSON object of host => organisation id.');
        }

        $map = [];
        foreach ($decoded as $host => $entry) {
            $id = is_array($entry) && ! array_is_list($entry) ? ($entry['id'] ?? null) : $entry;
            $id = is_string($id) ? trim($id) : $id;

            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new RuntimeException("The id for {$host} is not an organisation id: " . json_encode($entry));
            }

            $map[(string) $host] = (int) $id;
        }

        return $map;
    }

    /**
     * @param  list<string>  $options
     * @return array<string, string> normalised host => normalised zone apex
     */
    private function readApexes(array $options): array
    {
        $apexes = [];

        foreach ($options as $option) {
            $parts = explode(':', (string) $option, 2);
            $host = HostName::normalize($parts[0]);
            $apex = HostName::normalize($parts[1] ?? null);

            if ($host === null || $apex === null) {
                throw new RuntimeException("--apex={$option} is not HOST:APEX.");
            }

            if ($host !== $apex && ! str_ends_with($host, '.' . $apex)) {
                throw new RuntimeException("--apex={$option}: {$host} is not in the zone {$apex}.");
            }

            $apexes[$host] = $apex;
        }

        return $apexes;
    }

    /**
     * One row per distinct host, in map order.
     *
     * @param  array<string, int>  $map
     * @param  array<string, string>  $apexes
     * @return list<array<string, mixed>>
     */
    private function plan(array $map, array $apexes, DomainProbe $probe): array
    {
        $suffix = (string) config('cloudflare.managed_suffix');
        $zone = (string) config('cloudflare.managed_zone');

        $plan = [];
        $seen = [];

        foreach ($map as $raw => $masjidId) {
            $host = HostName::normalize($raw);

            if ($host === null) {
                $plan[] = $this->row($raw, $masjidId, self::ACTION_SKIP, 'not a host name (an IP literal or invalid)');

                continue;
            }

            if (($refusal = WritableHost::refusal($host)) !== null) {
                $plan[] = $this->row($host, $masjidId, self::ACTION_SKIP, $refusal);

                continue;
            }

            if (isset($seen[$host])) {
                $plan[] = $seen[$host] === $masjidId
                    ? $this->row($host, $masjidId, self::ACTION_SKIP, 'listed twice in the map')
                    : $this->row($host, $masjidId, self::ACTION_REFUSE, "also mapped to masjid {$seen[$host]} in the same map");

                continue;
            }
            $seen[$host] = $masjidId;

            if (Masjid::query()->whereKey($masjidId)->doesntExist()) {
                $plan[] = $this->row($host, $masjidId, self::ACTION_REFUSE, "no live organisation has id {$masjidId}");

                continue;
            }

            $existing = MasjidDomain::query()->where('host', $host)->first();

            if ($existing !== null) {
                $plan[] = (int) $existing->masjid_id === $masjidId
                    ? $this->row($host, $masjidId, self::ACTION_EXISTS, "already recorded (id {$existing->id}); left unchanged", [
                        'status' => $existing->status, 'kind' => $existing->kind, 'zone_apex' => $existing->zone_apex,
                    ])
                    : $this->row($host, $masjidId, self::ACTION_REFUSE, "already held by masjid {$existing->masjid_id}; never re-pointed");

                continue;
            }

            $managed = str_ends_with($host, '.' . $suffix);
            $apex = $managed ? $zone : ($apexes[$host] ?? null);

            if ($apex === null) {
                $plan[] = $this->row($host, $masjidId, self::ACTION_REFUSE, "custom host with no --apex={$host}:<zone>");

                continue;
            }

            $domain = new MasjidDomain([
                'masjid_id' => $masjidId,
                'host' => $host,
                'kind' => $managed ? MasjidDomain::KIND_MANAGED_SUBDOMAIN : MasjidDomain::KIND_CUSTOM,
                'zone_apex' => $apex,
                'source' => MasjidDomain::SOURCE_IMPORTED,
            ]);

            $result = $probe->confirm($domain);

            if (! $result['matched']) {
                $domain->status = MasjidDomain::STATUS_RESERVED;
            }

            $plan[] = $this->row($host, $masjidId, self::ACTION_CREATE, 'probe: ' . $result['seen'], [
                'status' => $domain->status,
                'kind' => $domain->kind,
                'zone_apex' => $domain->zone_apex,
                'verified_by' => $domain->verified_by,
                'verified_at' => $domain->verified_at,
                'serving_confirmed_at' => $domain->serving_confirmed_at,
                'last_checked_at' => $domain->last_checked_at,
            ]);
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    private function row(string $host, ?int $masjidId, string $action, string $why, array $extra = []): array
    {
        return array_merge([
            'host' => $host,
            'masjid_id' => $masjidId,
            'action' => $action,
            'why' => $why,
            'status' => null,
            'kind' => null,
            'zone_apex' => null,
            'verified_by' => null,
            'verified_at' => null,
            'serving_confirmed_at' => null,
            'last_checked_at' => null,
        ], $extra);
    }
}
