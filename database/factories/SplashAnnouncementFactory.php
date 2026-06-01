<?php

namespace Database\Factories;

use App\Models\SplashAnnouncement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SplashAnnouncement>
 *
 * Test-only factory. The production schema constrains masjid_id with a foreign
 * key, so callers either pass a real Masjid::factory()->create() id or let
 * this factory create one. We deliberately do NOT attach a media file in the
 * default state — tests that exercise the image relation use Storage::fake()
 * and addMediaFromRequest themselves, which keeps the factory side-effect-free.
 */
class SplashAnnouncementFactory extends Factory
{
    protected $model = SplashAnnouncement::class;

    public function definition(): array
    {
        // No MasjidFactory exists in this codebase yet; tests pass an explicit
        // masjid_id from a Masjid::create([...]) helper instead of relying on
        // Masjid::factory(). We default to 1 so callers that forget to pass one
        // get a clear FK violation rather than silent success.
        return [
            'masjid_id' => 1,
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'cta_label' => null,
            'cta_url' => null,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addDay(),
            'priority' => 0,
            'is_active' => true,
            'onesignal_iam_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function future(): static
    {
        return $this->state(fn () => [
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDays(2),
        ]);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'starts_at' => Carbon::now()->subDays(2),
            'ends_at' => Carbon::now()->subDay(),
        ]);
    }

    public function withIamId(string $id = 'iam_existing_123'): static
    {
        return $this->state(fn () => ['onesignal_iam_id' => $id]);
    }
}
