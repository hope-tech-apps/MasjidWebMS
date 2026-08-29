<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\MobileAppFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mobile media BOUNDARY, pinned.
 *
 * On 2026-08-28 the `featuresIcons` media rows were gone, GET
 * /api/mobile/masjids/{id}/features served every feature with `icon: null`, and
 * the Flutter drawer — which force-unwraps `feature.icon!.originalUrl!` — blanked
 * for every masjid. The same shape is armed on announcements, services, about
 * and donate. The server floor (App\Support\MobileMedia) is that no mobile
 * endpoint may emit a null media object the client force-unwraps; this suite is
 * the executable statement of that floor, so a regression ships red, not live.
 */
class MobileMediaBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,string> real-fs files a test staged and must remove. */
    private array $tempFiles = [];

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

        Storage::fake('public');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    #[Test]
    public function the_features_endpoint_never_returns_a_null_icon_even_with_no_media(): void
    {
        $masjid = $this->makeMasjid();

        foreach ([['Qur\'an', 'quran'], ['Hadith', 'hadith']] as [$name, $key]) {
            $feature = MobileAppFeature::create(['name' => $name, 'key' => $key]);
            $masjid->features()->attach($feature->id, ['is_available' => 1]);
        }

        $response = $this->getJson("/api/mobile/masjids/{$masjid->id}/features")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $feature) {
            $this->assertNotNull($feature['icon'], 'a feature was served with a null icon object');
            $this->assertNotNull(
                $feature['icon']['original_url'],
                'a feature icon carried a null original_url — the exact shape that crashed the drawer'
            );
            $this->assertNotEmpty($feature['icon']['original_url']);
        }
    }

    #[Test]
    public function the_features_endpoint_uses_the_real_icon_when_it_is_present(): void
    {
        $masjid = $this->makeMasjid();

        $feature = MobileAppFeature::create(['name' => 'Qur\'an', 'key' => 'quran']);
        $feature->addMediaFromString('<svg xmlns="http://www.w3.org/2000/svg"/>')
            ->usingFileName('quran.svg')
            ->toMediaCollection('featuresIcons');
        $masjid->features()->attach($feature->id, ['is_available' => 1]);

        $data = $this->getJson("/api/mobile/masjids/{$masjid->id}/features")->assertOk()->json('data');

        $url = $data[0]['icon']['original_url'];
        $this->assertNotNull($url);
        $this->assertStringNotContainsString(
            'mobile-assets/placeholder',
            $url,
            'the endpoint served a placeholder when a real featuresIcons media existed'
        );
    }

    #[Test]
    public function the_announcements_endpoint_never_returns_a_null_image_url(): void
    {
        $masjid = $this->makeMasjid();

        Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => 'Imageless notice',
            'summary' => 'summary',
            'details' => 'details',
            'text' => 'text',
            'start_date' => '2026-08-28',
            'end_date' => '2026-09-28',
        ]);

        $data = $this->getJson("/api/mobile/masjids/{$masjid->id}/announcements")->assertOk()->json('data');

        $this->assertNotEmpty($data, 'an imageless announcement must be served, not dropped');
        foreach ($data as $announcement) {
            $this->assertNotNull($announcement['image'], 'an announcement was served with a null image object');
            $this->assertNotNull(
                $announcement['image']['original_url'],
                'an announcement image carried a null original_url — crashes the carousel'
            );
        }
    }

    #[Test]
    public function features_ensure_icons_restores_a_missing_icon(): void
    {
        $feature = MobileAppFeature::create(['name' => 'Qur\'an', 'key' => 'quran']);
        $this->assertNull($feature->icon, 'precondition: the feature starts with no icon');

        // Stage the source SVG where the command reads it from (the real fs — the
        // command copies out of storage/app/public/icons, not the faked disk).
        $dir = storage_path('app/public/icons');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $src = $dir.'/alqurann.svg';
        file_put_contents($src, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->tempFiles[] = $src;

        $exit = Artisan::call('app:features-ensure-icons', ['--json' => true]);

        $this->assertSame(0, $exit, 'the recovery command exited non-zero: '.Artisan::output());
        $this->assertNotNull($feature->fresh()->icon, 'app:features-ensure-icons did not attach the missing icon');
    }
}
