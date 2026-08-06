<?php

namespace App\Support;

use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
// Carbon\CarbonImmutable, not Illuminate\Support\CarbonImmutable — Laravel only
// ships a mutable Illuminate\Support\Carbon; there is no immutable counterpart.
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * DonationMetrics — the read model behind the giving dashboard.
 *
 * Plain support class, not a facade: the stats controller is a thin wrapper over
 * it, and the Masjid Assistant can answer "how much came in this month?" by
 * calling the same code rather than reimplementing the money rules.
 *
 * Tenant isolation is inherited, never hand-rolled. Every query starts from a
 * BelongsToMasjid model (Donation / Fund), so the global scope supplies
 * `masjid_id`. The fund breakdown joins donations to funds by fund_id only —
 * a donation's fund always belongs to the same masjid, so scoping the funds
 * transitively scopes the gifts. See .claude/rules/tenant-scoping.md.
 *
 * All amounts are integer minor units (cents), summed straight out of the
 * stored columns — nothing here re-derives an amount.
 *
 * ## Buckets are in the MASJID's timezone, and that is why the window is split
 *
 * The canonical gift date is COALESCE(donated_at, created_at), but the two
 * columns are not in the same time frame:
 *
 *   - `donated_at` is a DATE — a wall-calendar day at the masjid ("this cash
 *     came in on Aug 1"). No time, no zone.
 *   - `created_at` is an instant stored in the app timezone, which config/app.php
 *     pins to UTC.
 *
 * So a single boundary like "start of August in America/New_York" is two
 * different literals: the date 2026-08-01 for one column and the instant
 * 2026-08-01 04:00:00 UTC for the other. Comparing the COALESCE result against
 * either one alone is wrong for the other column — against the UTC instant it
 * drops every offline gift dated the 1st (local midnight sorts before 04:00Z),
 * against the bare date it drags in Stripe gifts from the last evening hours of
 * July. Each column is therefore compared in its own frame and the two are OR'd
 * (see windowSql). The COALESCE expression stays canonical where there is no
 * boundary to get wrong: `last_gift_at` and ordering.
 *
 * CONVERT_TZ is deliberately avoided — it needs MySQL's timezone tables loaded
 * on the droplet and does not exist in SQLite, which the test suite runs on.
 *
 * ## Anonymous gifts
 *
 * `donor_count` is COUNT(DISTINCT contact_id), which SQL evaluates over non-null
 * values only, so it means "distinct IDENTIFIED donors". Gifts with no contact
 * (a walk-in cash donation, an unmatched import row) are NOT dropped from the
 * money: they count in gross, net, gift_count and therefore average_gift. They
 * are also reported on their own as `anonymous_gift_count`, so the gap between
 * donor_count and gift_count is always explainable rather than looking like a
 * bug.
 */
class DonationMetrics
{
    /** Mirrors the donations.status enum — also the whitelist the controller validates against. */
    public const STATUSES = ['pending', 'succeeded', 'failed', 'refunded'];

    /** Mirrors donations.source. */
    public const SOURCES = ['stripe', 'offline'];

    /** Money received. Anything else is an intent, a failure, or a reversal. */
    public const MONEY_RECEIVED_STATUS = 'succeeded';

    public function __construct(private readonly string $timezone)
    {
    }

    /**
     * Masjids created before the timezone column existed default to UTC, and a
     * super-admin view with no masjid resolved falls back to the app timezone —
     * both keep the buckets defined rather than throwing.
     */
    public static function forMasjid(?Masjid $masjid): self
    {
        return new self($masjid?->timezone ?: (string) config('app.timezone', 'UTC'));
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Header totals: this month, year to date, all time.
     *
     * One grouped query. The narrower buckets are conditional aggregates over
     * the same scan rather than three round trips, which also guarantees the
     * three numbers describe one consistent snapshot.
     *
     * Caller filters INTERSECT the buckets — the header always describes the set
     * the admin is actually looking at. With a from/to filter applied, `all_time`
     * is therefore "everything in the filtered range", not everything ever.
     *
     * @param  array{from?:?string,to?:?string,source?:?string,status?:?string}  $filters
     * @return array<string, array{gross_cents:int,net_cents:int,donor_count:int,gift_count:int,average_gift_cents:int,anonymous_gift_count:int}>
     */
    public function summary(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $buckets = $this->buckets();

        $select = [];
        $selectBindings = [];

        foreach ($buckets as $key => [$start, $end]) {
            [$inBucket, $bucketBindings] = $this->windowSql($start, $end);

            // net_amount is null until Stripe settles the balance transaction, and
            // an offline gift never gets one at all — fall back to what was charged
            // so the net column is never mysteriously smaller than the gross.
            $fragments = [
                "SUM(CASE WHEN {$inBucket} THEN donations.charged_amount ELSE 0 END) AS {$key}_gross",
                "SUM(CASE WHEN {$inBucket} THEN COALESCE(donations.net_amount, donations.charged_amount) ELSE 0 END) AS {$key}_net",
                "COUNT(DISTINCT CASE WHEN {$inBucket} THEN donations.contact_id END) AS {$key}_donors",
                "SUM(CASE WHEN {$inBucket} THEN 1 ELSE 0 END) AS {$key}_gifts",
                "SUM(CASE WHEN ({$inBucket}) AND donations.contact_id IS NULL THEN 1 ELSE 0 END) AS {$key}_anonymous",
            ];

            foreach ($fragments as $fragment) {
                $select[] = $fragment;
                // Each fragment repeats the window, so its bindings repeat with it;
                // they are appended in emission order to stay aligned with the SQL.
                $selectBindings = array_merge($selectBindings, $bucketBindings);
            }
        }

        [$inRange, $rangeBindings] = $this->windowSql(...$this->filterWindow($filters));

        $row = $this->donationsQuery($filters)
            ->whereRaw($inRange, $rangeBindings)
            ->selectRaw(implode(",\n", $select), $selectBindings)
            ->first();

        $summary = [];

        foreach (array_keys($buckets) as $key) {
            $gross = (int) $row->{"{$key}_gross"};
            $gifts = (int) $row->{"{$key}_gifts"};

            $summary[$key] = [
                'gross_cents' => $gross,
                'net_cents' => (int) $row->{"{$key}_net"},
                'donor_count' => (int) $row->{"{$key}_donors"},
                'gift_count' => $gifts,
                // Integer cents, floor. A bucket with no gifts averages 0, not a
                // division by zero.
                'average_gift_cents' => $gifts > 0 ? intdiv($gross, $gifts) : 0,
                'anonymous_gift_count' => (int) $row->{"{$key}_anonymous"},
            ];
        }

        return $summary;
    }

    /**
     * Per-fund breakdown, biggest first. Every fund is listed, including ones
     * that have taken nothing in the filtered window — a designation sitting at
     * $0 is exactly what a treasurer needs to see.
     *
     * One grouped query, so nothing iterates funds.
     *
     * @param  array{from?:?string,to?:?string,source?:?string,status?:?string}  $filters
     * @return array<int, array{fund_id:int,fund_name:string,is_active:bool,gross_cents:int,net_cents:int,gift_count:int,donor_count:int,last_gift_at:?string}>
     */
    public function byFund(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        [$inRange, $rangeBindings] = $this->windowSql(...$this->filterWindow($filters));

        $rows = Fund::query()
            ->leftJoin('donations', function (JoinClause $join) use ($filters, $inRange, $rangeBindings) {
                // Every donation predicate belongs in the ON clause. Moved to a
                // WHERE, each one would also have to hold for the all-null row a
                // LEFT JOIN produces for a fund with no gifts, quietly collapsing
                // this back into an inner join and hiding the $0 funds.
                $join->on('donations.fund_id', '=', 'funds.id')
                    ->where('donations.status', '=', $filters['status'])
                    ->whereRaw($inRange, $rangeBindings);

                if ($filters['source'] !== null) {
                    $join->where('donations.source', '=', $filters['source']);
                }
            })
            ->groupBy('funds.id', 'funds.name', 'funds.is_active')
            ->selectRaw(
                'funds.id AS fund_id,
                 funds.name AS fund_name,
                 funds.is_active AS is_active,
                 COALESCE(SUM(donations.charged_amount), 0) AS gross_cents,
                 COALESCE(SUM(COALESCE(donations.net_amount, donations.charged_amount)), 0) AS net_cents,
                 COUNT(donations.id) AS gift_count,
                 COUNT(DISTINCT donations.contact_id) AS donor_count,
                 MAX(COALESCE(donations.donated_at, donations.created_at)) AS last_gift_at'
            )
            // Name breaks the tie so the run of $0 funds at the bottom is stable
            // between requests instead of shuffling on every reload.
            ->orderByRaw('gross_cents DESC, funds.name ASC')
            ->get();

        return $rows->map(fn ($row) => [
            'fund_id' => (int) $row->fund_id,
            'fund_name' => (string) $row->fund_name,
            'is_active' => (bool) $row->is_active,
            'gross_cents' => (int) $row->gross_cents,
            'net_cents' => (int) $row->net_cents,
            'gift_count' => (int) $row->gift_count,
            'donor_count' => (int) $row->donor_count,
            // Reported as a plain calendar date: half these gifts only ever had
            // one (donated_at is a DATE), so a timestamp would imply a precision
            // the offline history does not have.
            'last_gift_at' => $row->last_gift_at
                ? CarbonImmutable::parse($row->last_gift_at)->toDateString()
                : null,
        ])->all();
    }

    /**
     * What the numbers were computed under, so the dashboard can label them
     * instead of the reader having to assume.
     *
     * @param  array{from?:?string,to?:?string,source?:?string,status?:?string}  $filters
     * @return array<string, mixed>
     */
    public function meta(array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);

        return [
            'timezone' => $this->timezone,
            // Donations are booked in the platform's single configured currency
            // (see DonationsController::store), so one currency labels the page.
            'currency' => strtoupper((string) config('services.stripe.currency', 'usd')),
            'filters' => [
                'from' => $normalized['from']?->toDateString(),
                'to' => $normalized['to']?->toDateString(),
                'source' => $normalized['source'],
                'status' => $normalized['status'],
            ],
        ];
    }

    /**
     * The from/to window as a raw SQL predicate, for callers that select the
     * GIFTS rather than aggregate them — the ledger list and the CSV export.
     *
     * Public on purpose. Those two used to bucket by DATE(COALESCE(donated_at,
     * created_at)), which reads created_at in the app timezone (UTC) while this
     * class reads it in the masjid's, so the header and the rows underneath it
     * could report different gifts for one from/to. Sharing the predicate is
     * what makes that class of drift impossible rather than merely fixed once.
     *
     * Takes the caller's RAW filter values (yyyy-mm-dd strings, as validated off
     * the query string); normalisation to masjid-local days happens here. With
     * neither end set the predicate is `1 = 1`, so it is always safe to apply.
     *
     * @param  array{from?:?string,to?:?string}  $filters
     * @return array{0:string,1:array<int,string>} [sql, bindings in SQL order]
     */
    public function windowSqlForFilters(array $filters): array
    {
        return $this->windowSql(...$this->filterWindow($this->normalizeFilters($filters)));
    }

    /** Donations narrowed by the non-date filters; masjid comes from the global scope. */
    private function donationsQuery(array $filters): Builder
    {
        return Donation::query()
            ->where('donations.status', $filters['status'])
            ->when(
                $filters['source'] !== null,
                fn (Builder $q) => $q->where('donations.source', $filters['source'])
            );
    }

    /**
     * @return array{from:?CarbonImmutable,to:?CarbonImmutable,source:?string,status:string}
     */
    private function normalizeFilters(array $filters): array
    {
        return [
            'from' => $this->localDay($filters['from'] ?? null),
            'to' => $this->localDay($filters['to'] ?? null),
            'source' => $filters['source'] ?? null,
            // Default to money actually received. An explicit status overrides it
            // (the ledger can filter to refunded and the header follows), which is
            // why the caller's choice is echoed back in meta().
            'status' => $filters['status'] ?? self::MONEY_RECEIVED_STATUS,
        ];
    }

    /** A yyyy-mm-dd (or ISO datetime) filter value as local midnight at the masjid. */
    private function localDay(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, $this->timezone)
            ->setTimezone($this->timezone)
            ->startOfDay();
    }

    /**
     * The caller's from/to as a half-open window.
     *
     * @return array{0:?CarbonImmutable,1:?CarbonImmutable}
     */
    private function filterWindow(array $filters): array
    {
        return [
            $filters['from'],
            // `to` is an inclusive calendar day to the admin who typed it, so the
            // exclusive end of the window is the start of the next day.
            $filters['to']?->addDay(),
        ];
    }

    /**
     * The three header buckets as half-open [start, end) windows in masjid-local
     * time.
     *
     * @return array<string, array{0:?CarbonImmutable,1:?CarbonImmutable}>
     */
    private function buckets(): array
    {
        $now = CarbonImmutable::now($this->timezone);

        // Both live buckets stop at the end of TODAY rather than the end of the
        // period. An admin entering next Friday's pledge with a future donated_at
        // must not inflate what has come in so far.
        $endOfToday = $now->startOfDay()->addDay();

        return [
            'this_month' => [$now->startOfMonth(), $endOfToday],
            'year_to_date' => [$now->startOfYear(), $endOfToday],
            // Bounded only by the caller's from/to, which the WHERE already applies.
            'all_time' => [null, null],
        ];
    }

    /**
     * SQL predicate for "the gift falls in [start, end)", with the two date
     * columns each compared in their own time frame — see the class docblock for
     * why one COALESCE comparison cannot be correct for both.
     *
     * @return array{0:string,1:array<int,string>} [sql, bindings in SQL order]
     */
    private function windowSql(?CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        if ($start === null && $end === null) {
            return ['1 = 1', []];
        }

        $appTimezone = (string) config('app.timezone', 'UTC');

        // Offline gifts carry a wall-calendar donated_at: compare against the
        // local date. Stripe gifts have none, so their created_at instant is
        // compared against the same local boundary expressed in the app timezone.
        $offline = ['donations.donated_at IS NOT NULL'];
        $online = ['donations.donated_at IS NULL'];
        $offlineBindings = [];
        $onlineBindings = [];

        if ($start !== null) {
            $offline[] = 'DATE(donations.donated_at) >= ?';
            $offlineBindings[] = $start->toDateString();
            $online[] = 'donations.created_at >= ?';
            $onlineBindings[] = $start->setTimezone($appTimezone)->format('Y-m-d H:i:s');
        }

        if ($end !== null) {
            $offline[] = 'DATE(donations.donated_at) < ?';
            $offlineBindings[] = $end->toDateString();
            $online[] = 'donations.created_at < ?';
            $onlineBindings[] = $end->setTimezone($appTimezone)->format('Y-m-d H:i:s');
        }

        $sql = '((' . implode(' AND ', $offline) . ') OR (' . implode(' AND ', $online) . '))';

        return [$sql, array_merge($offlineBindings, $onlineBindings)];
    }
}
