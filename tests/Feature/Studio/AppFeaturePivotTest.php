<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Support\AppFeaturePivot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\SeedsAppFeatureCatalogue;
use Tests\TestCase;

/**
 * The legacy app-feature rows a new organisation's switches imply
 * (docs/manara-studio-w1.md S4): what Studio's Android frame draws, and what S8
 * seeds, so the mockup and the installed Android app agree.
 */
class AppFeaturePivotTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAppFeatureCatalogue;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seedAppFeatureCatalogue();
    }

    #[Test]
    public function rows_are_derived_by_id_so_the_production_quran_key_is_found(): void
    {
        // The premise, asserted rather than assumed: no row is keyed `quran`.
        $quranId = (int) MobileAppFeature::where('key', self::PRODUCTION_QURAN_KEY)->value('id');
        $this->assertSame(1, $quranId);
        $this->assertFalse(MobileAppFeature::where('key', 'quran')->exists());

        $masjid = AppFeaturePivot::rowsFor(new Masjid(['name' => 'A Masjid', 'org_type' => 'masjid']));

        // One row per catalogue id, every one of them on for a masjid by default,
        // Qur'an included.
        $this->assertSame(range(1, 11), array_keys($masjid));
        $this->assertSame(array_fill(1, 11, true), $masjid);

        // A school is not offered the worship modules or the masjid screens.
        $school = AppFeaturePivot::rowsFor(new Masjid(['name' => 'A School', 'org_type' => 'school']));
        $this->assertFalse($school[$quranId]);
        $this->assertFalse($school[6], 'Donate');
        $this->assertTrue($school[10], 'Announcements');
        $this->assertTrue($school[11], 'Contact Us');

        // Switching Qur'an off reaches the production-keyed row.
        $off = new Masjid(['name' => 'A Masjid', 'org_type' => 'masjid']);
        $off->forceFill(['capability_overrides' => ['quran' => false]]);
        $this->assertFalse(AppFeaturePivot::rowsFor($off)[$quranId]);
    }

    /**
     * seedFromSwitches (S8) writes a NEW organisation's rows, and refuses an
     * organisation that already has any: an installed app reads them, and
     * replacing them is the app-features cutover's decision, not provisioning's.
     */
    #[Test]
    public function seeding_writes_the_derived_rows_once_and_refuses_an_org_that_has_rows(): void
    {
        $org = Masjid::create([
            'name' => 'Seeded School', 'email' => 'seeded@test.local', 'phone' => '+15550003333', 'org_type' => 'school',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
        ]);

        $this->assertSame(AppFeaturePivot::rowsFor($org), AppFeaturePivot::seedFromSwitches($org));
        $this->assertSame(
            AppFeaturePivot::rowsFor($org),
            MasjidMobileAppFeature::where('masjid_id', $org->id)->orderBy('feature_id')->pluck('is_available', 'feature_id')->map(fn ($v) => (bool) $v)->all(),
        );

        try {
            AppFeaturePivot::seedFromSwitches($org);
            $this->fail('a second seed was accepted');
        } catch (\LogicException) {
            $this->assertSame(11, MasjidMobileAppFeature::where('masjid_id', $org->id)->count(), 'nothing was added or replaced');
        }
    }
}
