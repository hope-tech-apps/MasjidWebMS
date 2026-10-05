<?php

namespace Tests\Feature;

use App\Models\PrizeLedgerEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SPA's list of Manara Bucks ledger kinds (resources/vue-app/core/helpers/
 * classStore.ts) must be PrizeLedgerEntry::KINDS, in order.
 *
 * The two lists are written by hand in two languages. The SPA's own suite
 * proves that every kind in ITS list has a teacher's label, a teacher's
 * sentence and a line in each of the six family tables; nothing there can know
 * what the server writes. A kind the server gains and this file does not reach
 * a teacher as the raw word ("transfer out") and a parent as an untranslated
 * key. So a kind added on the server fails here until the screens have it,
 * and from then on the SPA's suite demands its words.
 *
 * The file's layout is part of the contract: `export type LedgerKind = '…' |
 * '…';` and `export const LEDGER_KINDS: LedgerKind[] = ['…', '…'];`, each on
 * one line.
 */
class LedgerKindsTsMirrorTest extends TestCase
{
    private function source(): string
    {
        $path = base_path('resources/vue-app/core/helpers/classStore.ts');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    #[Test]
    public function the_spa_ledger_kinds_are_the_servers_in_order(): void
    {
        $this->assertSame(
            1,
            preg_match('/^export const LEDGER_KINDS: LedgerKind\[\] = \[(.*)\];$/m', $this->source(), $list),
            'classStore.ts has no one-line `export const LEDGER_KINDS: LedgerKind[] = [ ... ];`'
        );

        preg_match_all("/'([a-z_]+)'/", $list[1], $kinds);

        $this->assertSame(PrizeLedgerEntry::KINDS, $kinds[1]);
    }

    #[Test]
    public function the_spa_type_names_the_same_kinds_so_a_missing_label_does_not_compile(): void
    {
        // `Record<LedgerKind, string>` is what makes a label for every kind a
        // compile-time demand; it holds only while the type is the whole list.
        $this->assertSame(
            1,
            preg_match('/^export type LedgerKind = (.*);$/m', $this->source(), $union),
            'classStore.ts has no one-line `export type LedgerKind = ... ;`'
        );

        preg_match_all("/'([a-z_]+)'/", $union[1], $kinds);

        $this->assertSame(PrizeLedgerEntry::KINDS, $kinds[1]);
        $this->assertMatchesRegularExpression('/^const KIND_LABEL: Record<LedgerKind, string> = \{$/m', $this->source());
    }

    #[Test]
    public function the_two_transfer_kinds_are_among_them(): void
    {
        // The pair a moved student's balance is written as. Listed here by
        // name so that removing them from both sides at once is still a
        // failure someone has to read.
        foreach (PrizeLedgerEntry::TRANSFER_KINDS as $kind) {
            $this->assertContains($kind, PrizeLedgerEntry::KINDS);
            $this->assertStringContainsString("'{$kind}'", $this->source());
        }

        $this->assertSame(['transfer_out', 'transfer_in'], PrizeLedgerEntry::TRANSFER_KINDS);
    }
}
