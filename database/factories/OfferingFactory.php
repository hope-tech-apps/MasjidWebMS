<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\Offering;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Offering>
 */
class OfferingFactory extends Factory
{
    protected $model = Offering::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            // Default to an existing masjid (mirrors ContactFactory). Tests
            // normally pass masjid_id explicitly or use forMasjid(), or let a
            // bound TenantContext stamp it on create.
            'masjid_id' => Masjid::first()?->id,
            'kind' => Offering::KIND_EVENT,
            'name' => $name,
            // Suffixed so two tenants seeded in one run never collide on the
            // per-masjid (masjid_id, slug) unique index.
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'intake_form_id' => Form::factory(),
            'group_id' => null,
            'capacity' => null,           // unlimited
            'registration_count' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Pin the offering AND its intake form to one masjid — the default lets
     * Form::factory() fall to Masjid::first(), which is the wrong tenant in
     * any multi-masjid test.
     */
    public function forMasjid(Masjid $masjid): self
    {
        return $this->state(fn () => [
            'masjid_id' => $masjid->id,
            'intake_form_id' => Form::factory(['masjid_id' => $masjid->id]),
        ]);
    }

    /** A program whose confirmed registrants materialise into a group roster. */
    public function withRoster(Group $group): self
    {
        return $this->state(fn () => [
            'kind' => Offering::KIND_PROGRAM,
            'group_id' => $group->id,
        ]);
    }

    /** Capacity-bounded with seats still available. */
    public function withCapacity(int $capacity, int $taken = 0): self
    {
        return $this->state(fn () => [
            'capacity' => $capacity,
            'registration_count' => min($taken, $capacity),
        ]);
    }

    /** Sold out: every seat reserved. */
    public function atCapacity(int $capacity = 5): self
    {
        return $this->state(fn () => [
            'capacity' => $capacity,
            'registration_count' => $capacity,
        ]);
    }

    /** Registration window closed in the past. */
    public function closed(): self
    {
        return $this->state(fn () => [
            'opens_at' => now()->subMonths(2),
            'closes_at' => now()->subMonth(),
        ]);
    }
}
