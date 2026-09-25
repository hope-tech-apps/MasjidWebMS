<?php

namespace Tests\Feature;

use App\Models\ImportLink;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-tenant guarantee for App\Models\ImportLink (.claude/rules/tenant-scoping.md).
 *
 * The importers decide "already imported" and "created by this run" from these
 * rows. Seeing another organisation's link would make an import skip a person
 * (their Wix id is "already imported") or let one organisation's undo reach
 * rows it never wrote.
 */
class ImportLinkTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function linkIn(Masjid $masjid, string $externalId): ImportLink
    {
        return ImportLink::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'source' => ImportLink::SOURCE_WIX,
            'kind' => ImportLink::KIND_CONTACT,
            'external_id' => $externalId,
            'local_id' => 1,
            'created_local' => true,
            'import_batch' => 'batch-1',
        ]);
    }

    #[Test]
    public function the_columns_hold_what_the_importers_write(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('import_links', 'external_id'));
        $this->assertSame('varchar', Schema::getColumnType('import_links', 'import_batch'));
        $this->assertContains(Schema::getColumnType('import_links', 'fingerprint'), ['char', 'varchar']);
        $this->assertSame('integer', Schema::getColumnType('import_links', 'local_id'));
    }

    #[Test]
    public function another_organisations_links_are_invisible_and_untouchable(): void
    {
        $theirs = $this->linkIn($this->masjidB, 'wix-contact-1');

        app(TenantContext::class)->set($this->masjidA->id);

        $this->assertNull(ImportLink::find($theirs->id));
        $this->assertSame(0, ImportLink::query()->where('import_batch', 'batch-1')->delete());
        $this->assertSame(0, ImportLink::query()->where('external_id', 'wix-contact-1')->update(['local_id' => 99]));

        $this->assertSame(1, (int) ImportLink::withoutMasjidScope()->find($theirs->id)->local_id);
    }

    #[Test]
    public function a_link_is_stamped_with_the_bound_organisation_and_the_same_wix_id_may_exist_in_both(): void
    {
        $this->linkIn($this->masjidB, 'wix-contact-1');

        app(TenantContext::class)->set($this->masjidA->id);

        $ours = ImportLink::create([
            'masjid_id' => $this->masjidB->id,
            'source' => ImportLink::SOURCE_WIX,
            'kind' => ImportLink::KIND_CONTACT,
            'external_id' => 'wix-contact-1',
            'local_id' => 2,
            'import_batch' => 'batch-1',
        ]);

        $this->assertSame($this->masjidA->id, (int) $ours->masjid_id);
        $this->assertSame(2, ImportLink::withoutMasjidScope()->where('external_id', 'wix-contact-1')->count());
    }
}
