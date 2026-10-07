<?php

use App\Models\Masjid;
use App\Models\Offering;
use App\Services\Registrations\RegistrationException;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('names programs in registration refusals without changing exception constructors', function (string $method, array $args, string $words) {
    expect(RegistrationException::$method(...$args)->getMessage())->toBe($words);
})->with([
    ['planMismatch', [], 'This fee plan does not belong to this program.'],
    ['offeringClosed', [], 'This program is not currently accepting registrations.'],
    ['offeringFull', [], 'This program is full — free a seat before promoting from the waitlist.'],
    ['offeringHasLiveRegistrations', [1], 'This program still has 1 live registration(s) — turn off "Open for registration" instead of deleting it.'],
    ['offeringHasLiveRegistrations', [2], 'This program still has 2 live registration(s) — turn off "Open for registration" instead of deleting it.'],
]);

it('names programs in the public read and unavailable responses and preserves route and payload keys', function () {
    app(TenantContext::class)->forgetTenant();
    $masjid = Masjid::create([
        'name' => 'Sample organization', 'email' => 'office@example.invalid', 'phone' => '+15555550111',
        'country_id' => '1', 'city_id' => '1', 'address' => '1 Test Street',
        'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
    ]);
    $program = Offering::factory()->forMasjid($masjid)->create(['slug' => 'sample-program', 'kind' => 'program']);
    $headers = ['masjid-id' => (string) $masjid->id];

    $this->getJson('/api/v1/offerings/sample-program', $headers)->assertOk()
        ->assertJsonPath('message', 'Program loaded.')->assertJsonPath('data.slug', $program->slug)
        ->assertJsonPath('data.kind', 'program');
    $this->getJson('/api/v1/offerings/not-present', $headers)->assertNotFound()
        ->assertJsonPath('message', 'This program is not available.');
    $this->postJson('/api/v1/offerings/not-present/quote', ['fee_plan_id' => 1, 'entries' => 1], $headers)
        ->assertNotFound()->assertJsonPath('message', 'This program is not available.');
    $this->postJson('/api/v1/offerings/not-present/register', ['fee_plan_id' => 1, 'payer' => ['name' => 'Test registrant', 'email' => 'registrant@example.invalid'], 'data' => ['answer' => 'test']], $headers)
        ->assertNotFound()->assertJsonPath('message', 'This program is not available.');
});

it('names programs in the public waitlist outcome', function () {
    $controller = app(\App\Http\Controllers\Api\V1\OfferingRegistrationsController::class);
    $method = new ReflectionMethod($controller, 'outcomeMessage');
    $registration = new \App\Models\Registration(['status' => 'waitlisted']);
    expect($method->invoke($controller, $registration))->toBe('This program is full — you have been added to the waitlist.');
});

it('names programs in the existing request field messages', function (string $class, string $key, string $message) {
    expect((new $class)->messages()[$key])->toBe($message);
})->with([
    [\App\Http\Requests\Admin\Registrations\StoreRegistrationRequest::class, 'fee_plan_id.exists', 'That fee plan does not belong to this program.'],
    [\App\Http\Requests\Admin\Offerings\StoreOfferingRequest::class, 'slug.unique', 'Another program in this organization already uses that slug.'],
]);
