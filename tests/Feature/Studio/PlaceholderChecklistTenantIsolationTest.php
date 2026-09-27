<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidUser;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * The starter-site checklist (Studio W2 S10) reads Page and Section, which are
 * hand-scoped rather than BelongsToMasjid, so it filters by masjid_id itself.
 * Another tenant's pages and sections never appear, even through a pivot row
 * that crosses tenants, and another tenant's admin is refused.
 */
class PlaceholderChecklistTenantIsolationTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    private function studioOrg(): int
    {
        return (int) $this->provision($this->draftWith($this->studioAnswers('masjid', self::MINIMAL))->id)
            ->assertCreated()->json('data.masjid_id');
    }

    #[Test]
    public function another_tenants_pages_never_appear(): void
    {
        $a = $this->studioOrg();
        $b = $this->studioOrg();

        // A pathological pivot row: one of B's marked sections attached to A's home page.
        $bSection = Section::where('masjid_id', $b)->whereNotNull('settings')->firstOrFail();
        Page::where('masjid_id', $a)->where('slug', 'home')->firstOrFail()->sections()->attach($bSection->id, ['order' => 99]);

        $data = $this->getJson("/api/admin/masjids/{$a}/pages/placeholders")->assertOk()->json('data');

        $pageIds = collect($data['pages'])->pluck('page_id')->all();
        $sectionIds = collect($data['pages'])->flatMap(fn ($p) => $p['sections'])->pluck('section_id')->all();

        $this->assertSame([], array_values(array_diff($pageIds, Page::where('masjid_id', $a)->pluck('id')->all())));
        $this->assertSame([], array_values(array_diff($sectionIds, Section::where('masjid_id', $a)->pluck('id')->all())));
        $this->assertNotContains($bSection->id, $sectionIds);

        // An admin of A is refused B's checklist by the tenant rules.
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $a, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        foreach ([$a, $b] as $org) {
            $page = \App\Models\Masjid::findOrFail($org);
            $page->forceFill(['capability_overrides' => ['web_pages' => true]])->save();
        }
        Sanctum::actingAs($admin->fresh());

        $this->getJson("/api/admin/masjids/{$a}/pages/placeholders")->assertOk();
        $this->getJson("/api/admin/masjids/{$b}/pages/placeholders")->assertForbidden();
    }
}
