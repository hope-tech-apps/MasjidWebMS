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
 * The public organisation directory gate — `masjids.listed_at`.
 *
 * GET /api/mobile/masjids is the picker every mobile app opens with, and it
 * returned `Masjid::with('logo')->get()`: EVERY row, no published/active gate,
 * cached for a day. Creating an organisation therefore published it to real
 * users within 24 hours, which is why the `test2` seed tenant is currently
 * offered to congregations.
 *
 * What this pins:
 *  - the directory shows only listed organisations;
 *  - provisioning does NOT list — creating is no longer publishing;
 *  - a SuperAdmin can publish, and the day-long cache does not outlive the
 *    decision;
 *  - nobody below SuperAdmin can publish;
 *  - and the per-organisation endpoint is deliberately NOT gated, so unlisting
 *    never breaks an app that already holds the id.
 */
class MasjidDirectoryListingTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $countryId;

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

        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);

        foreach (['about_us', 'announcements', 'contact_us', 'donate', 'gallery', 'services'] as $key) {
            MobileAppFeature::create(['name' => ucfirst($key), 'key' => $key]);
        }
    }

    #[Test]
    public function the_directory_shows_listed_organisations_and_hides_unlisted_ones(): void
    {
        $listed = $this->makeMasjid('Listed Masjid');
        $listed->listed_at = now();
        $listed->save();

        $unlisted = $this->makeMasjid('Unlisted Masjid');

        $ids = $this->directoryIds();

        $this->assertContains($listed->id, $ids);
        $this->assertNotContains($unlisted->id, $ids);
    }

    #[Test]
    public function a_newly_provisioned_organisation_is_not_published(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $created = $this->postJson('/api/admin/onboarding/provision', [
            'name' => 'Brand New School',
            'org_type' => 'school',
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+15551234567',
            'address' => '1 Test St',
            'latitude' => 43.32,
            'longitude' => -79.79,
            'timezone' => 'America/Toronto',
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
            'platforms' => ['web'],
        ])->assertCreated();

        $masjid = Masjid::findOrFail($created->json('data.masjid_id'));

        $this->assertNull($masjid->listed_at, 'provisioning published the organisation');
        $this->assertFalse($masjid->isListed());
        $this->assertNotContains($masjid->id, $this->directoryIds());
    }

    #[Test]
    public function a_super_admin_can_publish_and_unpublish_an_organisation(): void
    {
        $masjid = $this->makeMasjid('Ready When You Are');

        // Warm the day-long cache with the un-published directory first: the
        // toggle has to invalidate it, or the decision does not reach the apps
        // for up to TTL_DAY.
        $this->assertNotContains($masjid->id, $this->directoryIds());

        Sanctum::actingAs($this->superAdmin());

        $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotNull($masjid->fresh()->listed_at);
        $this->assertContains($masjid->id, $this->directoryIds());

        $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => false])
            ->assertOk();

        $this->assertNull($masjid->fresh()->listed_at);
        $this->assertNotContains($masjid->id, $this->directoryIds());
    }

    #[Test]
    public function publishing_an_already_published_organisation_keeps_its_original_timestamp(): void
    {
        $masjid = $this->makeMasjid('Long Since Live');
        $masjid->listed_at = now()->subYear();
        $masjid->save();

        $original = $masjid->fresh()->listed_at;

        Sanctum::actingAs($this->superAdmin());

        $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
            ->assertOk();

        $this->assertTrue(
            $original->equalTo($masjid->fresh()->listed_at),
            'a no-op publish rewrote "listed since"'
        );
    }

    #[Test]
    public function a_masjid_admin_cannot_publish_an_organisation(): void
    {
        $masjid = $this->makeMasjid('Not Yours To Publish');

        Sanctum::actingAs(User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));

        $this->patchJson("/api/admin/masjids/{$masjid->id}/directory-listing", ['listed' => true])
            ->assertForbidden();

        $this->assertNull($masjid->fresh()->listed_at);
    }

    /**
     * Unlisting is a discoverability decision, not a revocation. An app that
     * already holds this id keeps working — gating the show endpoint too would
     * break every existing install the moment an operator pulled an
     * organisation out of the picker.
     */
    #[Test]
    public function an_unlisted_organisation_is_still_readable_by_id(): void
    {
        $masjid = $this->makeMasjid('Quietly Unlisted');

        $this->getJson("/api/mobile/masjids/{$masjid->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $masjid->id);
    }

    /** Ids the mobile directory currently offers. */
    private function directoryIds(): array
    {
        return array_column(
            $this->getJson('/api/mobile/masjids')->assertOk()->json('data'),
            'id'
        );
    }

    private function makeMasjid(string $name): Masjid
    {
        return Masjid::create([
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
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }
}
