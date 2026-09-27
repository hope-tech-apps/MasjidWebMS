<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * The `masjid_domains` table as MySQL will hold it. The suite runs on SQLite,
 * which enforces neither varchar lengths nor the 64-character identifier cap,
 * so what production would refuse is asserted here by TYPE and by NAME
 * (.claude/rules/shipping.md, .claude/rules/migrations.md).
 */
class MasjidDomainSchemaTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    /** The staging scrub's personal-data tokens (StagingScrubCoverageTest::PII_TOKENS). */
    private const PII_TOKENS = [
        'email', 'phone', 'first_name', 'last_name', 'name', 'address', 'dob',
        'birth', 'token', 'secret', 'ip', 'user_agent', 'evidence', 'notes',
        'note', 'message', 'body', 'content', 'data', 'payload',
        'subscription_id', 'device',
    ];

    #[Test]
    public function a_host_can_be_recorded_only_once(): void
    {
        $a = $this->makeOrg();
        $b = $this->makeOrg();
        $this->makeDomain($a, 'www.example.org');

        $this->expectException(QueryException::class);

        // Spelled differently on purpose: the mutator normalises it to the same
        // string, and the unique index is what refuses the second owner.
        $this->makeDomain($b, 'WWW.Example.org.');
    }

    #[Test]
    public function last_error_is_text_because_it_holds_cloudflares_own_words(): void
    {
        $this->assertSame('text', Schema::getColumnType('masjid_domains', 'last_error'));
    }

    #[Test]
    public function status_and_kind_are_plain_strings_not_enums(): void
    {
        foreach (['status', 'kind', 'source', 'waiting_on', 'verified_by'] as $column) {
            $this->assertContains(
                Schema::getColumnType('masjid_domains', $column),
                ['varchar', 'string'],
                "masjid_domains.{$column} must be a string so a new value never needs an ALTER"
            );
        }

        foreach (['host', 'zone_apex'] as $column) {
            $this->assertContains(Schema::getColumnType('masjid_domains', $column), ['varchar', 'string']);
        }

        $this->assertSame(MasjidDomain::STATUS_PENDING, $this->makeDomain($this->makeOrg(), 'a.example.org')->fresh()->status);
    }

    #[Test]
    public function the_created_flags_default_to_false_so_no_existing_row_is_ever_deletable_by_detach(): void
    {
        // W2 S3. A row that predates the flags must read as "Studio did not
        // create it": DomainDetacher deletes only what a flag says Studio made.
        foreach (['cf_dns_record_created', 'cf_pages_domain_created'] as $column) {
            $this->assertContains(Schema::getColumnType('masjid_domains', $column), ['tinyint', 'boolean', 'integer'], $column);
        }
        $this->assertContains(Schema::getColumnType('masjid_domains', 'adopted_from_import_at'), ['timestamp', 'datetime'], 'adopted_from_import_at');

        $id = DB::table('masjid_domains')->insertGetId([
            'masjid_id' => $this->makeOrg()->id,
            'host' => 'raw.example.org',
            'kind' => MasjidDomain::KIND_CUSTOM,
            'zone_apex' => 'example.org',
            'cf_dns_record_id' => 'rec-1',
            'cf_pages_domain_id' => 'pd-1',
        ]);
        $row = MasjidDomain::findOrFail($id);

        $this->assertFalse($row->cf_dns_record_created);
        $this->assertFalse($row->cf_pages_domain_created);
        $this->assertNull($row->adopted_from_import_at);
    }

    #[Test]
    public function detaching_is_a_status_the_row_may_hold_and_is_never_served(): void
    {
        $this->assertContains(MasjidDomain::STATUS_DETACHING, MasjidDomain::STATUSES);
        $this->assertNotContains(MasjidDomain::STATUS_DETACHING, MasjidDomain::SERVED);
        $this->assertNotContains(MasjidDomain::STATUS_DETACHING, MasjidDomain::TRUSTED);

        $row = $this->makeDomain($this->makeOrg(), 'gone.example.org', MasjidDomain::STATUS_DETACHING);
        $this->assertSame(MasjidDomain::STATUS_DETACHING, $row->fresh()->status);
        $this->assertSame([], MasjidDomain::query()->served()->pluck('id')->all());
    }

    #[Test]
    public function every_index_name_fits_mysqls_64_character_limit(): void
    {
        $names = array_column(Schema::getIndexes('masjid_domains'), 'name');

        $this->assertContains('md_masjid_status_idx', $names);
        $this->assertContains('masjid_domains_host_unique', $names);

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "index {$name} is longer than MySQL allows");
        }

        foreach (Schema::getForeignKeys('masjid_domains') as $foreignKey) {
            // SQLite reports foreign keys unnamed; the name Laravel would give
            // them on MySQL is <table>_<column>_foreign.
            $name = $foreignKey['name'] ?: 'masjid_domains_' . implode('_', $foreignKey['columns']) . '_foreign';
            $this->assertLessThanOrEqual(64, strlen($name), "foreign key {$name} is longer than MySQL allows");
        }
    }

    #[Test]
    public function no_column_name_looks_like_personal_data_to_the_staging_scrub(): void
    {
        $pattern = '/(?:^|_)(?:' . implode('|', self::PII_TOKENS) . ')(?:$|_)/i';

        $flagged = array_values(array_filter(
            Schema::getColumnListing('masjid_domains'),
            fn (string $column) => preg_match($pattern, $column) === 1,
        ));

        $this->assertSame([], $flagged);
        $this->assertTrue(DB::table('masjid_domains')->doesntExist());
    }
}
