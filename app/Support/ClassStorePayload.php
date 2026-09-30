<?php

namespace App\Support;

use App\Models\Prize;
use App\Models\PrizeLedgerEntry;

/**
 * How the class store's rows leave the server (T-003.4): built by hand, never `toArray()`, so
 * a column added later is not published to a family by accident.
 *
 * A TEACHER sees everything a ledger row records. A FAMILY sees what a parent needs to read
 * their own child's history and nothing that is the teacher's working: no note (a teacher's
 * words about a child), no author, no paper-note breakdown, no prize id. The two shapes are
 * separate functions so the narrower one cannot grow by copying the wider.
 */
final class ClassStorePayload
{
    /** @return array<string,mixed> */
    public static function prize(Prize $prize, ?int $forGroupId = null): array
    {
        return [
            'id' => (int) $prize->id,
            'scope' => $prize->isSchoolWide() ? 'school' : 'class',
            'title' => $prize->title,
            'description' => $prize->description,
            'cost_bucks' => (int) $prize->cost_bucks,
            // NULL is unlimited (R6, blank means unlimited).
            'stock' => $prize->stock === null ? null : (int) $prize->stock,
            'in_stock' => $prize->inStock(),
            'is_active' => (bool) $prize->is_active,
            // May THIS class's teachers change it? Only their own class's prize; the
            // school-wide list is the office's.
            'editable' => $forGroupId !== null && $prize->group_id !== null && (int) $prize->group_id === $forGroupId,
        ];
    }

    /**
     * One ledger row as the class's TEACHER reads it.
     *
     * @param  bool  $reversed  whether a reversal row points at this one
     * @return array<string,mixed>
     */
    public static function entryForTeacher(PrizeLedgerEntry $entry, bool $reversed): array
    {
        return [
            'id' => (int) $entry->id,
            'kind' => $entry->kind,
            'amount' => (int) $entry->amount,
            'week_start' => $entry->week_start,
            'prize_id' => $entry->prize_id,
            'prize_title' => $entry->prize_title,
            'prize_cost' => $entry->prize_cost,
            'reverses_entry_id' => $entry->reverses_entry_id,
            'breakdown' => $entry->breakdown,
            'note' => $entry->note,
            'created_by' => $entry->createdBy ? ['id' => (int) $entry->createdBy->id, 'name' => $entry->createdBy->name] : null,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
            'is_reversed' => $reversed,
            // The two kinds a teacher may still correct.
            'reversible' => ! $reversed && in_array($entry->kind, PrizeLedgerEntry::REVERSIBLE_KINDS, true),
        ];
    }

    /**
     * One ledger row as a PARENT reads it: what happened, how many bucks, when. Nothing else.
     *
     * @return array<string,mixed>
     */
    public static function entryForFamily(PrizeLedgerEntry $entry, bool $reversed): array
    {
        return [
            'id' => (int) $entry->id,
            'kind' => $entry->kind,
            'amount' => (int) $entry->amount,
            'week_start' => $entry->week_start,
            'prize_title' => $entry->prize_title,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
            'is_reversed' => $reversed,
        ];
    }
}
