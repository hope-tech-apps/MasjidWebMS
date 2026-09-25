<?php

namespace Tests\Support;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Services\Stripe\FormResponseCheckoutService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\StripeClient;

/**
 * The two Ramadan-giving form shapes (Zakat-ul-Fitr per person; iftar sponsorship
 * levels, some reserving a date), a Stripe that only records what it was asked for,
 * and the public submit as the renderer sends it. Shared by FormQuantityPaymentTest and
 * FormDateReservationTest. Every name, email and phone here is invented.
 *
 * Stripe is stubbed through FormResponseCheckoutService's protected seams: pages are
 * cs_ramadan_1, cs_ramadan_2… and each is `open` until a test says otherwise.
 */
trait MakesRamadanGivingForms
{
    protected const ORIGIN = 'https://giving.example.test';

    protected const PATH = '/ramadan';

    protected function useSqliteAndStubStripe(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        config([
            'forms.payment_return_origins' => [self::ORIGIN],
            'forms.submit_per_hour' => 100,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
        ]);

        FakeStripePages::$created = [];
        FakeStripePages::$pages = [];

        $this->app->bind(FormResponseCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    $n = count(FakeStripePages::$created) + 1;
                    FakeStripePages::$created[] = ['params' => $params, 'account' => $connectedAccountId];
                    FakeStripePages::$pages["cs_ramadan_{$n}"] = 'open';

                    return ['id' => "cs_ramadan_{$n}", 'url' => "https://checkout.stripe.test/pay/cs_ramadan_{$n}", 'payment_intent' => null];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    $status = FakeStripePages::$pages[$sessionId] ?? 'expired';

                    return ['status' => $status, 'url' => $status === 'open' ? "https://checkout.stripe.test/pay/{$sessionId}" : null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    FakeStripePages::$pages[$sessionId] = 'expired';
                }
            };
        });
    }

    protected function makeOrg(string $account = 'acct_test_ramadan_org'): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => $account,
            'stripe_charges_enabled' => true,
            'timezone' => 'America/New_York',
        ]);
    }

    protected function makeSuperAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    protected function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /** $17 per person, card payment on: Zakat-ul-Fitr's shape. */
    protected function makeZakatForm(Masjid $masjid, array $people = ['min' => 1, 'max' => 20], array $settings = []): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'zakat-' . uniqid(),
            'name' => 'Zakat-ul-Fitr',
            'schema' => ['sections' => [['id' => 'giver', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'required' => true] + $people,
            ]]]],
            'settings' => array_replace([
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'fee' => ['amount' => 17, 'currency' => 'USD', 'perQuantityOf' => 'people'],
                'payment' => ['online' => true],
            ], $settings),
            'is_active' => true,
        ]);
    }

    /**
     * Four levels, three of which reserve a date from $dates; Individual Iftar is $18 per
     * person. Card payment on, and the office too when $office.
     *
     * @param  array<int,string>  $dates
     */
    protected function makeIftarForm(Masjid $masjid, array $dates, bool $office = false): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'iftar-' . uniqid(),
            'name' => 'Iftar Sponsorship',
            'schema' => ['sections' => [['id' => 'sponsor', 'title' => 'Sponsor', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'sponsorship', 'label' => 'Sponsorship', 'type' => 'radio', 'required' => true, 'options' => [
                    ['value' => 'individual', 'label' => 'Individual Iftar'],
                    ['value' => 'quarter', 'label' => 'Quarter Iftar'],
                    ['value' => 'half', 'label' => 'Half Iftar'],
                    ['value' => 'full', 'label' => 'Full Iftar'],
                ]],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'min' => 1, 'max' => 50],
                ['name' => 'iftar_date', 'label' => 'Date', 'type' => 'select', 'optionsSource' => 'reservable_dates'],
            ]]]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'fee' => [
                    'currency' => 'USD',
                    'perQuantityOf' => 'people',
                    'byChoice' => ['field' => 'sponsorship', 'prices' => [
                        ['value' => 'individual', 'amount' => 18, 'perQuantity' => true],
                        ['value' => 'quarter', 'amount' => 450, 'reservesDate' => true],
                        ['value' => 'half', 'amount' => 950, 'reservesDate' => true],
                        ['value' => 'full', 'amount' => 1900, 'reservesDate' => true],
                    ]],
                ],
                'reservation' => ['field' => 'iftar_date', 'dates' => $dates],
                'payment' => ['online' => true] + ($office ? ['officePayment' => true, 'officeInstructions' => 'Pay at the front desk.'] : []),
            ],
            'is_active' => true,
        ]);
    }

    /** The public submit as the renderer sends it, with a fresh replay key per call. */
    protected function submitTo(Form $form, array $answers, array $extra = [], ?int $masjidId = null): TestResponse
    {
        return $this->postJson("/api/v1/forms/{$form->id}/responses", array_merge([
            'data' => array_merge(['fullName' => 'Jane Giver', 'email' => 'jane@example.test'], $answers),
            'return_path' => self::PATH,
            'client_submission_key' => (string) Str::uuid(),
        ], $extra), ['masjid-id' => (string) ($masjidId ?? $form->masjid_id), 'Origin' => self::ORIGIN]);
    }

    /** "Return to payment" for a registration. */
    protected function reopenPayment(FormResponse $row): TestResponse
    {
        return $this->postJson(
            "/api/v1/form-responses/{$row->uuid}/checkout",
            ['return_path' => self::PATH],
            ['masjid-id' => (string) $row->masjid_id, 'Origin' => self::ORIGIN]
        );
    }

    /** The public page's form payload, read through the page API the renderer calls. */
    protected function publicFormPayload(Form $form): array
    {
        $page = Page::create([
            'masjid_id' => $form->masjid_id,
            'slug' => 'give-' . uniqid(),
            'title' => 'Give',
            'is_active' => true,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $form->masjid_id,
            'section_type' => 'form',
            'title' => 'Give',
            'content' => ['form_id' => $form->id],
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $this->withHeader('masjid-id', (string) $form->masjid_id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertOk()
            ->json('data.sections.0.content.form');
    }
}
