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
