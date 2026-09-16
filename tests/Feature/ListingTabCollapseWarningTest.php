<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MobileAppFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listing an organisation is what puts it into a parent's app, and the phone
 * then draws that organisation's bottom tabs from its row in `/features`.
 *
 * BOTH clients gate the same three tabs on the same three legacy ids — iOS in
 * `MainTabView.swift`, Android in `BottomBar.kt::visibleTabs` — Announcements
 * (10), Contact Us (11) and Donate (6). Home is never gated. So an organisation
 * with all three off opens, for anybody who selects it, as a single Home tab.
 *
 * BISS (org 18) reaches that state through the S2 cutover resolutions. It is
 * invisible today only because BISS is unlisted, so the thing that makes it
 * visible is a LISTING TOGGLE taken at some later date by somebody who is not
 * reading a cutover runbook. That is why the warning lives at the toggle.
 *
 * These tests drive the PIVOT, because that is what `/features` serves — and
 * therefore what the apps gate on — until the cutover retires it. An earlier
 * version of this guard read the capability switches instead, which would have
 * told an operator that BISS "opens as a single Home tab" while its pivot still
 * drew four: false, in the present tense, about the one organisation the guard
 * exists for.
 *
 * It WARNS rather than refuses on purpose. A one-tab profile is a legitimate
 * thing to publish; the defect would be publishing one by accident.
 */
class ListingTabCollapseWarningTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $countryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);
    }

    #[Test]
    public function listing_an_organisation_whose_three_gated_tabs_are_off_says_so(): void
    {
        $masjid = $this->makeMasjid('Burlington Islamic Sunday School', 'school', [
            10 => false,
            11 => false,
            6 => false,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $message = (string) $this->patchJson(
            "/api/admin/masjids/{$masjid->id}/directory-listing",
            ['listed' => true]
        )->assertOk()->json('message');

        $this->assertStringContainsString('single Home tab', $message);
        $this->assertStringContainsString('Burlington Islamic Sunday School', $message);

        // A warning, not a refusal: the organisation really is listed.
        $this->assertTrue($masjid->fresh()->isListed(), 'the warning must not have blocked the write');
    }

    #[Test]
    public function one_gated_tab_left_on_is_enough_to_stay_quiet(): void
    {
        // Contact Us alone keeps the bar from collapsing, so this must NOT warn.
        // A warning on an ordinary listing would be trained away within a week
        // and the real one would go unread with it.
        $masjid = $this->makeMasjid('Has A Contact Form', 'school', [
            10 => false,
            11 => true,
            6 => false,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->assertNull(
            $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
                ->assertOk()
                ->json('message'),
            'one tab on must send no message, so the screen keeps its own specific confirmation'
        );
    }

    #[Test]
    public function unlisting_never_warns_because_nothing_is_being_shown_to_anyone(): void
    {
        $masjid = $this->makeMasjid('Going Dark', 'school', [
            10 => false,
            11 => false,
            6 => false,
        ]);
        $masjid->listed_at = now();
        $masjid->save();

        Sanctum::actingAs($this->superAdmin());

        $this->assertNull(
            $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => false])
                ->assertOk()
                ->json('message'),
            'unlisting must keep the screen own "removed from the directory" sentence'
        );
        $this->assertFalse($masjid->fresh()->isListed());
    }

    #[Test]
    public function an_organisation_with_its_tabs_on_is_listed_without_comment(): void
    {
        // The ordinary case — publishing an organisation whose tabs are on —
        // must be silent. This is the test that fails if somebody inverts the
        // condition, and the one that keeps the warning worth reading.
        $masjid = $this->makeMasjid('Ordinary Masjid', 'masjid', [
            10 => true,
            11 => true,
            6 => true,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->assertNull(
            $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
                ->assertOk()
                ->json('message'),
            'a listing with tabs on must send no message'
        );
    }

    #[Test]
    public function an_organisation_with_no_pivot_rows_at_all_is_not_called_a_one_tab_app(): void
    {
        // No rows is "not configured yet", not "everything off" — and Android's
        // empty-features branch deliberately shows the whole bar in that case.
        // Org 17 is in exactly this state on production.
        $masjid = $this->makeMasjid('Never Configured', 'masjid', []);
        DB::table('masjid_mobile_app_features')->where('masjid_id', $masjid->id)->delete();

        Sanctum::actingAs($this->superAdmin());

        $this->assertNull(
            $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
                ->assertOk()
                ->json('message'),
            'an unconfigured organisation must not be described as a one-tab app'
        );
    }

    /**
     * @param  array<int, bool>  $tabFeatures  legacy feature id => available
     */
    private function makeMasjid(string $name, string $orgType, array $tabFeatures): Masjid
    {
        $masjid = Masjid::create([
            'name' => $name,
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'address' => '1 Test St',
            'latitude' => 43.32,
            'longitude' => -79.79,
            'timezone' => 'America/Toronto',
        ]);

        $masjid->org_type = $orgType;
        $masjid->save();

        // One pivot row per gated tab, exactly as production carries them.
        //
        // The ids are FORCED to the legacy numbers rather than auto-assigned.
        // The apps gate on the id — 6, 10, 11 — and so does the controller, so a
        // fixture whose `donate` row happened to be id 3 would make every one of
        // these tests pass or fail for a reason that has nothing to do with the
        // guard. Production has carried 1-11 since the table was created.
        foreach ([10 => 'announcements', 11 => 'contact_us', 6 => 'donate'] as $legacyId => $key) {
            if (! MobileAppFeature::whereKey($legacyId)->exists()) {
                DB::table('mobile_app_features')->insert([
                    'id' => $legacyId,
                    'key' => $key,
                    'name' => ucfirst(str_replace('_', ' ', $key)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('masjid_mobile_app_features')->insert([
                'masjid_id' => $masjid->id,
                'feature_id' => $legacyId,
                'is_available' => ($tabFeatures[$legacyId] ?? false) ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $masjid->fresh();
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }
}
