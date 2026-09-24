<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * "New client" and the drafts list (docs/manara-studio-w1.md S2, routes 1-3).
 */
class StudioDraftListTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
    }

    #[Test]
    public function a_new_draft_starts_with_no_colours_and_no_palette(): void
    {
        $admin = $this->actAsSuperAdmin();

        $data = $this->postJson(self::DRAFTS, ['org_type' => 'school', 'name' => 'Al-Razi Academy'])
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->json('data');

        $this->assertSame('draft', $data['status']);
        $this->assertSame('foundation', $data['current_step']);
        $this->assertSame(0, $data['lock_version']);
        $this->assertSame(1, $data['schema_version']);
        $this->assertSame(['identity' => ['org_type' => 'school', 'name' => 'Al-Razi Academy']], $data['answers']);
        $this->assertNull($data['logo']);
        // R25: no brand section and no report graded on somebody else's palette.
        $this->assertArrayNotHasKey('brand', $data['answers']);
        $this->assertNull($data['palette']);
        $this->assertSame($admin->id, StudioDraft::findOrFail($data['id'])->created_by);

        // With nothing sent the draft is empty, and still an object to the SPA.
        $empty = $this->postJson(self::DRAFTS, [])->assertCreated();
        $this->assertSame('{}', json_encode($empty->getData()->data->answers));
        $this->assertNull($empty->json('data.org_type'));

        $this->postJson(self::DRAFTS, ['org_type' => 'church'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['org_type'], 'data');
    }

    #[Test]
    public function the_list_is_newest_first_and_filtered_by_status(): void
    {
        $this->actAsSuperAdmin();

        $old = $this->newDraft(['name' => 'Old'])['id'];
        $new = $this->newDraft(['name' => 'New'])['id'];
        $live = $this->newDraft(['name' => 'Live'])['id'];
        $this->markProvisioned($live);

        $this->uploadLogo($new, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();

        // Distinct times, set last: ties would order by id and pin nothing.
        DB::table('studio_drafts')->where('id', $old)->update(['updated_at' => now()->subDays(3)]);
        DB::table('studio_drafts')->where('id', $new)->update(['updated_at' => now()->subDay()]);
        DB::table('studio_drafts')->where('id', $live)->update(['updated_at' => now()]);

        $rows = $this->getJson(self::DRAFTS)->assertOk()->assertJsonPath('status', 'success')->json('data');
        $this->assertSame([$new, $old], array_column($rows, 'id'), 'absent status means drafts, newest first');
        $this->assertSame(
            ['id', 'status', 'name', 'org_type', 'current_step', 'has_logo', 'provisioned_masjid_id', 'updated_at'],
            array_keys($rows[0]),
        );
        $this->assertTrue($rows[0]['has_logo']);
        $this->assertFalse($rows[1]['has_logo']);

        $this->assertSame([$live], array_column($this->getJson(self::DRAFTS . '?status=provisioned')->json('data'), 'id'));
        $this->assertSame([$live, $new, $old], array_column($this->getJson(self::DRAFTS . '?status=all')->json('data'), 'id'));

        $this->getJson(self::DRAFTS . '?status=abandoned')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status'], 'data');
    }
}
