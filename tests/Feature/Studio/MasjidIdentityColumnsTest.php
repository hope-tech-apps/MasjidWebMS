<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `masjids.slug` and `masjids.description` (S3), asserted by type: SQLite
 * would accept a 500-character description in a varchar that MySQL refuses.
 */
class MasjidIdentityColumnsTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    #[Test]
    public function slug_is_a_unique_string(): void
    {
        $this->assertContains(Schema::getColumnType('masjids', 'slug'), ['varchar', 'string']);

        $indexes = collect(Schema::getIndexes('masjids'))->keyBy('name');
        $this->assertTrue($indexes->has('masjids_slug_unique'), 'masjids_slug_unique is missing');
        $this->assertTrue((bool) $indexes['masjids_slug_unique']['unique']);
        $this->assertSame(['slug'], $indexes['masjids_slug_unique']['columns']);

        // Organisations made before Studio have no slug, and there are many.
        $this->makeOrg();
        $this->makeOrg();

        $this->makeOrg(['slug' => 'al-noor']);
        $this->expectException(QueryException::class);
        $this->makeOrg(['slug' => 'al-noor']);
    }

    #[Test]
    public function description_is_text_so_its_length_is_the_clients_to_decide(): void
    {
        $this->assertSame('text', Schema::getColumnType('masjids', 'description'));

        $long = str_repeat('A community for everyone. ', 40);
        $org = $this->makeOrg(['description' => $long]);

        $this->assertSame($long, Masjid::find($org->id)->description);
    }
}
