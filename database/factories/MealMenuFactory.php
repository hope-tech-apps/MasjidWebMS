<?php

namespace Database\Factories;

use App\Models\MealMenu;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealMenu>
 */
class MealMenuFactory extends Factory
{
    protected $model = MealMenu::class;

    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            'title' => 'Jummah Lunch',
            // A unique DAY, not a unique DateTime: `unique()->dateTimeBetween()` only
            // de-duplicated the instants (with their time of day), so two menus in one
            // test could still land on the same date and trip unique(masjid_id,
            // service_date) about one run in 120 per extra menu — which the full suite
            // hit on 2026-09-25 in StaffLunchOrderTest. The window stays 1–120 days, so
            // the hand-picked dates tests place outside it (StaffLunchOrderTest's +200)
            // still never collide.
            'service_date' => now()->addDays(fake()->unique()->numberBetween(1, 120))->toDateString(),
            'status' => MealMenu::STATUS_DRAFT,
            'ordering_closes_at' => null,
            'pickup_instructions' => 'Pick up after Jummah in the main hall.',
            'allow_online_payment' => true,
            'allow_pay_at_pickup' => true,
            'currency' => 'usd',
        ];
    }

    public function forMasjid(Masjid $masjid): static
    {
        return $this->state(['masjid_id' => $masjid->id]);
    }

    public function open(): static
    {
        return $this->state([
            'status' => MealMenu::STATUS_OPEN,
            'ordering_closes_at' => now()->addDay(),
        ]);
    }

    /** A standing kitchen catalogue: no service date, the owner's 48-hour lead time. */
    public function catalogue(): static
    {
        return $this->state([
            'kind' => MealMenu::KIND_CATALOGUE,
            'title' => 'Halal Kitchen',
            'service_date' => null,
            'pickup_lead_hours' => MealMenu::DEFAULT_PICKUP_LEAD_HOURS,
            'pickup_instructions' => 'Pick up at the front office.',
        ]);
    }

    public function closed(): static
    {
        return $this->state(['status' => MealMenu::STATUS_CLOSED]);
    }
}
