<?php

namespace Database\Factories;

use App\Models\Flyer;
use App\Models\FlyerTemplate;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Flyer>
 *
 * Test-only factory. `uuid` is deliberately not set — the model's creating hook
 * assigns one, and leaving it out is what keeps that hook covered.
 */
class FlyerFactory extends Factory
{
    protected $model = Flyer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Tests normally pass masjid_id explicitly, or let a bound
            // TenantContext stamp it on create (mirrors ContactFactory).
            'masjid_id' => Masjid::first()?->id,
            // The scope is bypassed so this resolves the same design whether or
            // not a tenant happens to be bound while the factory runs; tests that
            // care which template a flyer uses pass the id themselves.
            'flyer_template_id' => FlyerTemplate::withoutMasjidScope()->value('id'),
            'title' => 'Test Flyer',
            // Both columns are NOT NULL json.
            'content' => ['headline' => 'Community Iftar'],
            'palette' => ['primary' => '#01B151'],
            'status' => 'draft',
            'cutout_status' => 'none',
        ];
    }
}
