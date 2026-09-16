<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listing an organisation is what puts it into a parent's app, and the phone
 * then draws that organisation's bottom tabs from its own switches.
 *
 * `MainTabView.swift` gates three of the four tabs — Announcements (legacy
 * feature id 10), Contact Us (11) and Donate (6) — and Home is the only one
 * always present. An organisation with all three switched off therefore opens,
 * for anybody who selects it in the switcher, as a single Home tab.
 *
 * BISS (org 18) is in exactly that state after the S2 cutover resolutions:
 * about_us, gallery, announcements, contact_requests, services and
 * donation_link all resolved off. It is invisible today only because BISS is
 * unlisted — so the thing that makes it visible is a LISTING TOGGLE, taken at
 * some later date by somebody who is not reading a cutover runbook. That is
 * why the warning lives at the toggle and not in the plan.
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
        // BISS's post-cutover shape, written as switches rather than as pivot
        // rows, because the switches are what survives S2b.
        $masjid = $this->makeMasjid('Burlington Islamic Sunday School', 'school', [
            'announcements' => false,
            'events' => false,
            'contact_requests' => false,
            'donation_link' => false,
            'giving' => false,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
            ->assertOk();

        $message = (string) $response->json('message');

        $this->assertStringContainsString('single Home tab', $message);
        $this->assertStringContainsString('Burlington Islamic Sunday School', $message);

        // A warning, not a refusal: the organisation really is listed.
        $this->assertTrue($masjid->fresh()->isListed(), 'the warning must not have blocked the write');
    }

    #[Test]
    public function one_gated_tab_left_on_is_enough_to_stay_quiet(): void
    {
        // Contact Us alone keeps the tab bar from collapsing, so this must NOT
        // warn — a warning on an ordinary listing would be trained away within
        // a week and the real one would go unread with it.
        $masjid = $this->makeMasjid('Has A Contact Form', 'school', [
            'announcements' => false,
            'events' => false,
            'contact_requests' => true,
            'donation_link' => false,
            'giving' => false,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $message = (string) $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
            ->assertOk()
            ->json('message');

        $this->assertStringNotContainsString('single Home tab', $message);
    }

    #[Test]
    public function unlisting_never_warns_because_nothing_is_being_shown_to_anyone(): void
    {
        $masjid = $this->makeMasjid('Going Dark', 'school', [
            'announcements' => false,
            'events' => false,
            'contact_requests' => false,
            'donation_link' => false,
            'giving' => false,
        ]);
        $masjid->listed_at = now();
        $masjid->save();

        Sanctum::actingAs($this->superAdmin());

        $message = (string) $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => false])
            ->assertOk()
            ->json('message');

        $this->assertStringNotContainsString('single Home tab', $message);
        $this->assertFalse($masjid->fresh()->isListed());
    }

    #[Test]
    public function a_masjid_keeps_its_tabs_by_default_and_is_listed_without_comment(): void
    {
        // The defaults for a masjid turn all three on, so the ordinary case —
        // publishing a mosque — must be silent. This is the test that fails if
        // somebody inverts the condition.
        $masjid = $this->makeMasjid('Ordinary Masjid', 'masjid', []);

        Sanctum::actingAs($this->superAdmin());

        $message = (string) $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
            ->assertOk()
            ->json('message');

        $this->assertStringNotContainsString('single Home tab', $message);
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    private function makeMasjid(string $name, string $orgType, array $overrides): Masjid
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
        $masjid->capability_overrides = $overrides === [] ? null : $overrides;
        $masjid->save();

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
