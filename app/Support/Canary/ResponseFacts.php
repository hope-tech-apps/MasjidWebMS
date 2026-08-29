<?php

namespace App\Support\Canary;

/**
 * Turns a public API body into the two numbers the canary compares.
 *
 * Both live holes were invisible to the test suite and visible in the response
 * body, so everything here reads the body the way an attacker would: how many
 * rows came back, and whose are they.
 */
final class ResponseFacts
{
    /**
     * How many records an answer exposed, plus how that was derived.
     *
     * The basis matters in the report. `pagination.total` is authoritative —
     * it is the database's own count of the matching set, unaffected by page
     * size, and it is what made the production measurement (11 / 3 / 14)
     * legible. A count taken from a list is capped by whatever `limit()` the
     * controller applied, so it can under-report a leak but never invent one:
     * the comparison is one-sided, which is the safe direction.
     *
     * The canary carries the envelope's shape rather than each controller's,
     * because `response()->api()` is the one thing every /api/v1 answer has in
     * common — and note `array_filter()` inside that macro DROPS an empty
     * `data` key entirely, so "no data key" means "no rows", not "unparseable".
     *
     * @return array{0: int|null, 1: string}
     */
    public static function recordCount(mixed $data): array
    {
        if ($data === null) {
            return [0, 'empty envelope'];
        }

        if (! is_array($data)) {
            return [null, 'unavailable'];
        }

        $total = $data['pagination']['total'] ?? null;

        if (is_int($total) || (is_string($total) && ctype_digit($total))) {
            return [(int) $total, 'pagination.total'];
        }

        if (array_is_list($data)) {
            return [count($data), 'top-level list'];
        }

        // The /api/v1/home shape: several sibling collections under one key.
        // Summing them is comparable across header variants, which is all the
        // fail-open comparison needs.
        $sum = 0;
        $found = false;

        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                $sum += count($value);
                $found = true;
            }
        }

        return $found ? [$sum, 'sum of nested lists'] : [null, 'unavailable'];
    }

    /**
     * Every distinct organisation named anywhere in the body.
     *
     * Walks the whole tree rather than the top level: the /api/mobile
     * controllers return raw Eloquent models with relations eager-loaded, so a
     * foreign `masjid_id` can sit several levels down inside an embedded row.
     *
     * Nulls are ignored — a nullable `masjid_id` on a genuinely global row is
     * not evidence of anything.
     *
     * @param  array<int,string>  $keys
     * @return array<int,int> sorted, distinct
     */
    public static function tenantIds(mixed $node, array $keys = ['masjid_id']): array
    {
        $found = [];

        self::walk($node, $keys, $found);

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * @param  array<int,string>  $keys
     * @param  array<int,int>  $found
     */
    private static function walk(mixed $node, array $keys, array &$found): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, $keys, true)) {
                if (is_int($value)) {
                    $found[] = $value;
                } elseif (is_string($value) && ctype_digit($value)) {
                    $found[] = (int) $value;
                }
            }

            if (is_array($value)) {
                self::walk($value, $keys, $found);
            }
        }
    }

    /**
     * WHICH rows came back — their primary keys, bucketed by the key path they
     * sat under.
     *
     * ## Why this exists: the leak that strips its own evidence
     *
     * `tenantIds()` above reads `masjid_id` straight out of the body, which is
     * proof. Every `/api/v1` Resource STRIPS `masjid_id` — AnnouncementResource,
     * ServiceResource, PageResource and PhotoGalleryResource all emit `id,
     * title, …` and nothing that names an organisation. So on the entire `/api/v1`
     * surface that detector is blind, and the count comparison only ever compares
     * a tenant-less answer against a tenant's.
     *
     * Measured on 2026-08-12: change `scopeFilterByMasjid()` to validate the
     * `masjid-id` header and then IGNORE it — pinning every public query to the
     * lowest masjid id — and the canary reports 36/36 probes, 23 passing checks,
     * zero findings, exit 0. Every visitor to masjid 5's and masjid 13's sites
     * reads MASJID 1's announcements, services, pages and home feed; an admin
     * watches their own announcement never appear; and the canary calls the
     * platform clean. The three existing detectors compose to nothing there: the
     * body carries no `masjid_id` to be foreign, and the row COUNTS are equal
     * because every tenant is served the same rows.
     *
     * What is still observable when identity is stripped from the body is
     * IDENTITY OF ANOTHER KIND: `id` survives, primary keys are unique per table
     * in a shared-database tenancy, and the canary knows which organisation it
     * asked for. If masjid A's answer and masjid B's answer contain the same
     * `id` at the same key path, those are literally the same database rows —
     * proof, not inference, and it catches a partial contamination as readily as
     * a total pin.
     *
     * ## The bucketing, and what it is defending against
     *
     * Ids are keyed by the path of the LIST they came from — `items`,
     * `menu_items`, `services`, `(root)` — so a page id of 3 is never compared
     * against a section id of 3. Only list ELEMENTS are read, and the walk does
     * not descend into a row: an `id` sitting on a singleton object is usually a
     * shared lookup row (a country, a category) that both tenants legitimately
     * reference, and reading those would manufacture an overlap out of correct
     * behaviour. A canary that cries wolf gets silenced, which is the same
     * outcome as not having built it.
     *
     * @return array<string,array<int,int>> key path => sorted, distinct ids
     */
    public static function recordIdentity(mixed $payload, int $maxDepth = 6): array
    {
        $buckets = [];

        self::collectIds($payload, '', $buckets, $maxDepth);

        foreach ($buckets as $path => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $buckets[$path] = $ids;
        }

        ksort($buckets);

        return array_filter($buckets, static fn (array $ids) => $ids !== []);
    }

    /**
     * @param  array<string,array<int,int>>  $buckets
     */
    private static function collectIds(mixed $node, string $path, array &$buckets, int $depth): void
    {
        if ($depth < 0 || ! is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? null;

                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $buckets[$path === '' ? '(root)' : $path][] = (int) $id;
                }
            }

            // Deliberately does NOT descend into the rows themselves. See the
            // docblock above: a nested id is usually a shared lookup row.
            return;
        }

        // A singleton resource — `/api/mobile/masjids/{id}`, `…/about`,
        // `…/tv-config` — is one tenant-scoped row at the ROOT of the payload,
        // and its own `id` is the only identity it has (the model's PK is `id`,
        // not `masjid_id`, so the tenant-id scan cannot see it either). Root
        // only: an `id` on a NESTED object is usually a shared lookup row (a
        // country, a category) that both tenants legitimately reference, and
        // reading those would manufacture an overlap out of correct behaviour.
        if ($path === '') {
            $id = $node['id'] ?? null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $buckets['(root)'][] = (int) $id;
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                self::collectIds($value, $path === '' ? (string) $key : $path.'.'.$key, $buckets, $depth - 1);
            }
        }
    }

    /**
     * A stable hash of the whole answer, for the case where the rows carry no
     * `id` either.
     *
     * The last resort of the cross-tenant comparison, and the weakest: it can
     * only say "these two organisations were handed byte-identical content",
     * which is a leak OR an endpoint that is not tenant-scoped at all. The
     * caller only reaches for it when both answers actually contained rows, so
     * two empty answers — the normal state of a vertical with no data yet —
     * never trip it.
     *
     * Maps are key-sorted so an ordering difference in the serialiser cannot
     * make two identical answers look different; lists keep their order, since
     * a different order IS a different answer.
     */
    public static function fingerprint(mixed $payload): ?string
    {
        if ($payload === null || $payload === [] || $payload === '') {
            return null;
        }

        $json = json_encode(self::canonical($payload));

        return $json === false ? null : hash('sha256', $json);
    }

    private static function canonical(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (array_is_list($node)) {
            return array_map([self::class, 'canonical'], $node);
        }

        ksort($node);

        return array_map([self::class, 'canonical'], $node);
    }

    /**
     * The `data` member of the legacy envelope, or the whole body when the
     * response is not enveloped (the /api/mobile controllers hand-roll
     * `['status' => 'success', 'data' => ...]`, which is the same shape, but a
     * future endpoint may not).
     */
    public static function payload(mixed $body): mixed
    {
        if (is_array($body) && array_key_exists('data', $body)) {
            return $body['data'];
        }

        if (is_array($body) && array_key_exists('status', $body) && count($body) <= 2) {
            // {status, message} with no data — the empty answer after
            // array_filter() dropped the data key.
            return null;
        }

        return $body;
    }
}
