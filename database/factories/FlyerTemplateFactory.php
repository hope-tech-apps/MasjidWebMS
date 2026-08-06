<?php

namespace Database\Factories;

use App\Models\FlyerTemplate;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FlyerTemplate>
 *
 * Test-only factory. The default state is a TENANT-OWNED design (a masjid_id,
 * is_system false) because that is the row a tenant may edit; use ->system() for
 * the shared designs that ship with the product, and seed those with the tenant
 * context UNBOUND or the BelongsToMasjid creating hook will stamp them.
 */
class FlyerTemplateFactory extends Factory
{
    protected $model = FlyerTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // flyer_templates.key is GLOBALLY unique (not unique-per-masjid), so a
            // uuid suffix keeps two factory rows in one test from colliding.
            'key' => 'test.' . Str::uuid(),
            'name' => 'Test Design',
            'kind' => 'event',
            // Same shape the Studio editor and the flyer-content validator read.
            'schema' => [
                'canvas' => ['width' => 1080, 'height' => 1350],
                'slots' => [
                    ['name' => 'headline', 'type' => 'text', 'default' => 'Join us', 'required' => true],
                    ['name' => 'body', 'type' => 'text', 'default' => null],
                ],
            ],
            'is_active' => true,
            'is_system' => false,
            // Tests normally pass masjid_id explicitly, or let a bound
            // TenantContext stamp it on create (mirrors ContactFactory).
            'masjid_id' => Masjid::first()?->id,
        ];
    }

    /**
     * A SHARED design: masjid_id null, is_system true. Every tenant sees it and
     * none may edit it.
     */
    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
            'masjid_id' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
