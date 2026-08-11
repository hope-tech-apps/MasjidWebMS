<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactCredential;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContactCredential>
 */
class ContactCredentialFactory extends Factory
{
    protected $model = ContactCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to existing rows (mirrors ContactFactory). Tests normally
            // pass masjid_id/contact_id explicitly, or let a bound
            // TenantContext stamp the tenant on create.
            'masjid_id' => Masjid::first()?->id,
            'contact_id' => Contact::first()?->id,
            'kind' => ContactCredential::KIND_MEDICAL_LICENSE,
            'issuing_body' => fake()->company(),
            'identifier' => strtoupper(fake()->bothify('??-########')),
            'issued_at' => Carbon::today()->subYear(),
            'expires_at' => Carbon::today()->addYear(),
        ];
    }

    /** Expiry already behind us. */
    public function expired(int $daysAgo = 10): static
    {
        return $this->state(fn () => ['expires_at' => Carbon::today()->subDays($daysAgo)]);
    }

    /** Expiry N days out — inside or outside a window, as the test needs. */
    public function expiringInDays(int $days): static
    {
        return $this->state(fn () => ['expires_at' => Carbon::today()->addDays($days)]);
    }

    /** No expiry at all (a one-time background check, a lifetime cert). */
    public function nonExpiring(): static
    {
        return $this->state(fn () => ['expires_at' => null]);
    }
}
