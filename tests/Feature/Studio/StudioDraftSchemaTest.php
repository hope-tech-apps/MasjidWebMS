<?php

namespace Tests\Feature\Studio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * The studio_drafts columns, asserted by TYPE. The suite runs on SQLite, which
 * enforces no VARCHAR length, so a round trip passes whatever the declared
 * length; only the type says what MySQL will refuse (.claude/rules/shipping.md).
 */
class StudioDraftSchemaTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
    }

    #[Test]
    public function answers_is_json_on_mysql_and_text_on_sqlite(): void
    {
        $expected = DB::getDriverName() === 'mysql' ? 'json' : 'text';

        $this->assertSame($expected, Schema::getColumnType('studio_drafts', 'answers'));
    }

    #[Test]
    public function name_is_a_string(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('studio_drafts', 'name'));
        $this->assertSame('varchar', Schema::getColumnType('studio_drafts', 'logo_original_name'));
        $this->assertSame('varchar', Schema::getColumnType('studio_drafts', 'status'));
    }

    #[Test]
    public function the_status_index_is_named_by_hand_under_mysqls_64_character_limit(): void
    {
        $names = array_column(Schema::getIndexes('studio_drafts'), 'name');

        $this->assertContains('studio_drafts_status_updated_idx', $names);

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "{$name} is longer than MySQL allows");
        }
    }

    #[Test]
    public function a_300_character_logo_filename_is_truncated_rather_than_refused(): void
    {
        $this->actAsSuperAdmin();
        $id = $this->newDraft()['id'];
        $name = str_repeat('ب', 296) . '.png';

        $this->uploadLogo($id, $this->realUpload($name, $this->pngBytes()))
            ->assertOk()
            ->assertJsonPath('data.logo.original_name', mb_substr($name, 0, 255));

        $this->assertSame(255, mb_strlen((string) DB::table('studio_drafts')->where('id', $id)->value('logo_original_name')));
    }
}
