<?php

namespace Tests\Feature;

use App\Models\HistoricalImportRecord;
use App\Models\HistoricalOrder;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WritesWixOrderExport;
use Tests\TestCase;

/**
 * Cross-tenant suite for the two order-history models (mandatory per
 * .claude/rules/tenant-scoping.md): HistoricalOrder and HistoricalImportRecord.
 *
 * The model layer, with TenantContext bound directly: another organisation's
 * rows are invisible, a created row is stamped with the bound tenant whatever it
 * was handed, and one organisation's import is invisible to the next — including
 * to a second import of the SAME order numbers, which the uniqueness key scopes
 * per organisation rather than letting one org's history block another's.
 */
class HistoricalOrderTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WritesWixOrderExport;

    private Masjid $a;
    private Masjid $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->a = $this->makeOrgForWixImport();
        $this->b = $this->makeOrgForWixImport();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forgetTenant();
        $this->removeWixExports();
        parent::tearDown();
    }

    private function order(Masjid $org, string $number): HistoricalOrder
    {
        return HistoricalOrder::withoutMasjidScope()->create([
            'masjid_id' => $org->id,
            'source' => HistoricalOrder::SOURCE_WIX_STORES,
            'order_number' => $number,
            'provider' => HistoricalOrder::PROVIDER_WIX,
            'status' => HistoricalOrder::STATUS_PAID,
            'ordered_at' => now(),
            'total_minor' => 1000,
            'lines' => [],
            'import_batch' => 'b1',
        ]);
    }

    #[Test]
    public function another_organisations_orders_and_import_records_are_invisible(): void
    {
        $theirs = $this->order($this->b, '10001');
        $theirRecord = HistoricalImportRecord::withoutMasjidScope()->create([
            'masjid_id' => $this->b->id, 'import_batch' => 'b1',
            'record_type' => HistoricalImportRecord::TYPE_FUND, 'record_id' => 99,
        ]);

        app(TenantContext::class)->set($this->a->id);

        $this->assertNull(HistoricalOrder::find($theirs->id));
        $this->assertCount(0, HistoricalOrder::all());
        $this->assertNull(HistoricalImportRecord::find($theirRecord->id));
        $this->assertCount(0, HistoricalImportRecord::all());
    }

    #[Test]
    public function a_row_created_while_bound_belongs_to_the_bound_organisation(): void
    {
        app(TenantContext::class)->set($this->a->id);

        $order = HistoricalOrder::create([
            'masjid_id' => $this->b->id,   // ignored: the bound tenant wins
            'source' => HistoricalOrder::SOURCE_WIX_STORES,
            'order_number' => '10001',
            'provider' => HistoricalOrder::PROVIDER_WIX,
            'status' => HistoricalOrder::STATUS_PAID,
            'ordered_at' => now(),
            'total_minor' => 1000,
            'lines' => [],
            'import_batch' => 'b1',
        ]);

        $this->assertSame($this->a->id, (int) $order->masjid_id);
        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->where('masjid_id', $this->b->id)->count());
    }

    #[Test]
    public function one_organisations_import_neither_sees_nor_blocks_anothers(): void
    {
        $dir = $this->writeWixExport();

        $this->assertSame(0, $this->importWix($this->a, $dir, ['--execute' => true, '--batch' => 'a1']));
        // The same order numbers again, for the other organisation: imported in
        // full, not skipped as "already imported".
        $this->assertSame(0, $this->importWix($this->b, $dir, ['--execute' => true, '--batch' => 'b1']));

        $this->assertSame(6, HistoricalOrder::withoutMasjidScope()->where('masjid_id', $this->a->id)->count());
        $this->assertSame(6, HistoricalOrder::withoutMasjidScope()->where('masjid_id', $this->b->id)->count());

        // Undoing one organisation's batch leaves the other's history whole.
        $this->importWix($this->a, '', ['--undo' => 'a1', '--execute' => true]);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->where('masjid_id', $this->a->id)->count());
        $this->assertSame(6, HistoricalOrder::withoutMasjidScope()->where('masjid_id', $this->b->id)->count());
    }
}
