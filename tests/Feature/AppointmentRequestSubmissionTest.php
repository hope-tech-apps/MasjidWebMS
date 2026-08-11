<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public appointment-request endpoint (T-021) — an unauthenticated write,
 * and therefore the surface an attacker reaches first.
 *
 * `/api/v1/*` carries no middleware at all in this app, so everything
 * protecting this route lives in the controller and its named throttle.
 * Mirrors FormSubmissionTest, whose idiom this endpoint copies.
 */
class AppointmentRequestSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;

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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => 'Amal Yusuf',
            'phone' => '+15550001111',
            'email' => 'amal@example.com',
            'date_of_birth' => '1980-04-12',
            'reason' => 'Persistent cough for two weeks',
            'preferred_window' => 'Weekday mornings',
        ], $overrides);
    }

    private function submit(array $payload, ?int $masjidId = null)
    {
        $headers = [];

        if ($masjidId !== null) {
            $headers['masjid-id'] = (string) $masjidId;
        }

        return $this->postJson('/api/v1/appointment-requests', $payload, $headers);
    }

    // ------------------------------------------------------------- happy path

    #[Test]
    public function a_request_is_stored_for_the_masjid_the_header_names(): void
    {
        $response = $this->submit($this->payload(), $this->masjidA->id);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertNotNull($response->json('data.id'));

        $stored = AppointmentRequest::find($response->json('data.id'));
        $this->assertSame($this->masjidA->id, $stored->masjid_id);
        $this->assertSame('Amal Yusuf', $stored->applicant_name);
        // Encrypted columns round-trip through the cast.
        $this->assertSame('1980-04-12', $stored->date_of_birth);
        $this->assertSame('Persistent cough for two weeks', $stored->reason);
        $this->assertSame(AppointmentRequest::STATUS_NEW, $stored->status);
        $this->assertSame(AppointmentRequest::SOURCE_WEB, $stored->source);
    }

    #[Test]
    public function a_client_supplied_masjid_id_in_the_body_is_ignored(): void
    {
        // The header decides the tenant; the body must not be able to plant
        // the request in another organization's queue.
        $response = $this->submit(
            $this->payload(['masjid_id' => $this->masjidB->id]),
            $this->masjidA->id
        );

        $response->assertOk();
        $this->assertSame(
            $this->masjidA->id,
            AppointmentRequest::find($response->json('data.id'))->masjid_id
        );
    }

    #[Test]
    public function a_client_supplied_status_and_source_are_ignored(): void
    {
        $response = $this->submit(
            $this->payload(['status' => AppointmentRequest::STATUS_SCHEDULED, 'source' => 'staff']),
            $this->masjidA->id
        );

        $stored = AppointmentRequest::find($response->json('data.id'));
        $this->assertSame(AppointmentRequest::STATUS_NEW, $stored->status);
        $this->assertSame(AppointmentRequest::SOURCE_WEB, $stored->source);
    }

    // ---------------------------------------------------------------- tenancy

    #[Test]
    public function a_missing_masjid_header_is_a_400(): void
    {
        $this->submit($this->payload())->assertStatus(400);

        $this->assertDatabaseCount('appointment_requests', 0);
    }

    #[Test]
    public function an_unknown_masjid_is_a_404(): void
    {
        $this->submit($this->payload(), 999999)->assertStatus(404);

        $this->assertDatabaseCount('appointment_requests', 0);
    }

    // --------------------------------------------------------------- honeypot

    #[Test]
    public function the_honeypot_reports_success_but_stores_nothing(): void
    {
        // A bot filling every input trips `website`; the response gives it no
        // signal to adapt to.
        $this->submit($this->payload(['website' => 'https://spam.example']), $this->masjidA->id)
            ->assertOk()
            ->assertJsonPath('data.id', null);

        $this->assertDatabaseCount('appointment_requests', 0);
    }

    // ------------------------------------------------------------- validation

    #[Test]
    public function validation_failures_return_the_legacy_failed_envelope(): void
    {
        $response = $this->submit(
            $this->payload(['applicant_name' => '', 'date_of_birth' => 'April 1980']),
            $this->masjidA->id
        );

        // BaseFormRequest's contract: 422 + {status:'failed', data:<field bag>}.
        $response->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertArrayHasKey('applicant_name', $response->json('data'));
        $this->assertArrayHasKey('date_of_birth', $response->json('data'));
    }

    #[Test]
    public function a_future_date_of_birth_is_rejected(): void
    {
        $this->submit(
            $this->payload(['date_of_birth' => now()->addYear()->format('Y-m-d')]),
            $this->masjidA->id
        )->assertStatus(422);
    }

    #[Test]
    public function reason_and_phone_are_required(): void
    {
        $response = $this->submit(
            $this->payload(['reason' => null, 'phone' => null]),
            $this->masjidA->id
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('reason', $response->json('data'));
        $this->assertArrayHasKey('phone', $response->json('data'));
    }

    #[Test]
    public function email_is_optional(): void
    {
        // Not every patient has an email address; the clinic calls back.
        $this->submit($this->payload(['email' => null]), $this->masjidA->id)
            ->assertOk();
    }
}
