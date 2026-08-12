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
